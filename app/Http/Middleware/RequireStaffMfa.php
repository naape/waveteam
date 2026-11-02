<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outside the Filament panel (e.g. the shared document viewer), staff must
 * already have two-factor authentication set up. Without this, a staff
 * member who signed in but skipped MFA set-up could open documents directly.
 */
class RequireStaffMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isStaff() && blank($user->getAppAuthenticationSecret())) {
            abort(403, __('Set up two-factor authentication in the admin portal first.'));
        }

        return $next($request);
    }
}
