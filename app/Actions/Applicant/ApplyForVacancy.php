<?php

namespace App\Actions\Applicant;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Models\ApplicantProfile;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobVacancy;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyForVacancy
{
    public function __invoke(ApplicantProfile $profile, JobVacancy $vacancy, ?string $coverNote = null): JobApplication
    {
        if (! JobVacancy::query()->open()->whereKey($vacancy->id)->exists()) {
            throw ValidationException::withMessages(['vacancy' => __('This vacancy is no longer accepting applications.')]);
        }

        // Snapshot of the CV used for this application (later CV updates don't change it).
        $cv = $profile->documents()->where('document_type', DocumentType::Cv)->where('is_current', true)->first();

        if ($cv === null) {
            throw ValidationException::withMessages(['cv' => __('Please upload your CV under Documents before applying.')]);
        }

        try {
            $application = DB::transaction(function () use ($profile, $vacancy, $cv, $coverNote) {
                $application = new JobApplication([
                    'job_vacancy_id' => $vacancy->id,
                    'applicant_profile_id' => $profile->id,
                    'cover_note' => $coverNote,
                    'cv_document_id' => $cv->id,
                    'submitted_at' => now(),
                ]);
                $application->status = ApplicationStatus::Submitted;
                $application->save();

                JobApplicationStatusHistory::create([
                    'job_application_id' => $application->id,
                    'from_status' => null,
                    'to_status' => ApplicationStatus::Submitted->value,
                    'changed_by' => $profile->user_id,
                ]);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['vacancy' => __('You have already applied for this vacancy.')]);
        }

        Audit::log('application.submitted', $application, meta: ['vacancy_id' => $vacancy->id]);

        $profile->user->notify(new TemplatedNotification(
            'application.submitted',
            ['vacancy_title' => $vacancy->title],
            route('applicant.applications.index'),
            __('Track your application'),
        ));

        return $application;
    }
}
