<?php

namespace App\Filament\EmailQueue;

use App\Models\QueuedJob;
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
            ->emptyStateHeading(__('No emails waiting'));
    }
}
