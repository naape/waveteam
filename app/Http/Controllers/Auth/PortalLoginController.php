<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Enums\Portal;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Portal-specific sign-in. Credentials only work in the portal matching the
 * account type; staff accounts can never sign in here (admin panel only).
 */
class PortalLoginController extends Controller
{
    /** Short-term: attempts per minute per email+IP. */
    private const PER_MINUTE = 5;

    /** Longer-term: failures per email within 15 minutes before a lockout. */
    private const PER_15_MIN = 10;

    public function show(Portal $portal): View
    {
        return view('auth.login', ['portal' => $portal]);
    }

    public function store(Request $request, Portal $portal): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = mb_strtolower(trim($credentials['email']));

        $this->ensureNotRateLimited($request, $email);

        $attempt = [
            'email' => $email,
            'password' => $credentials['password'],
            'user_type' => $portal->userType(),
            fn ($query) => $query->where('status', AccountStatus::Active),
        ];

        if (! Auth::attempt($attempt, $request->boolean('remember'))) {
            RateLimiter::hit($this->minuteKey($request, $email), 60);
            RateLimiter::hit($this->longKey($email), 15 * 60);

            throw ValidationException::withMessages(['email' => $this->failureMessage($email, $credentials['password'], $portal)]);
        }

        RateLimiter::clear($this->minuteKey($request, $email));
        RateLimiter::clear($this->longKey($email));
        $request->session()->regenerate();

        return redirect()->intended($portal->dashboardUrl());
    }

    public function destroy(Request $request): RedirectResponse
    {
        $portal = $request->user() ? Portal::forUser($request->user()) : null;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($portal ? $portal->route('login') : route('home'))
            ->with('status', __('You have been signed out.'));
    }

    /**
     * Only after the correct password is supplied do we explain *why* the
     * sign-in failed (wrong portal / inactive). Otherwise one generic message,
     * so the form can't be used to discover which emails are registered.
     */
    private function failureMessage(string $email, string $password, Portal $portal): string
    {
        $generic = __('These credentials do not match our records.');
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password) || $user->user_type === UserType::Staff) {
            return $generic;
        }

        if (! $user->isActive()) {
            return __('Your account is not active. Please contact WaveTeam at :email.', ['email' => config('portal.company.email')]);
        }

        $correct = Portal::forUser($user);

        if ($correct !== null && $correct !== $portal) {
            return __('This email is registered on the :portal. Please sign in there.', ['portal' => $correct->label()]);
        }

        return $generic;
    }

    private function ensureNotRateLimited(Request $request, string $email): void
    {
        $minute = $this->minuteKey($request, $email);
        $long = $this->longKey($email);

        if (! RateLimiter::tooManyAttempts($minute, self::PER_MINUTE) && ! RateLimiter::tooManyAttempts($long, self::PER_15_MIN)) {
            return;
        }

        event(new Lockout($request));

        $seconds = max(RateLimiter::availableIn($minute), RateLimiter::availableIn($long));

        throw ValidationException::withMessages([
            'email' => __('Too many sign-in attempts. Please try again in :minutes minute(s).', ['minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    private function minuteKey(Request $request, string $email): string
    {
        return 'login:'.Str::transliterate($email).'|'.$request->ip();
    }

    private function longKey(string $email): string
    {
        return 'login-15m:'.Str::transliterate($email);
    }
}
