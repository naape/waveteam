<?php

namespace Tests\Feature\Organization;

use App\Enums\OrganizationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Notifications\TemplatedNotification;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ServiceRequestTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_category_id' => ServiceCategory::where('slug', 'tower-construction-and-maintenance')->value('id'),
            'title' => 'Antenna swap at 12 sites',
            'description' => 'Swap legacy antennas for multi-band antennas at 12 tower sites in Gampaha.',
            'required_headcount' => 6,
            'required_experience_years' => 3,
            'required_qualifications' => 'Certified climbers',
            'skills' => [Skill::where('name', 'Rigging')->value('id')],
            'district_id' => District::where('name_en', 'Gampaha')->value('id'),
            'location_text' => 'Gampaha (12 sites)',
            'start_date' => now()->addWeeks(2)->toDateString(),
            'expected_duration_value' => 6,
            'expected_duration_unit' => 'weeks',
            'working_hours' => '07:00–18:00',
            'requires_police_clearance' => '1',
            'budget_amount' => '3500000',
        ], $overrides);
    }

    private function docx(): UploadedFile
    {
        // Minimal but structurally real OOXML package so finfo recognises it.
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships/>'); // present in every real .docx
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        return new UploadedFile($path, 'BOQ.docx', null, null, true);
    }

    public function test_pages_render(): void
    {
        $contact = $this->makeOrgContact();
        $this->actingAs($contact);

        $this->get(route('organization.dashboard'))->assertOk();
        $this->get(route('organization.requests.index'))->assertOk()->assertSee('No service requests here yet');
        $this->get(route('organization.requests.create'))->assertOk()->assertSee('Rigging')
            ->assertSee('name="requires_police_clearance" value="1" class="text-navy-600 focus:ring-navy-500" checked', false); // "Yes" pre-selected
        $this->get(route('organization.profile'))->assertOk();
    }

    public function test_organization_submits_request_with_attachments(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $finance = $this->makeStaff('Finance');
        $contact = $this->makeOrgContact(OrganizationStatus::Pending); // pending may submit

        $this->actingAs($contact)->post(route('organization.requests.store'), $this->payload([
            'attachments' => [$this->fakePdf('site-list.pdf'), $this->docx()],
        ]))->assertSessionHasNoErrors();

        $request = ServiceRequest::with(['attachments.file', 'skills', 'statusHistory'])->firstOrFail();
        $this->assertMatchesRegularExpression('/^SR-\d{4}-00001$/', $request->reference);
        $this->assertSame(ServiceRequestStatus::Submitted, $request->status);
        $this->assertSame(6, $request->required_headcount);
        $this->assertTrue($request->requires_police_clearance);
        $this->assertCount(1, $request->skills);
        $this->assertCount(2, $request->attachments);
        $this->assertSame(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $request->attachments->pluck('file.mime_type')->all());
        $this->assertCount(1, $request->statusHistory);

        Notification::assertSentTo($contact, TemplatedNotification::class, fn ($n) => $n->templateKey === 'service_request.submitted');
        Notification::assertSentTo($ops, TemplatedNotification::class, fn ($n) => $n->templateKey === 'service_request.submitted.admin');
        Notification::assertNotSentTo($finance, TemplatedNotification::class);
        $this->assertTrue(AuditLog::where('action', 'service_request.submitted')->exists());

        $this->get(route('organization.requests.show', $request))->assertOk()->assertSee($request->reference)->assertSee('site-list.pdf');
    }

    public function test_validation_and_unsafe_attachments(): void
    {
        $contact = $this->makeOrgContact();
        $this->actingAs($contact);

        $this->post(route('organization.requests.store'), $this->payload(['title' => '', 'required_headcount' => 0, 'start_date' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors(['title', 'required_headcount', 'start_date']);

        $this->post(route('organization.requests.store'), $this->payload([
            'attachments' => [UploadedFile::fake()->createWithContent('macro.docx', 'MZ not a docx')],
        ]))->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_suspended_organization_cannot_submit(): void
    {
        $contact = $this->makeOrgContact(OrganizationStatus::Suspended);

        $this->actingAs($contact)->post(route('organization.requests.store'), $this->payload())->assertForbidden();
        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_request_visibility_is_limited_to_the_organization(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);

        // Colleague in the same organisation can see it.
        $colleague = $this->makeOrgContact(org: $contact->organizationMembership->organization);
        $this->actingAs($colleague)->get(route('organization.requests.show', $request))->assertOk();

        // Another organisation cannot.
        $this->actingAs($this->makeOrgContact())->get(route('organization.requests.show', $request))->assertForbidden();
        $this->actingAs($this->makeOrgContact())->post(route('organization.requests.messages.store', $request), ['body' => 'hi'])->assertForbidden();

        // Applicants are sent back to their own portal.
        $this->actingAs($this->makeApplicant())->get(route('organization.requests.show', $request))->assertRedirect(route('applicant.dashboard'));
    }

    public function test_client_can_message_wavteam_and_staff_are_notified(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);

        $this->actingAs($contact)->post(route('organization.requests.messages.store', $request), [
            'body' => 'Can you start a week earlier?', 'files' => [$this->fakePdf('drawing.pdf')],
        ])->assertSessionHasNoErrors();

        $message = $request->messages()->with('files')->firstOrFail();
        $this->assertFalse($message->is_internal);
        $this->assertCount(1, $message->files);
        Notification::assertSentTo($ops, TemplatedNotification::class, fn ($n) => $n->templateKey === 'service_request.message.admin');
    }

    public function test_internal_staff_notes_are_never_shown_to_client(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);
        $request->messages()->create(['sender_id' => $this->makeStaff('Operations Manager')->id, 'body' => 'INTERNAL: margin is thin', 'is_internal' => true]);
        $request->messages()->create(['sender_id' => $this->makeStaff('Operations Manager')->id, 'body' => 'Hello from WaveTeam', 'is_internal' => false]);

        $this->actingAs($contact)->get(route('organization.requests.show', $request))
            ->assertSee('Hello from WaveTeam')->assertDontSee('INTERNAL: margin is thin');
    }

    public function test_cancellation_rules(): void
    {
        Notification::fake();
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::UnderReview);

        $this->actingAs($contact)->post(route('organization.requests.cancel', $request), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($contact)->post(route('organization.requests.cancel', $request), ['reason' => 'Project postponed by client'])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(ServiceRequestStatus::Cancelled, $request->status);
        $this->assertSame('Project postponed by client', $request->cancel_reason);
        $this->assertSame(1, $request->statusHistory()->where("to_status", "cancelled")->count());

        $inProgress = $this->makeServiceRequest($contact, ServiceRequestStatus::InProgress);
        $this->actingAs($contact)->post(route('organization.requests.cancel', $inProgress), ['reason' => 'Changed our mind'])->assertSessionHasErrors('reason');
        $this->assertSame(ServiceRequestStatus::InProgress, $inProgress->fresh()->status);
    }

    public function test_team_is_only_visible_when_enabled(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::InProgress);
        $applicant = $this->makeApplicant(['full_name' => 'Ruwan Jayasinghe']);
        $request->assignments()->create(['applicant_profile_id' => $applicant->applicantProfile->id, 'role_title' => 'Rigger', 'start_date' => now(), 'status' => 'active']);

        $this->actingAs($contact)->get(route('organization.requests.show', $request))->assertDontSee('Ruwan Jayasinghe');

        $request->forceFill(['show_team_to_client' => true])->save();
        $this->actingAs($contact)->get(route('organization.requests.show', $request))->assertSee('Ruwan Jayasinghe')->assertSee('Rigger');
    }

    public function test_organization_profile_update_and_identity_lock(): void
    {
        $pending = $this->makeOrgContact(OrganizationStatus::Pending);
        $data = ['organization_name' => 'New Name Ltd', 'organization_type' => 'government', 'address' => '5 New Rd', 'phone' => '0112223344',
            'contact_name' => 'Kumari S', 'designation' => 'Director'];

        $this->actingAs($pending)->put(route('organization.profile'), $data)->assertSessionHasNoErrors();
        $org = $pending->organizationMembership->organization->fresh();
        $this->assertSame('New Name Ltd', $org->name);
        $this->assertSame('+94112223344', $org->phone);
        $this->assertSame('5 New Rd', $org->billing_address);

        // Once verified (active), name and type are locked.
        $active = $this->makeOrgContact(OrganizationStatus::Active);
        $original = $active->organizationMembership->organization->name;
        $this->actingAs($active)->put(route('organization.profile'), $data)->assertSessionHasNoErrors();
        $this->assertSame($original, $active->organizationMembership->organization->fresh()->name);
    }

    public function test_dashboard_counts(): void
    {
        $contact = $this->makeOrgContact();
        $this->makeServiceRequest($contact);
        $quoted = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);
        $this->makeQuotation($quoted);
        $this->makeServiceRequest($contact, ServiceRequestStatus::Completed);
        $this->makeServiceRequest($this->makeOrgContact()); // other org, not counted

        $this->actingAs($contact)->get(route('organization.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', ['open' => 2, 'quotations' => 1, 'active' => 0, 'completed' => 1]);
    }
}
