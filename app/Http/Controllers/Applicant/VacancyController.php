<?php

namespace App\Http\Controllers\Applicant;

use App\Actions\Applicant\ApplyForVacancy;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\JobCategory;
use App\Models\JobVacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VacancyController extends Controller
{
    public function index(Request $request): View
    {
        $profile = $request->user()->applicantProfile()->firstOrFail();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            'district' => ['nullable', 'integer'],
        ]);

        $vacancies = JobVacancy::query()
            ->open()
            ->with(['jobCategory', 'district', 'districts'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('title', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('location_text', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->where('job_category_id', $id))
            ->when($filters['district'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q
                ->where('district_id', $id)
                ->orWhereHas('districts', fn ($d) => $d->whereKey($id))))
            ->orderByDesc('published_at')
            ->paginate(10)
            ->withQueryString();

        return view('applicant.vacancies.index', [
            'vacancies' => $vacancies,
            'filters' => $filters,
            'categories' => JobCategory::where('is_active', true)->orderBy('sort')->pluck('name', 'id'),
            'districts' => District::orderBy('name_en')->get()->mapWithKeys(fn ($d) => [$d->id => $d->localizedName()]),
            'appliedIds' => $profile->jobApplications()->pluck('job_vacancy_id')->all(),
        ]);
    }

    public function show(Request $request, JobVacancy $vacancy): View
    {
        $profile = $request->user()->applicantProfile()->firstOrFail();
        $application = $profile->jobApplications()->where('job_vacancy_id', $vacancy->id)->first();
        $isOpen = JobVacancy::query()->open()->whereKey($vacancy->id)->exists();

        // Closed / draft vacancies are only visible to people who already applied.
        abort_unless($isOpen || $application, 404);

        return view('applicant.vacancies.show', [
            'vacancy' => $vacancy->load(['jobCategory', 'district', 'districts']),
            'application' => $application,
            'isOpen' => $isOpen,
            'hasCv' => $profile->documents()->where('document_type', DocumentType::Cv)->where('is_current', true)->exists(),
            'pccStatus' => $profile->policeClearanceStatus(),
        ]);
    }

    public function apply(Request $request, JobVacancy $vacancy, ApplyForVacancy $apply): RedirectResponse
    {
        $data = $request->validate([
            'cover_note' => ['nullable', 'string', 'max:2000'],
            'confirm_accurate' => ['accepted'],
        ], ['confirm_accurate.accepted' => __('Please confirm that your profile information is accurate.')]);

        $apply($request->user()->applicantProfile()->firstOrFail(), $vacancy, $data['cover_note'] ?? null);

        return redirect()->route('applicant.applications.index')
            ->with('status', __('Your application for “:title” has been submitted.', ['title' => $vacancy->title]));
    }
}
