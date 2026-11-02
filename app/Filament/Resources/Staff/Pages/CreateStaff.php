<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use App\Support\Audit;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Staff are invited: the account gets an unusable random password and the
 * person receives a link to set their own. 2FA set-up is forced at first sign-in.
 */
class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected static ?string $title = 'Invite staff member';

    protected function handleRecordCreation(array $data): Model
    {
        $user = new User([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => Str::password(40),
        ]);
        $user->user_type = UserType::Staff;
        $user->status = AccountStatus::Active;
        $user->email_verified_at = now(); // ownership is proven by using the emailed link
        $user->save();

        return $user;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;

        $token = Password::broker()->createToken($user);
        $notification = new ResetPassword($token);
        $notification->url = Filament::getResetPasswordUrl($token, $user);
        $user->notify($notification);

        Audit::log('staff.created', $user, new: ['email' => $user->email, 'roles' => $user->roles()->pluck('name')->all()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Invitation sent. The staff member will receive an email to set their password.');
    }
}
