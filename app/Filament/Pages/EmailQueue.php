<?php

namespace App\Filament\Pages;

use App\Filament\EmailQueue\EmailQueueStats;
use App\Filament\EmailQueue\FailedEmails;
use App\Filament\EmailQueue\WaitingEmails;
use App\Models\FailedQueueJob;
use Filament\Pages\Page;
use UnitEnum;

/**
 * What is waiting to be emailed, what failed, and whether the cron job that
 * sends the queue is running.
 */
class EmailQueue extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static ?string $slug = 'email-queue';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationParentItem = 'Email servers (SMTP)';

    protected static ?int $navigationSort = 46;

    protected static ?string $title = 'Email queue';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = FailedQueueJob::query()->emails()->count();

        return $failed ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderWidgets(): array
    {
        return [EmailQueueStats::class, WaitingEmails::class, FailedEmails::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
