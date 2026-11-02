<?php

namespace Tests\Feature\Organization;

use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\AuditLog;
use App\Models\OrganizationDocument;
use App\Models\ServiceRequestAttachment;
use App\Notifications\TemplatedNotification;
use App\Support\Documents\DocumentVault;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class QuotationAndFilesTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
    }

    public function test_client_accepts_quotation(): void
    {
        Notification::fake();
        $finance = $this->makeStaff('Finance');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);
        $quote = $this->makeQuotation($request);

        $this->actingAs($contact)->get(route('organization.requests.show', $request))->assertSee('Accept quotation');

        $this->actingAs($contact)->post(route('organization.quotations.respond', $quote), ['decision' => 'accept'])
            ->assertSessionHasErrors('confirm');

        $this->actingAs($contact)->post(route('organization.quotations.respond', $quote), ['decision' => 'accept', 'confirm' => '1', 'comment' => 'Go ahead'])
            ->assertSessionHasNoErrors();

        $quote->refresh();
        $this->assertSame(QuotationStatus::Accepted, $quote->status);
        $this->assertSame($contact->id, $quote->responded_by);
        $this->assertSame(ServiceRequestStatus::Approved, $request->fresh()->status);
        Notification::assertSentTo($finance, TemplatedNotification::class, fn ($n) => $n->templateKey === 'quotation.responded.admin');
        $this->assertTrue(AuditLog::where('action', 'quotation.accepted')->exists());

        // Can't respond twice.
        $this->actingAs($contact)->post(route('organization.quotations.respond', $quote), ['decision' => 'reject', 'comment' => 'x'])
            ->assertSessionHasErrors('quotation');
    }

    public function test_client_rejects_quotation_with_reason(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);
        $quote = $this->makeQuotation($request);

        $this->actingAs($contact)->post(route('organization.quotations.respond', $quote), ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');

        $this->actingAs($contact)->post(route('organization.quotations.respond', $quote), ['decision' => 'reject', 'comment' => 'Over budget'])
            ->assertSessionHasNoErrors();

        $this->assertSame(QuotationStatus::Rejected, $quote->fresh()->status);
        $this->assertSame('Over budget', $quote->fresh()->client_comment);
        $this->assertSame(ServiceRequestStatus::UnderReview, $request->fresh()->status);
    }

    public function test_expired_draft_and_foreign_quotations_cannot_be_answered(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);

        $expired = $this->makeQuotation($request, attrs: ['valid_until' => now()->subDay()]);
        $this->actingAs($contact)->post(route('organization.quotations.respond', $expired), ['decision' => 'accept', 'confirm' => '1'])
            ->assertSessionHasErrors('quotation');

        $draft = $this->makeQuotation($request, QuotationStatus::Draft);
        $this->actingAs($contact)->post(route('organization.quotations.respond', $draft), ['decision' => 'accept', 'confirm' => '1'])->assertNotFound();
        $this->actingAs($contact)->get(route('organization.requests.show', $request))->assertDontSee($draft->reference);

        $other = $this->makeOrgContact();
        $sent = $this->makeQuotation($request);
        $this->actingAs($other)->post(route('organization.quotations.respond', $sent), ['decision' => 'accept', 'confirm' => '1'])->assertForbidden();
        $this->assertSame(QuotationStatus::Sent, $sent->fresh()->status);
    }

    public function test_file_access_matrix(): void
    {
        $vault = app(DocumentVault::class);
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);

        $visible = $vault->store($this->fakePdf('visible.pdf'), $contact);
        ServiceRequestAttachment::create(['service_request_id' => $request->id, 'stored_file_id' => $visible->id, 'visible_to_client' => true]);

        $hidden = $vault->store($this->fakePdf('staff-only.pdf'), null);
        ServiceRequestAttachment::create(['service_request_id' => $request->id, 'stored_file_id' => $hidden->id, 'visible_to_client' => false]);

        $internal = $vault->store($this->fakePdf('internal-note.pdf'), null);
        $request->messages()->create(['body' => 'note', 'is_internal' => true])->files()->attach($internal->id);

        $draftPdf = $vault->store($this->fakePdf('draft-quote.pdf'), null);
        $this->makeQuotation($request, QuotationStatus::Draft, ['pdf_file_id' => $draftPdf->id]);

        $sentPdf = $vault->store($this->fakePdf('quote.pdf'), null);
        $this->makeQuotation($request, QuotationStatus::Sent, ['pdf_file_id' => $sentPdf->id]);

        $orgDoc = $vault->store($this->fakePdf('br-cert.pdf'), $contact);
        OrganizationDocument::create(['organization_id' => $contact->organizationMembership->organization_id, 'title' => 'BR', 'stored_file_id' => $orgDoc->id]);

        $unlinked = $vault->store($this->fakePdf('orphan.pdf'), null);

        $this->actingAs($contact);
        $this->get(route('organization.files.show', $visible))->assertOk();
        $this->get(route('organization.files.show', $sentPdf))->assertOk();
        $this->get(route('organization.files.show', [$orgDoc, 'download' => 1]))->assertOk()->assertDownload('br-cert.pdf');
        foreach ([$hidden, $internal, $draftPdf, $unlinked] as $file) {
            $this->get(route('organization.files.show', $file))->assertNotFound();
        }

        // Another organisation sees nothing.
        $this->actingAs($this->makeOrgContact());
        foreach ([$visible, $sentPdf, $orgDoc] as $file) {
            $this->get(route('organization.files.show', $file))->assertNotFound();
        }

        $this->assertTrue(AuditLog::where('action', 'file.viewed')->exists());
    }

    public function test_office_documents_always_download_never_inline(): void
    {
        $contact = $this->makeOrgContact();
        $this->actingAs($contact)->post(route('organization.profile.documents.store'), [
            'title' => 'Authorisation letter', 'file' => $this->fakePdf('letter.pdf'),
        ])->assertSessionHasNoErrors();

        $doc = OrganizationDocument::with('file')->firstOrFail();
        $res = $this->get(route('organization.files.show', $doc->file));
        $res->assertOk();
        $this->assertStringStartsWith('inline', $res->headers->get('Content-Disposition'));

        // Simulate an Office file record: must be forced to download.
        $doc->file->forceFill(['mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->save();
        $this->assertStringStartsWith('attachment', $this->get(route('organization.files.show', $doc->file))->headers->get('Content-Disposition'));
    }
}
