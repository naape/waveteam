<?php

namespace App\Actions\Admin;

use App\Actions\Accounts\DeactivateUser;
use App\Enums\AccountStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removing a staff member signs them out everywhere and hides the account.
 * Nothing is erased: the audit trail and their name on past work stay, and the
 * account can be restored.
 */
class StaffAccountActions
{
    public function __construct(private readonly DeactivateUser $deactivate) {}

    public function remove(User $staff, User $by, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required to remove a staff member.')]);
        }

        if (! $staff->isStaff() || $staff->is($by)) {
            throw ValidationException::withMessages(['reason' => __('You cannot remove your own account.')]);
        }

        // Also means a Super Admin always remains: the person removing one is one.
        if ($this->isSuperAdmin($staff) && ! $this->isSuperAdmin($by)) {
            throw ValidationException::withMessages(['reason' => __('Only a Super Admin can remove a Super Admin.')]);
        }

        $open = ServiceRequest::query()->where('assigned_manager_id', $staff->id)->get()
            ->reject(fn (ServiceRequest $request) => $request->status->isClosed())->count();

        if ($open > 0) {
            throw ValidationException::withMessages(['reason' => trans_choice(
                '{1} This person manages :n open service request. Assign it to someone else first.|[2,*] This person manages :n open service requests. Assign them to someone else first.',
                $open, ['n' => $open])]);
        }

        DB::transaction(function () use ($staff, $by, $reason) {
            if ($staff->status !== AccountStatus::Deactivated) {
                ($this->deactivate)($staff, $by, $reason);
            }

            $staff->delete();
            Audit::log('staff.removed', $staff, meta: [
                'reason' => $reason, 'email' => $staff->email, 'roles' => $staff->roles()->pluck('name')->all(),
            ]);
        });
    }

    /** The account comes back deactivated, so signing in again takes a separate decision. */
    public function restore(User $staff, User $by): void
    {
        if ($this->isSuperAdmin($staff) && ! $this->isSuperAdmin($by)) {
            throw ValidationException::withMessages(['reason' => __('Only a Super Admin can restore a Super Admin.')]);
        }

        $staff->restore();
        Audit::log('staff.restored', $staff, meta: ['email' => $staff->email]);
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN);
    }
}
