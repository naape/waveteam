<?php

namespace App\Actions\Admin;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\BlindIndex;
use App\Support\Nic;
use App\Support\PhoneNumber;
use App\Support\Profile\ProfileCompletion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff edits to an applicant's personal details and sign-in email.
 * (Passwords: App\Actions\Accounts\PortalPasswordActions.)
 * Every change needs a reason and is audit-logged (sensitive values redacted).
 */
class ApplicantAccountActions
{
    /** Fields staff may edit, as they are stored on the profile. */
    public const PROFILE_FIELDS = [
        'full_name', 'passport_number', 'date_of_birth', 'gender', 'district_id',
        'permanent_address', 'current_address', 'contact_number', 'whatsapp_number',
        'emergency_contact_name', 'emergency_contact_number',
    ];

    /**
     * @param  array<string, mixed>  $data  validated form data (may include email, nic_number)
     */
    public function updateDetails(ApplicantProfile $profile, array $data, User $staff, string $reason): void
    {
        $user = $profile->user;

        if (array_key_exists('nic_number', $data) && filled($data['nic_number'])) {
            $nic = BlindIndex::normalizeNic($data['nic_number']);
            if ($nic !== $profile->nic_number) {
                $taken = ApplicantProfile::where('nic_hash', BlindIndex::nic($nic))->whereKeyNot($profile->id)->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['nic_number' => __('Another applicant is already registered with this NIC.')]);
                }
                $profile->nic_number = $nic;
            }
        }

        $nicYear = Nic::birthYear($profile->nic_number);
        if ($nicYear && filled($data['date_of_birth'] ?? null) && (int) date('Y', strtotime((string) $data['date_of_birth'])) !== $nicYear) {
            throw ValidationException::withMessages(['date_of_birth' => __('Date of birth does not match the birth year in the NIC (:year).', ['year' => $nicYear])]);
        }

        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : $user->email;
        if ($email !== $user->email && User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('This email address is already used by another account.')]);
        }

        $tracked = [...self::PROFILE_FIELDS, 'nic_number'];
        $before = Arr::only($profile->getAttributes(), $tracked) + ['email' => $user->email];
        $emailChanged = false;

        DB::transaction(function () use ($profile, $user, $data, $email, &$emailChanged) {
            $profile->fill([
                ...Arr::only($data, self::PROFILE_FIELDS),
                'passport_number' => filled($data['passport_number'] ?? null) ? strtoupper($data['passport_number']) : null,
                'contact_number' => PhoneNumber::normalize($data['contact_number'] ?? null),
                'whatsapp_number' => PhoneNumber::normalize($data['whatsapp_number'] ?? null),
                'emergency_contact_number' => PhoneNumber::normalize($data['emergency_contact_number'] ?? null),
            ])->save();

            $user->name = $profile->full_name;
            if ($email !== $user->email) {
                // The new address must be confirmed by the applicant.
                $user->email = $email;
                $user->email_verified_at = null;
                $emailChanged = true;
            }
            $user->save();
        });

        ProfileCompletion::refresh($profile);

        $after = Arr::only($profile->fresh()->getAttributes(), $tracked) + ['email' => $user->email];
        $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));

        Audit::log('applicant.details_updated_by_staff', $profile,
            Arr::only($before, $changed), Arr::only($after, $changed),
            ['reason' => $reason, 'fields' => $changed]);

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }
    }
}
