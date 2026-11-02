<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional IP allowlist for the admin panel (ADMIN_IP_ALLOWLIST in .env).
 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('portal.admin_ip_allowlist');

        if ($allowed && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
