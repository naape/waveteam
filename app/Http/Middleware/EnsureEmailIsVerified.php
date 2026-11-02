<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseEnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's "verified" middleware, with a switch to turn the check off
 * temporarily (EMAIL_VERIFICATION_REQUIRED=false). Keep it on in production.
 */
class EnsureEmailIsVerified extends BaseEnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null): Response
    {
        if (! config('portal.email_verification_required', true)) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
