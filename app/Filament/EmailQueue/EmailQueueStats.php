<?php

namespace App\Filament\EmailQueue;

use App\Models\FailedQueueJob;
use App\Models\QueuedJob;
use App\Support\Mail\QueuedEmail;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EmailQueueStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    protected function getStats(): array
    {
        $waiting = QueuedJob::query()->emails()->count();
        $oldest = QueuedJob::query()->emails()->orderBy('created_at')->first();
        $failed = FailedQueueJob::query()->emails()->count();
        $running = QueuedEmail::schedulerRunning();
        $last = QueuedEmail::lastBeat();

        return [
            Stat::make(__('Automatic sending (cron job)'), $running ? __('Running') : __('Not running'))
                ->description($last ? __('Last run :when', ['when' => $last->diffForHumans()]) : __('No run recorded. Emails stay queued until the cron job is set up.'))
                ->descriptionIcon($running ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($running ? 'success' : 'danger'),
            Stat::make(__('Waiting to send'), $waiting)
                ->description($oldest ? __('Oldest queued :when', ['when' => $oldest->created_at->diffForHumans()]) : __('Queue is empty'))
                ->color($waiting ? 'warning' : 'success'),
            Stat::make(__('Failed'), $failed)
                ->description($failed ? __('Check the reason below, then retry') : __('No failed emails'))
                ->color($failed ? 'danger' : 'success'),
        ];
    }
}
