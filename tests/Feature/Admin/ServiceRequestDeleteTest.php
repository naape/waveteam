<?php

namespace Tests\Feature\Admin;

use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Models\AuditLog;
use App\Models\ServiceRequest;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class ServiceRequestDeleteTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_deletes_request_with_reason_and_it_is_kept_for_restore(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::UnderReview);
        $quote = $this->makeQuotation($request, QuotationStatus::Sent);
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('delete', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('delete', data: ['reason' => 'Duplicate of an earlier request'])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertSoftDeleted($request);
        $this->assertDatabaseHas('quotations', ['id' => $quote->id]); // nothing erased
        $log = AuditLog::where('action', 'service_request.deleted')->firstOrFail();
        $this->assertSame('Duplicate of an earlier request', $log->meta['reason']);

        // Hidden from the normal list, visible under "Deleted requests".
        Livewire::test(ListServiceRequests::class)->assertCanNotSeeTableRecords([$request]);
        Livewire::test(ListServiceRequests::class)->filterTable('trashed', false)->assertCanSeeTableRecords([$request]);

        // Quotations list still works for a deleted request.
        Livewire::test(ListQuotations::class)->assertOk();

        // Restore.
        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('restore')
            ->assertHasNoActionErrors();

        $this->assertNotSoftDeleted($request);
        $this->assertSame(ServiceRequestStatus::UnderReview, $request->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'service_request.restored')->exists());
    }

    public function test_client_no_longer_sees_a_deleted_request(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact);
        $quote = $this->makeQuotation($request, QuotationStatus::Sent);
        $request->delete();

        $this->actingAs($contact)
            ->get(route('organization.requests.show', $request->reference))->assertNotFound();
        $this->actingAs($contact)
            ->get(route('organization.requests.index'))->assertOk()->assertDontSee($request->reference);
        $this->actingAs($contact)
            ->post(route('organization.quotations.respond', $quote), ['decision' => 'accept', 'confirm' => '1'])
            ->assertForbidden();
        $this->assertSame(QuotationStatus::Sent, $quote->fresh()->status);
    }

    public function test_request_with_people_on_it_cannot_be_deleted(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::TeamAssigned);
        $worker = $this->makeApplicant();
        $request->assignments()->create(['applicant_profile_id' => $worker->applicantProfile->id, 'role_title' => 'Rigger', 'start_date' => now(), 'status' => 'confirmed']);
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('delete', data: ['reason' => 'Client withdrew'])
            ->assertNotified('This request cannot be deleted yet');

        $this->assertNotSoftDeleted($request);
    }

    public function test_only_staff_with_delete_permission_get_the_action(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact());
        $request->delete();
        $live = $this->makeServiceRequest($this->makeOrgContact());
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewServiceRequest::class, ['record' => $live->getRouteKey()])
            ->assertActionHidden('delete');

        // Without the permission, deleted requests are simply not found.
        $this->get(\App\Filament\Resources\ServiceRequests\ServiceRequestResource::getUrl('view', ['record' => $request]))->assertNotFound();
        $this->assertNull(ServiceRequest::find($request->id));
    }

    public function test_quotation_can_be_deleted_and_restored_but_not_when_accepted(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);
        $sent = $this->makeQuotation($request, QuotationStatus::Sent);
        $accepted = $this->makeQuotation($request, QuotationStatus::Accepted);
        $this->actingAs($this->makeStaff('Super Admin'));

        // From the request page.
        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('deleteQuotation', data: ['quotation' => $sent->id, 'reason' => 'Wrong price list'])
            ->assertHasNoActionErrors();
        $this->assertSoftDeleted($sent);
        $this->assertSame('Wrong price list', AuditLog::where('action', 'quotation.deleted')->firstOrFail()->meta['reason']);

        // The client no longer sees it.
        $this->actingAs($contact)->get(route('organization.requests.show', $request->reference))
            ->assertOk()->assertDontSee($sent->reference);
        $this->actingAs($contact)->post(route('organization.quotations.respond', $sent), ['decision' => 'accept', 'confirm' => '1'])
            ->assertNotFound();

        // From the Quotations list: accepted ones have no delete; deleted ones can be restored.
        $this->actingAs(\App\Models\User::where('user_type', 'staff')->first());
        Livewire::test(ListQuotations::class)
            ->assertTableActionHidden('delete', $accepted)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $sent);
        $this->assertNotSoftDeleted($sent);
        $this->assertSame(QuotationStatus::Sent, $sent->fresh()->status);
    }

    public function test_quotation_delete_needs_permission(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::QuotationSent);
        $sent = $this->makeQuotation($request, QuotationStatus::Sent);
        $this->actingAs($this->makeStaff('Finance'));

        Livewire::test(ListQuotations::class)->assertTableActionHidden('delete', $sent);
        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])->assertActionHidden('deleteQuotation');
    }
}
