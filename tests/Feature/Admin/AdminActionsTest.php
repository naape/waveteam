<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\ApplicantReview;
use App\Actions\Admin\OrganizationActions;
use App\Actions\Admin\QuotationActions;
use App\Actions\Admin\RecruitmentActions;
use App\Actions\Admin\StaffRequestActions;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VerificationStatus;
use App\Models\ApplicantDocument;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Notifications\TemplatedNotification;
use App\Support\Documents\DocumentVault;
use Database\Seeders\DemoVacancySeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class AdminActionsTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class, DemoVacancySeeder::class]);
    }

    // ---- Applicants & documents ---------------------------------------------

    public function test_profile_verification_and_document_review(): void
    {
        Notification::fake();
        $hr = $this->makeStaff('HR Manager');
        $user = $this->makeApplicant();
        $this->actingAs($user)->post(route('applicant.documents.store'), ['document_type' => 'nic', 'file' => $this->fakePdf()]);
        $doc = ApplicantDocument::firstOrFail();
        $review = app(ApplicantReview::class);

        $review->setVerification($user->applicantProfile, VerificationStatus::Verified, $hr);
        $this->assertSame(VerificationStatus::Verified, $user->applicantProfile->fresh()->verification_status);
        Notification::assertSentTo($user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'profile.verified');

        try {
            $review->reviewDocument($doc, false, $hr);
            $this->fail('Rejection without reason should fail');
        } catch (ValidationException) {
        }

        $review->reviewDocument($doc, false, $hr, 'Back side missing');
        $this->assertSame(DocumentStatus::Rejected, $doc->fresh()->status);
        Notification::assertSentTo($user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'document.rejected' && $n->data['reason'] === 'Back side missing');

        // Rejection reason is shown to the applicant.
        $this->actingAs($user)->get(route('applicant.documents.index'))->assertSee('Back side missing');
    }

    public function test_police_clearance_is_not_reviewed_in_document_queue(): void
    {
        $user = $this->makeApplicant();
        $this->actingAs($user)->post(route('applicant.police-clearance.store'), [
            'issue_date' => now()->subWeek()->toDateString(), 'file' => $this->fakePdf(), 'confirm_genuine' => '1',
        ]);

        $this->expectException(ValidationException::class);
        app(ApplicantReview::class)->reviewDocument(ApplicantDocument::firstOrFail(), true, $this->makeStaff('Super Admin'));
    }

    // ---- Recruitment -----------------------------------------------------------

    private function application(): JobApplication
    {
        $user = $this->makeApplicant();
        $this->actingAs($user)->post(route('applicant.documents.store'), ['document_type' => 'cv', 'file' => $this->fakePdf()]);
        $this->actingAs($user)->post(route('applicant.vacancies.apply', JobVacancy::first()), ['confirm_accurate' => '1']);

        return JobApplication::firstOrFail();
    }

    public function test_application_status_change_and_interview_scheduling(): void
    {
        $app = $this->application();
        Notification::fake();
        $hr = $this->makeStaff('HR Manager');
        $actions = app(RecruitmentActions::class);

        $actions->changeStatus($app, ApplicationStatus::Shortlisted, $hr, 'Strong rigging background');
        Notification::assertSentTo($app->applicantProfile->user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'application.status_changed' && $n->data['status'] === 'Shortlisted');

        $first = $actions->scheduleInterview($app, now()->addDays(3)->setTime(10, 0), InterviewMode::InPerson, 'Gampaha office', $hr);
        $second = $actions->scheduleInterview($app, now()->addDays(5)->setTime(14, 0), InterviewMode::Video, 'https://meet.example/abc', $hr);

        $this->assertSame(ApplicationStatus::InterviewScheduled, $app->fresh()->status);
        $this->assertSame(InterviewStatus::Rescheduled, $first->fresh()->status);
        $this->assertSame(InterviewStatus::Scheduled, $second->fresh()->status);
        Notification::assertSentTo($app->applicantProfile->user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'interview.invitation');
        $this->assertSame(['submitted', 'shortlisted', 'interview_scheduled'], $app->statusHistory()->orderBy('id')->pluck('to_status')->all());

        // The applicant sees the interview.
        $this->actingAs($app->applicantProfile->user)->get(route('applicant.applications.index'))->assertSee('https://meet.example/abc');

        $this->expectException(ValidationException::class);
        $actions->scheduleInterview($app, now()->subHour(), InterviewMode::Phone, null, $hr);
    }

    // ---- Organisations ---------------------------------------------------------

    public function test_organization_verification_and_suspension(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $contact = $this->makeOrgContact(OrganizationStatus::Pending);
        $org = $contact->organizationMembership->organization;
        $actions = app(OrganizationActions::class);

        $actions->setStatus($org, OrganizationStatus::Active, $ops);
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
        $this->assertNotNull($org->fresh()->verified_at);

        try {
            $actions->setStatus($org, OrganizationStatus::Suspended, $ops);
            $this->fail('Suspension without reason should fail');
        } catch (ValidationException) {
        }

        $actions->setStatus($org, OrganizationStatus::Suspended, $ops, 'Unpaid invoices');
        Notification::assertSentTo($contact, TemplatedNotification::class, fn ($n) => $n->templateKey === 'organization.status_changed' && $n->data['status'] === 'Suspended');
    }

    // ---- Service requests ------------------------------------------------------

    public function test_staff_request_workflow(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $finance = $this->makeStaff('Finance');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);
        $actions = app(StaffRequestActions::class);

        // Invalid jump is refused.
        try {
            $actions->changeStatus($request, ServiceRequestStatus::Completed, $ops);
            $this->fail('Invalid transition should fail');
        } catch (ValidationException) {
        }

        $actions->changeStatus($request, ServiceRequestStatus::UnderReview, $ops);
        Notification::assertSentTo($contact, TemplatedNotification::class, fn ($n) => $n->templateKey === 'service_request.status_changed');

        $actions->postMessage($request, $ops, 'We will visit the sites on Monday.', false);
        $actions->postMessage($request, $ops, 'INTERNAL: client pays late', true);
        Notification::assertSentToTimes($contact, TemplatedNotification::class, 2); // status + reply only

        $this->actingAs($contact)->get(route('organization.requests.show', $request))
            ->assertSee('We will visit the sites on Monday.')->assertDontSee('INTERNAL: client pays late');

        $hidden = $actions->addAttachment($request, $ops, $this->fakePdf('costing.pdf'), 'Internal costing', false);
        $this->actingAs($contact)->get(route('organization.files.show', $hidden->file))->assertNotFound();

        $actions->assignManager($request, $ops, $ops);
        $this->assertSame($ops->id, $request->fresh()->assigned_manager_id);

        $this->expectException(ValidationException::class);
        $actions->assignManager($request, $finance, $ops); // Finance can't manage requests
    }

    public function test_quotation_draft_send_and_client_acceptance(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $finance = $this->makeStaff('Finance');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::UnderReview);
        $quotes = app(QuotationActions::class);

        $draft = $quotes->saveDraft($request, [
            ['description' => 'Rigging crew per site', 'quantity' => 12, 'unit' => 'site', 'unit_price' => 185000],
            ['description' => 'Transport', 'quantity' => 1, 'unit_price' => 240000.555],
        ], 18, now()->addDays(14)->toDateString(), 'Terms', $finance);

        $this->assertSame(QuotationStatus::Draft, $draft->status);
        $this->assertMatchesRegularExpression('/^QT-\d{4}-0001$/', $draft->reference);
        $this->assertEquals(2460000.56, (float) $draft->subtotal);
        $this->assertEquals(442800.10, (float) $draft->tax_amount);
        $this->assertEquals(2902800.66, (float) $draft->total);
        $this->assertSame(ServiceRequestStatus::QuotationPreparation, $request->fresh()->status);

        // The client can't see a draft.
        $this->actingAs($contact)->get(route('organization.requests.show', $request))->assertDontSee($draft->reference);

        // Only someone with quotations.approve can send.
        try {
            $quotes->send($draft, $ops);
            $this->fail('Ops cannot approve quotations');
        } catch (ValidationException) {
        }

        $quotes->send($draft, $finance);
        $draft->refresh();
        $this->assertSame(QuotationStatus::Sent, $draft->status);
        $this->assertSame($finance->id, $draft->approved_by);
        $this->assertSame(ServiceRequestStatus::QuotationSent, $request->fresh()->status);
        $this->assertStringStartsWith('%PDF', app(DocumentVault::class)->contents($draft->pdf));
        Notification::assertSentTo($contact, TemplatedNotification::class, fn ($n) => $n->templateKey === 'quotation.sent');

        // Client downloads the PDF and accepts (Phase 5 flow).
        $this->actingAs($contact)->get(route('organization.files.show', $draft->pdf))->assertOk();
        $this->actingAs($contact)->post(route('organization.quotations.respond', $draft), ['decision' => 'accept', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(ServiceRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_revised_quotation_supersedes_previous(): void
    {
        $finance = $this->makeStaff('Finance');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::UnderReview);
        $quotes = app(QuotationActions::class);
        $item = [['description' => 'Work', 'quantity' => 1, 'unit_price' => 1000]];

        $v1 = $quotes->send($quotes->saveDraft($request, $item, 0, now()->addWeek()->toDateString(), null, $finance), $finance);
        $v2 = $quotes->send($quotes->saveDraft($request->fresh(), $item, 0, now()->addWeek()->toDateString(), null, $finance), $finance);

        $this->assertSame(QuotationStatus::Superseded, $v1->fresh()->status);
        $this->assertSame(QuotationStatus::Sent, $v2->fresh()->status);
        $this->assertSame(2, $v2->version);
        $this->assertSame('QT-'.now()->year.'-0002', $v2->reference);
    }
}
