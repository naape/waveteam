<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Enums\Portal;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password reset for applicant and organisation accounts. Staff reset their
 * password through the admin panel's own flow.
 */
class PasswordResetController extends Controller
{
    public function request(Portal $portal): View
    {
        return view('auth.forgot-password', ['portal' => $portal]);
    }

    public function email(Request $request, Portal $portal): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:190']]);

        // Only issue links to active accounts of this portal's type; the
        // response is identical either way so emails can't be enumerated.
        $status = Password::sendResetLink([
            'email' => mb_strtolower(trim($request->input('email'))),
            'user_type' => $portal->userType(),
            'status' => AccountStatus::Active,
        ]);

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return back()->with('status', __('If an account exists for that email, we have sent a password reset link. Please check your inbox.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $resetUser = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$resetUser) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Sign the account out everywhere after a reset.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
                $resetUser = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        $portal = Portal::forUser($resetUser);

        return redirect($portal ? $portal->route('login') : url(config('portal.admin_path').'/login'))
            ->with('status', __('Your password has been reset. Please sign in.'));
    }
}
