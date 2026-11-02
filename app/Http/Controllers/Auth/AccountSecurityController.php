<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Portal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Password change, active sessions and recent sign-in activity — shared by
 * both portals.
 */
class AccountSecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => (object) [
                'current' => $s->id === $request->session()->getId(),
                'ip' => $s->ip_address,
                'agent' => $this->describeAgent((string) $s->user_agent),
                'last_active' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity),
            ]);

        $activity = AuditLog::query()
            ->where('actor_id', $user->id)
            ->whereIn('action', ['auth.login', 'auth.logout', 'auth.password_reset', 'account.password_changed', 'account.sessions_revoked'])
            ->latest('created_at')
            ->limit(10)
            ->get();

        return view('account.security', [
            'portal' => Portal::forUser($user),
            'sessions' => $sessions,
            'activity' => $activity,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password']])->save();

        // Keep this session, end all others.
        $this->revokeOtherSessions($request);
        $request->session()->regenerate();

        Audit::log('account.password_changed', $user);

        return back()->with('status', __('Your password has been changed. Other devices have been signed out.'));
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validateWithBag('sessions', [
            'password' => ['required', 'current_password'],
        ]);

        Auth::logoutOtherDevices($request->input('password'));
        $count = $this->revokeOtherSessions($request);

        Audit::log('account.sessions_revoked', $request->user(), meta: ['count' => $count]);

        return back()->with('status', __('You have been signed out of all other devices.'));
    }

    private function revokeOtherSessions(Request $request): int
    {
        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }

    private function describeAgent(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => __('Browser'),
        };
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Unknown device'),
        };

        return "{$browser} · {$os}";
    }
}
