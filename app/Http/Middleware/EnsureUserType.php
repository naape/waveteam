<?php

namespace App\Http\Middleware;

use App\Enums\Portal;
use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the portals separate: an applicant can never use organisation pages
 * and vice versa. Usage: ->middleware('portal:applicant') / ('portal:org_contact') / ('portal:any')
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        // 'any' = any self-service portal user (applicant or organisation), never staff.
        if ($type === 'any') {
            abort_if($user && Portal::forUser($user) === null, 403, __('This area is not available for your account type.'));

            return $next($request);
        }

        if ($user && $user->user_type !== UserType::from($type)) {
            // Portal users are sent to their own dashboard; staff get a hard 403.
            $own = Portal::forUser($user);

            abort_if($own === null, 403, __('This area is not available for your account type.'));

            return redirect($own->dashboardUrl())
                ->with('status', __('That page belongs to another portal. You are signed in to the :portal.', ['portal' => $own->label()]));
        }

        return $next($request);
    }
}
