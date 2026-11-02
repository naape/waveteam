<?php

namespace App\Actions\Admin;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class OrganizationActions
{
    public function setStatus(Organization $organization, OrganizationStatus $status, User $by, ?string $note = null): void
    {
        if ($status === OrganizationStatus::Suspended && blank($note)) {
            throw ValidationException::withMessages(['note' => __('A reason is required to suspend an organisation.')]);
        }

        $old = $organization->status;
        $organization->forceFill([
            'status' => $status,
            'verified_by' => $status === OrganizationStatus::Active ? ($organization->verified_by ?? $by->id) : $organization->verified_by,
            'verified_at' => $status === OrganizationStatus::Active ? ($organization->verified_at ?? now()) : $organization->verified_at,
        ])->save();

        Audit::log('organization.status_changed', $organization, ['status' => $old?->value], ['status' => $status->value], array_filter(['note' => $note]));

        Notification::send($organization->users, new TemplatedNotification('organization.status_changed', [
            'organization_name' => $organization->name,
            'status' => $status->getLabel(),
            'note' => $note ?? '',
        ], route('organization.dashboard'), __('Go to dashboard')));
    }
}
