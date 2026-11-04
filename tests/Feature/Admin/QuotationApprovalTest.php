<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\StaffRequestActions;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class QuotationApprovalTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staff_cannot_approve_a_request_for_the_client(): void
    {
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::QuotationSent);
        $this->makeQuotation($request, QuotationStatus::Sent);
        $staff = $this->makeStaff('Super Admin');
        $this->actingAs($staff);

        $this->assertNotContains(ServiceRequestStatus::Approved, StaffRequestActions::staffNextStatuses($request));

        Livewire::test(ViewServiceRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('status', data: ['status' => ServiceRequestStatus::Approved->value])
            ->assertHasActionErrors(['status']);

        try {
            app(StaffRequestActions::class)->changeStatus($request, ServiceRequestStatus::Approved, $staff);
            $this->fail('Staff approved a request.');
        } catch (ValidationException) {
        }

        $this->assertSame(ServiceRequestStatus::QuotationSent, $request->fresh()->status);
    }

    public function test_client_accepting_the_quotation_approves_the_request(): void
    {
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::QuotationSent);
        $quote = $this->makeQuotation($request, QuotationStatus::Sent);

        $this->actingAs($contact)
            ->get(route('organization.requests.show', $request->reference))
            ->assertOk()->assertSee(__('Accept quotation'));

        $this->actingAs($contact)
            ->post(route('organization.quotations.respond', $quote), ['decision' => 'accept', 'confirm' => '1'])
            ->assertRedirect();

        $this->assertSame(QuotationStatus::Accepted, $quote->fresh()->status);
        $this->assertSame($contact->id, $quote->fresh()->responded_by);
        $this->assertSame(ServiceRequestStatus::Approved, $request->fresh()->status);
    }
}
