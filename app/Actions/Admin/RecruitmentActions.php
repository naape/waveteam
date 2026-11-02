<?php

namespace App\Actions\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecruitmentActions
{
    public function changeStatus(JobApplication $application, ApplicationStatus $to, User $by, ?string $note = null, bool $notify = true): void
    {
        $from = $application->status;
        if ($from === $to) {
            return;
        }

        DB::transaction(function () use ($application, $from, $to, $by, $note) {
            $application->forceFill(['status' => $to])->save();
            JobApplicationStatusHistory::create([
                'job_application_id' => $application->id,
                'from_status' => $from?->value,
                'to_status' => $to->value,
                'changed_by' => $by->id,
                'note' => $note,
            ]);
        });

        Audit::log('application.status_changed', $application, ['status' => $from?->value], ['status' => $to->value], array_filter(['note' => $note]));

        // Interview invitations send their own, more detailed, notification.
        if ($notify && $to !== ApplicationStatus::InterviewScheduled) {
            $application->applicantProfile->user->notify(new TemplatedNotification('application.status_changed', [
                'vacancy_title' => $application->vacancy->title,
                'status' => $to->getLabel(),
            ], route('applicant.applications.index'), __('View application')));
        }
    }

    public function scheduleInterview(JobApplication $application, Carbon $at, InterviewMode $mode, ?string $location, User $by, ?string $notes = null): Interview
    {
        if ($at->isPast()) {
            throw ValidationException::withMessages(['scheduled_at' => __('The interview must be in the future.')]);
        }

        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Selected], true)) {
            throw ValidationException::withMessages(['scheduled_at' => __('This application is already closed.')]);
        }

        $interview = DB::transaction(function () use ($application, $at, $mode, $location, $by, $notes) {
            // Any earlier scheduled interview for this application is superseded.
            $application->interviews()->where('status', InterviewStatus::Scheduled)->update(['status' => InterviewStatus::Rescheduled]);

            $interview = Interview::create([
                'job_application_id' => $application->id,
                'scheduled_at' => $at,
                'mode' => $mode,
                'location_or_link' => $location,
                'status' => InterviewStatus::Scheduled,
                'notes' => $notes,
                'scheduled_by' => $by->id,
            ]);

            $this->changeStatus($application, ApplicationStatus::InterviewScheduled, $by, 'Interview '.$at->format('Y-m-d H:i'), notify: false);

            return $interview;
        });

        Audit::log('interview.scheduled', $interview, new: ['scheduled_at' => $at->toDateTimeString(), 'mode' => $mode->value]);

        $application->applicantProfile->user->notify(new TemplatedNotification('interview.invitation', [
            'vacancy_title' => $application->vacancy->title,
            'scheduled_at' => $at->timezone(config('app.timezone'))->format('l, j F Y \a\t g:i A'),
            'mode' => $mode->getLabel(),
            'location' => $location ?: __('to be confirmed'),
        ], route('applicant.applications.index'), __('View details')));

        return $interview;
    }

    public function recordOutcome(Interview $interview, InterviewStatus $status, User $by, ?int $score = null, ?string $outcome = null): void
    {
        $interview->forceFill(['status' => $status, 'score' => $score, 'outcome' => $outcome])->save();
        Audit::log('interview.outcome_recorded', $interview, new: array_filter(['status' => $status->value, 'score' => $score]));
    }
}
