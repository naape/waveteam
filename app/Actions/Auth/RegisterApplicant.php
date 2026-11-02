<?php

namespace App\Actions\Auth;

use App\Actions\Accounts\RecordConsent;
use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegisterApplicant
{
    public function __construct(private readonly RecordConsent $recordConsent) {}

    /**
     * @param  array{full_name:string,email:string,nic_number:string,contact_number:string,password:string}  $data
     */
    public function __invoke(array $data, ?Request $request = null): User
    {
        $user = DB::transaction(function () use ($data, $request) {
            $user = new User([
                'name' => $data['full_name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $user->user_type = UserType::Applicant;
            $user->status = AccountStatus::Active;
            $user->save();

            $profile = new ApplicantProfile([
                'full_name' => $data['full_name'],
                'nic_number' => $data['nic_number'],
                'contact_number' => PhoneNumber::normalize($data['contact_number']),
            ]);
            $profile->user()->associate($user);
            $profile->save();

            ($this->recordConsent)($user, $request, [...RecordConsent::REQUIRED, 'retention']);

            return $user;
        });

        event(new Registered($user)); // sends the verification email + audit entry
        $user->notify(new TemplatedNotification('applicant.registered', actionUrl: route('applicant.dashboard'), actionText: __('Complete your profile')));

        return $user;
    }
}
