<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;

/**
 * Audit trail for every authentication / security event, from all portals
 * including the Filament admin login.
 *
 * Registered automatically by Laravel's listener discovery (handle* methods).
 */
class AuthEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        Audit::log('auth.login', $user, meta: ['remember' => $event->remember]);
    }

    public function handleFailed(Failed $event): void
    {
        // Never store the attempted password; only the identifier.
        Audit::log('auth.login_failed', $event->user, meta: [
            'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 190),
            'known_account' => $event->user !== null,
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        Audit::log('auth.lockout', meta: ['email' => mb_substr((string) $event->request->input('email'), 0, 190)]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            Audit::log('auth.logout', $event->user);
        }
    }

    public function handleRegistered(Registered $event): void
    {
        Audit::log('auth.registered', $event->user);
    }

    public function handleVerified(Verified $event): void
    {
        Audit::log('auth.email_verified', $event->user);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        Audit::log('auth.password_reset', $event->user);
    }
}
