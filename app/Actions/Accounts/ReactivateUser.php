<?php

namespace App\Actions\Accounts;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\Audit;

class ReactivateUser
{
    public function __invoke(User $user, User $by, ?string $note = null): void
    {
        $old = $user->status;

        $user->forceFill([
            'status' => AccountStatus::Active,
            'deactivated_at' => null,
            'deactivated_by' => null,
        ])->save();

        Audit::log('user.reactivated', $user, ['status' => $old?->value], ['status' => AccountStatus::Active->value], array_filter(['note' => $note, 'by' => $by->id]));
    }
}
