<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterApplicant;
use App\Actions\Auth\RegisterOrganization;
use App\Enums\OrganizationType;
use App\Enums\Portal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterApplicantRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function showApplicant(): View
    {
        return view('auth.register-applicant', ['portal' => Portal::Applicant]);
    }

    public function storeApplicant(RegisterApplicantRequest $request, RegisterApplicant $register): RedirectResponse
    {
        $user = $register($request->validated(), $request);

        return $this->signIn($request, $user);
    }

    public function showOrganization(): View
    {
        return view('auth.register-organization', [
            'portal' => Portal::Organization,
            'organizationTypes' => OrganizationType::options(),
        ]);
    }

    public function storeOrganization(RegisterOrganizationRequest $request, RegisterOrganization $register): RedirectResponse
    {
        $user = $register($request->validated(), $request);

        return $this->signIn($request, $user);
    }

    private function signIn(Request $request, $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')
            ->with('status', __('Your account has been created. Please check your email to verify your address.'));
    }
}
