<?php

namespace App\Http\Controllers\Applicant;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\JobVacancy;
use App\Support\Profile\ProfileCompletion;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $profile = $user->applicantProfile()->with(['currentPoliceClearance', 'jobCategories:id'])->firstOrFail();
        ProfileCompletion::refresh($profile);

        $categoryIds = $profile->jobCategories->pluck('id');
        $appliedIds = $profile->jobApplications()->pluck('job_vacancy_id');

        $currentDocs = $profile->documents()->where('is_current', true)->get()->toBase()->groupBy(fn ($d) => $d->document_type->value);

        return view('applicant.dashboard', [
            'profile' => $profile,
            'checklist' => ProfileCompletion::checklist($profile),
            'pccStatus' => $profile->policeClearanceStatus(),
            'pendingPcc' => $profile->policeClearanceRecords()->pending()->whereNull('superseded_at')->first(),
            'documents' => [
                'cv' => $currentDocs->has(DocumentType::Cv->value),
                'nic' => $currentDocs->has(DocumentType::Nic->value),
                'certificates' => $currentDocs->only([DocumentType::EducationCert->value, DocumentType::ProfessionalCert->value])->flatten()->count(),
            ],
            'applications' => $profile->jobApplications()->with('vacancy')->latest('submitted_at')->limit(5)->get(),
            'vacancies' => JobVacancy::query()->open()
                ->whereNotIn('id', $appliedIds)
                ->when($categoryIds->isNotEmpty(), fn ($q) => $q->whereIn('job_category_id', $categoryIds))
                ->with(['jobCategory', 'district'])
                ->latest('published_at')->limit(3)->get(),
            'assignments' => $profile->assignments()
                ->whereIn('status', ['proposed', 'confirmed', 'active'])
                ->with('serviceRequest:id,title,location_text')
                ->orderBy('start_date')->get(),
            'notifications' => $user->notifications()->latest()->limit(6)->get(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
