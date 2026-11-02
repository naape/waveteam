<?php

namespace App\Actions\Auth;

use App\Actions\Accounts\RecordConsent;
use App\Enums\AccountStatus;
use App\Enums\OrganizationStatus;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates the organisation (pending WaveTeam approval) and its primary contact login.
 */
class RegisterOrganization
{
    public function __construct(private readonly RecordConsent $recordConsent) {}

    public function __invoke(array $data, ?Request $request = null): User
    {
        $user = DB::transaction(function () use ($data, $request) {
            $organization = new Organization([
                'name' => $data['organization_name'],
                'registration_number' => $data['registration_number'] ?? null,
                'organization_type' => $data['organization_type'],
                'address' => $data['address'],
                'billing_address' => ($data['billing_same'] ?? false) ? $data['address'] : ($data['billing_address'] ?? null),
                'email' => mb_strtolower($data['email']),
                'phone' => PhoneNumber::normalize($data['phone']),
            ]);
            $organization->status = OrganizationStatus::Pending;
            $organization->save();

            $user = new User([
                'name' => $data['contact_name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $user->user_type = UserType::OrgContact;
            $user->status = AccountStatus::Active;
            $user->save();

            OrganizationUser::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'designation' => $data['designation'],
                'phone' => PhoneNumber::normalize($data['phone']),
                'is_primary' => true,
            ]);

            ($this->recordConsent)($user, $request);
            Audit::log('organization.registered', $organization, meta: ['contact_user_id' => $user->id]);

            return $user;
        });

        event(new Registered($user));
        $user->notify(new TemplatedNotification(
            'organization.registered',
            ['organization_name' => $data['organization_name']],
            route('organization.dashboard'),
            __('Go to your dashboard'),
        ));

        return $user;
    }
}
