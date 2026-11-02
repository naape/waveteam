<?php

namespace App\Http\Controllers\Applicant;

use App\Actions\Applicant\SubmitPoliceClearance;
use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PoliceClearanceController extends Controller
{
    public function index(Request $request): View
    {
        $profile = $request->user()->applicantProfile()->with('currentPoliceClearance')->firstOrFail();

        return view('applicant.police-clearance', [
            'profile' => $profile,
            'status' => $profile->policeClearanceStatus(),
            'current' => $profile->currentPoliceClearance,
            'history' => $profile->policeClearanceRecords()->with('document')->get(),
            'validityMonths' => (int) Settings::get('pcc.default_validity_months'),
            'maxMb' => round(Settings::get('uploads.max_kb') / 1024, 1),
        ]);
    }

    public function store(Request $request, SubmitPoliceClearance $submit): RedirectResponse
    {
        $data = $request->validate([
            'issue_date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYears(3)->toDateString()],
            'certificate_number' => ['nullable', 'string', 'max:50'],
            'issuing_authority' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:'.(int) Settings::get('uploads.max_kb')],
            'confirm_genuine' => ['accepted'],
        ], [
            'issue_date.after' => __('This certificate is too old. Please obtain a new police clearance certificate.'),
            'confirm_genuine.accepted' => __('Please confirm the certificate is genuine and unaltered.'),
        ]);

        $profile = $request->user()->applicantProfile()->firstOrFail();

        $submit($profile, $request->file('file'), Carbon::parse($data['issue_date']), $request->user(), $data['certificate_number'] ?? null, $data['issuing_authority'] ?? null);

        return redirect()->route('applicant.police-clearance.index')
            ->with('status', __('Certificate uploaded. It will show as “Pending Verification” until a WaveTeam administrator has checked it.'));
    }
}
