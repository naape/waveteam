<?php

namespace App\Filament\EmailQueue;

use App\Actions\Admin\EmailQueueActions;
use App\Models\QueuedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class WaitingEmails extends TableWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Waiting to send';

    public static function canView(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => QueuedJob::query()->emails())
            ->defaultSort('created_at')
            ->poll('15s')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('to')->label(__('To'))->state(fn (QueuedJob $r) => $r->emailInfo()['to'])->wrap(),
                TextColumn::make('subject')->label(__('Subject'))->state(fn (QueuedJob $r) => $r->emailInfo()['subject'])->wrap(),
                TextColumn::make('state')->label(__('Status'))->badge()->state(fn (QueuedJob $r) => $r->stateLabel()),
                TextColumn::make('attempts')->label(__('Attempts'))->numeric(),
                TextColumn::make('created_at')->label(__('Queued'))->since()->sortable(),
            ])
            ->headerActions([
                Action::make('deleteAll')->label(__('Delete all'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => QueuedJob::query()->emails()->whereNull('reserved_at')->exists())
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete all waiting emails?'))
                    ->modalDescription(__('These emails will not be sent, and this cannot be undone. In-portal notices are not affected. Emails being sent right now are left alone.'))
                    ->modalSubmitActionLabel(__('Delete all'))
                    ->action(function () {
                        $count = app(EmailQueueActions::class)->deleteAllWaiting();
                        Notification::make()->title(__(':n waiting emails deleted', ['n' => $count]))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('delete')->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (QueuedJob $record) => $record->reserved_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('This email will not be sent, and this cannot be undone.'))
                    ->action(function (QueuedJob $record) {
                        app(EmailQueueActions::class)->deleteWaiting($record)
                            ? Notification::make()->title(__('Waiting email deleted'))->success()->send()
                            : Notification::make()->title(__('This email is already being sent'))->warning()->send();
                    }),
            ])
            ->emptyStateHeading(__('No emails waiting'));
    }
}
