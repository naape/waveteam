<?php

namespace App\Filament\Resources\JobApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\JobApplications\JobApplicationResource;
use App\Models\JobApplication;
use Filament\Resources\Pages\ViewRecord;

class ViewJobApplication extends ViewRecord
{
    protected static string $resource = JobApplicationResource::class;

    /** Eager-load everything the page shows (strict mode forbids lazy loading). */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['vacancy', 'applicantProfile.currentPoliceClearance', 'cvDocument', 'interviews', 'statusHistory.changer']);
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var JobApplication $app */
        $app = $this->record;

        // Opening a new application moves it to "Under review" automatically.
        if ($app->status === ApplicationStatus::Submitted && auth()->user()->can('applications.manage')) {
            app(\App\Actions\Admin\RecruitmentActions::class)->changeStatus($app, ApplicationStatus::UnderReview, auth()->user(), 'Opened by reviewer');
            $this->record = $this->resolveRecord($this->record->getKey());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            JobApplicationResource::interviewAction(),
            JobApplicationResource::statusAction(),
        ];
    }
}
