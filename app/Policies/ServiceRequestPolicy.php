<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Organisation contacts see every request of their own organisation (so
 * colleagues can follow each other's work); staff need service_requests.view.
 */
class ServiceRequestPolicy
{
    public function view(User $user, ServiceRequest $request): bool
    {
        if ($user->isOrgContact()) {
            return $this->belongsToOrganization($user, $request);
        }

        return $user->isStaff() && $user->can('service_requests.view');
    }

    /** Message, cancel, respond to quotations — the client side only. */
    public function act(User $user, ServiceRequest $request): bool
    {
        return $user->isOrgContact() && $this->belongsToOrganization($user, $request);
    }

    private function belongsToOrganization(User $user, ServiceRequest $request): bool
    {
        $orgId = $user->organizationMembership?->organization_id;

        // Deleted requests are gone from the client's point of view.
        return $orgId !== null && $orgId === $request->organization_id && ! $request->trashed();
    }
}
