<?php

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only trust proxies explicitly listed (e.g. a load balancer / Cloudflare)
        // so client IPs used by the admin allowlist and audit log can't be spoofed.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: array_map('trim', explode(',', $proxies)));
        }

        $middleware->append(SecurityHeaders::class);
        $middleware->appendToGroup('web', EnsureAccountActive::class);

        // Must run before any Authenticate middleware (which Laravel sorts
        // early) so a deactivated user's session is ended, not merely refused.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: EnsureAccountActive::class,
        );

        // Send guests to the sign-in page of the portal they were trying to reach.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => match (true) {
            $request->is('organizations', 'organizations/*') => route('organization.login'),
            default => route('applicant.login'),
        });

        // Signed-in users visiting guest pages (login/register) go to their dashboard.
        $middleware->redirectUsersTo(fn (\Illuminate\Http\Request $request) => \App\Enums\Portal::forUser($request->user())?->dashboardUrl()
            ?? url(config('portal.admin_path')));

        $middleware->alias([
            'portal' => EnsureUserType::class,
            'admin.ip' => AdminIpAllowlist::class,
            'staff.mfa' => \App\Http\Middleware\RequireStaffMfa::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'nic_number', 'passport_number']);
    })->create();
