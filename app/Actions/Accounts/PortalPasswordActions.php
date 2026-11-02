<?php

namespace App\Actions\Accounts;

use App\Enums\UserType;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Staff-initiated password changes for applicant and organisation accounts.
 * Staff passwords are never changed here (they use their own reset flow).
 */
class PortalPasswordActions
{
    /** Sets a new password and signs the account out on every device. */
    public function setPassword(User $user, string $password, User $staff, string $reason): void
    {
        $this->guard($user);

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        Audit::log('account.password_set_by_staff', $user, meta: ['reason' => $reason, 'portal' => $user->user_type->value]);
    }

    /** Emails the standard reset link; the current password keeps working until it is used. */
    public function sendResetLink(User $user, User $staff): string
    {
        $this->guard($user);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        Audit::log('account.password_reset_link_sent', $user, meta: ['status' => $status, 'portal' => $user->user_type->value]);

        return $status;
    }

    private function guard(User $user): void
    {
        if (! in_array($user->user_type, [UserType::Applicant, UserType::OrgContact], true)) {
            throw new InvalidArgumentException('Only applicant and organisation passwords can be changed here.');
        }
    }
}
