<?php

namespace App\Filament\EmailQueue;

use App\Actions\Admin\EmailQueueActions;
use App\Models\FailedQueueJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

class FailedEmails extends TableWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Failed';

    public static function canView(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => FailedQueueJob::query()->emails())
            ->defaultSort('failed_at', 'desc')
            ->poll('15s')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('to')->label(__('To'))->state(fn (FailedQueueJob $r) => $r->emailInfo()['to'])->wrap(),
                TextColumn::make('subject')->label(__('Subject'))->state(fn (FailedQueueJob $r) => $r->emailInfo()['subject'])->wrap(),
                TextColumn::make('reason')->label(__('Reason'))->state(fn (FailedQueueJob $r) => $r->reason())->wrap()->color('danger'),
                TextColumn::make('failed_at')->label(__('Failed'))->since()->sortable(),
            ])
            ->headerActions([
                Action::make('retryAll')->label(__('Retry all'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn () => FailedQueueJob::query()->emails()->exists())
                    ->requiresConfirmation()
                    ->modalDescription(__('All failed emails go back in the queue and the next cron run sends them.'))
                    ->action(function () {
                        $count = app(EmailQueueActions::class)->retryAll();
                        Notification::make()->title(__(':n emails queued again', ['n' => $count]))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('details')->label(__('Details'))->icon(Heroicon::OutlinedInformationCircle)->color('gray')
                    ->modalHeading(__('Why this email failed'))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (FailedQueueJob $record) => new HtmlString(
                        '<pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:60vh;overflow:auto">'.e($record->exception).'</pre>'
                    )),
                Action::make('retry')->label(__('Retry'))->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (FailedQueueJob $record) {
                        app(EmailQueueActions::class)->retry($record);
                        Notification::make()->title(__('Email queued again'))->success()->send();
                    }),
                Action::make('delete')->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('This email will not be sent.'))
                    ->action(function (FailedQueueJob $record) {
                        app(EmailQueueActions::class)->forget($record);
                        Notification::make()->title(__('Failed email deleted'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No failed emails'));
    }
}
