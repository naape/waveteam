<?php

namespace App\Filament\Actions;

use App\Actions\Accounts\PortalPasswordActions;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

/**
 * "Set a new password" and "Email a password reset link" for applicant and
 * organisation accounts, usable on any page or table.
 *
 * $accounts maps the page/table record to the account(s) it can act on
 * (one for an applicant, possibly several contacts for an organisation).
 */
class PasswordActions
{
    /**
     * @param  Closure(Model): Collection<int, User>  $accounts
     * @param  Closure(Model): bool  $authorize
     */
    public static function set(Closure $accounts, Closure $authorize): Action
    {
        return Action::make('setPassword')->label(__('Set a new password'))->icon(Heroicon::OutlinedKey)
            ->visible(fn (Model $record) => $authorize($record) && $accounts($record)->isNotEmpty())
            ->modalHeading(__('Set a new password'))
            ->modalDescription(__('The account is signed out on all devices. Give the new password through a safe channel (not email) and ask them to change it after signing in.'))
            ->schema(fn (Model $record) => [
                self::accountSelect($accounts($record)),
                TextInput::make('password')->label(__('New password'))->password()->revealable()
                    ->required()->rule(Password::defaults())->confirmed()->autocomplete('new-password'),
                TextInput::make('password_confirmation')->label(__('Confirm new password'))->password()->revealable()->required()->dehydrated(false),
                Textarea::make('reason')->label(__('Reason (audit log)'))->required()->maxLength(1000),
            ])
            ->action(function (Model $record, array $data) use ($accounts) {
                $user = self::pick($accounts($record), $data);
                app(PortalPasswordActions::class)->setPassword($user, $data['password'], auth()->user(), $data['reason']);
                Notification::make()->title(__('Password changed for :name', ['name' => $user->name]))
                    ->body(__('They have been signed out everywhere.'))->success()->send();
            });
    }

    /**
     * @param  Closure(Model): Collection<int, User>  $accounts
     * @param  Closure(Model): bool  $authorize
     */
    public static function resetLink(Closure $accounts, Closure $authorize): Action
    {
        return Action::make('resetLink')->label(__('Email a password reset link'))->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Model $record) => $authorize($record) && $accounts($record)->isNotEmpty())
            ->modalHeading(__('Email a password reset link'))
            ->modalDescription(__('The current password keeps working until they choose a new one from the link.'))
            ->modalSubmitActionLabel(__('Send link'))
            ->schema(fn (Model $record) => [self::accountSelect($accounts($record))])
            ->action(function (Model $record, array $data) use ($accounts) {
                $user = self::pick($accounts($record), $data);
                $status = app(PortalPasswordActions::class)->sendResetLink($user, auth()->user());
                $status === PasswordBroker::RESET_LINK_SENT
                    ? Notification::make()->title(__('Reset link sent to :email', ['email' => $user->email]))->success()->send()
                    : Notification::make()->title(__($status))->warning()->send();
            });
    }

    /** Shows which account is affected; a choice only when there are several. */
    private static function accountSelect(Collection $users): Select
    {
        return Select::make('user_id')->label(__('Account'))
            ->options($users->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"]))
            ->default($users->first()?->id)
            ->required()
            ->selectablePlaceholder(false)
            ->disabled($users->count() === 1)
            ->dehydrated();
    }

    private static function pick(Collection $users, array $data): User
    {
        // Only accounts belonging to this record are accepted.
        return $users->firstWhere('id', (int) ($data['user_id'] ?? $users->first()?->id)) ?? abort(404);
    }
}
