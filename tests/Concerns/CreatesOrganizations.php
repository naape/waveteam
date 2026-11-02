<?php

namespace Tests\Concerns;

use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\District;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Quotation;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;

trait CreatesOrganizations
{
    protected function makeOrgContact(OrganizationStatus $status = OrganizationStatus::Active, ?Organization $org = null): User
    {
        $org ??= Organization::forceCreate([
            'name' => 'Acme Telecom '.uniqid(), 'organization_type' => 'private', 'address' => '1 Main St, Colombo',
            'email' => uniqid().'@acme.lk', 'phone' => '+94112345678', 'status' => $status,
        ]);
        $user = User::factory()->orgContact()->create();
        OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $user->id, 'designation' => 'Manager', 'is_primary' => true]);

        return $user->fresh();
    }

    protected function makeServiceRequest(User $contact, ServiceRequestStatus $status = ServiceRequestStatus::Submitted, array $attrs = []): ServiceRequest
    {
        return ServiceRequest::forceCreate(array_merge([
            'organization_id' => $contact->organizationMembership->organization_id,
            'submitted_by' => $contact->id,
            'service_category_id' => ServiceCategory::first()->id,
            'title' => 'Antenna swap',
            'description' => 'Swap antennas at 12 sites in the Western Province.',
            'required_headcount' => 4,
            'district_id' => District::first()->id,
            'location_text' => 'Gampaha',
            'status' => $status,
            'reference' => ServiceRequest::nextReference(),
        ], $attrs));
    }

    protected function makeQuotation(ServiceRequest $request, QuotationStatus $status = QuotationStatus::Sent, array $attrs = []): Quotation
    {
        return Quotation::forceCreate(array_merge([
            'service_request_id' => $request->id,
            'reference' => 'QT-'.uniqid(),
            'status' => $status,
            'subtotal' => 100000, 'tax_amount' => 0, 'total' => 100000,
            'valid_until' => now()->addWeeks(2),
            'sent_at' => now(),
        ], $attrs));
    }
}
