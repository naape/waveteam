<?php

namespace App\Actions\Accounts;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deactivates an account and terminates it everywhere immediately:
 * all database sessions deleted, API tokens revoked, remember-me cleared.
 */
class DeactivateUser
{
    public function __invoke(User $user, User $by, string $reason): void
    {
        if ($user->is($by)) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        DB::transaction(function () use ($user, $by, $reason) {
            $old = $user->status;

            $user->forceFill([
                'status' => AccountStatus::Deactivated,
                'deactivated_at' => now(),
                'deactivated_by' => $by->id,
                'remember_token' => null,
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            Audit::log('user.deactivated', $user, ['status' => $old?->value], ['status' => AccountStatus::Deactivated->value], ['reason' => $reason]);
        });
    }
}
