<?php

namespace App\Filament\Actions;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\ReactivateUser;
use App\Enums\AccountStatus;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Shared deactivate / reactivate table actions (staff and portal accounts).
 */
class AccountStatusActions
{
    /** @param  Closure(User): bool  $authorize */
    public static function deactivate(Closure $authorize): Action
    {
        return Action::make('deactivate')
            ->label(__('Deactivate'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record) => $record->status !== AccountStatus::Deactivated
                && ! $record->is(auth()->user())
                && $authorize($record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => __('Deactivate :name?', ['name' => $record->name]))
            ->modalDescription(__('The user is signed out immediately on all devices and cannot sign in until reactivated. Their data is kept.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason (recorded in the audit log)'))->required()->maxLength(500),
            ])
            ->action(function (User $record, array $data) {
                app(DeactivateUser::class)($record, auth()->user(), $data['reason']);
                Notification::make()->title(__('Account deactivated'))->success()->send();
            });
    }

    /** @param  Closure(User): bool  $authorize */
    public static function reactivate(Closure $authorize): Action
    {
        return Action::make('reactivate')
            ->label(__('Reactivate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->visible(fn (User $record) => $record->status !== AccountStatus::Active && $authorize($record))
            ->requiresConfirmation()
            ->schema([
                Textarea::make('note')->label(__('Note (optional)'))->maxLength(500),
            ])
            ->action(function (User $record, array $data) {
                app(ReactivateUser::class)($record, auth()->user(), $data['note'] ?? null);
                Notification::make()->title(__('Account reactivated'))->success()->send();
            });
    }
}
