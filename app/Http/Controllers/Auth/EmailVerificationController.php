<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Portal;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($this->home($request));
        }

        return view('auth.verify-email', ['portal' => Portal::forUser($request->user())]);
    }

    /** Signed link from the email; fires Verified (audited). */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect($this->home($request))->with('status', __('Thank you — your email address is verified.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($this->home($request));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('A new verification link has been sent to your email address.'));
    }

    private function home(Request $request): string
    {
        return Portal::forUser($request->user())?->dashboardUrl() ?? route('home');
    }
}
