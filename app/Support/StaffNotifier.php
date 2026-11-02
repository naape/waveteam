<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Sends a notification to every active staff member holding a permission
 * (optionally preferring one specific person, e.g. the assigned manager).
 */
class StaffNotifier
{
    /** @return Collection<int, User> */
    public static function withPermission(string $permission): Collection
    {
        return User::query()
            ->where('user_type', UserType::Staff)
            ->where('status', AccountStatus::Active)
            ->get()
            ->filter(fn (User $u) => $u->can($permission))
            ->values();
    }

    public static function notify(string $permission, Notification $notification, ?User $preferred = null): void
    {
        $recipients = $preferred && $preferred->isActive() ? collect([$preferred]) : self::withPermission($permission);

        NotificationFacade::send($recipients, $notification);
    }
}
