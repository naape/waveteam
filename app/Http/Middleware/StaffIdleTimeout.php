<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs staff out of the admin panel after a configurable idle period
 * (setting: session.staff_idle_minutes, default 30).
 */
class StaffIdleTimeout
{
    private const KEY = 'staff_last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isStaff()) {
            $limit = (int) Settings::get('session.staff_idle_minutes') * 60;
            $last = (int) $request->session()->get(self::KEY, time());

            if ($limit > 0 && time() - $last > $limit) {
                Audit::log('auth.idle_timeout', $user);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect(url(config('portal.admin_path').'/login'));
            }

            $request->session()->put(self::KEY, time());
        }

        return $next($request);
    }
}
