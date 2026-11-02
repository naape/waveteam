<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $profile = $request->user()->applicantProfile()->firstOrFail();

        return view('applicant.applications.index', [
            'applications' => $profile->jobApplications()
                ->with([
                    'vacancy.jobCategory',
                    'statusHistory' => fn ($q) => $q->orderBy('created_at'),
                    'interviews' => fn ($q) => $q->orderBy('scheduled_at'),
                ])
                ->latest('submitted_at')
                ->get(),
        ]);
    }
}
