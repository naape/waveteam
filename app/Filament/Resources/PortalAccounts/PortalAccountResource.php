<?php

namespace App\Filament\Resources\PortalAccounts;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Filament\Actions\AccountStatusActions;
use App\Filament\Resources\PortalAccounts\Pages\ListPortalAccounts;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Sign-in accounts of applicants and organisation contacts: status only
 * (deactivate / reactivate). Profiles are managed in their own modules.
 */
class PortalAccountResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'portal-accounts';

    protected static ?string $modelLabel = 'portal account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('user_type', [UserType::Applicant, UserType::OrgContact]);
    }

    public static function canManage(User $record): bool
    {
        $me = auth()->user();

        return match ($record->user_type) {
            UserType::Applicant => $me->can('applicants.change_status'),
            UserType::OrgContact => $me->can('organizations.manage'),
            default => false,
        };
    }

    public static function canViewAny(): bool
    {
        $me = auth()->user();

        return $me && ($me->can('applicants.change_status') || $me->can('organizations.manage'));
    }

    public static function canCreate(): bool
    {
        return false; // accounts are created by self-registration only
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (User $r) => $r->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('user_type')->label(__('Portal'))->badge(),
                IconColumn::make('email_verified_at')->label(__('Email verified'))->boolean()
                    ->state(fn (User $r) => $r->email_verified_at !== null),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label(__('Registered'))->date()->sortable(),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->since()->sortable()->placeholder(__('Never')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user_type')->label(__('Portal'))->options([
                    UserType::Applicant->value => UserType::Applicant->getLabel(),
                    UserType::OrgContact->value => UserType::OrgContact->getLabel(),
                ]),
                SelectFilter::make('status')->options(AccountStatus::options()),
                TernaryFilter::make('email_verified_at')->label(__('Email verified'))->nullable(),
            ])
            ->recordActions([
                AccountStatusActions::deactivate(fn (User $r) => static::canManage($r)),
                AccountStatusActions::reactivate(fn (User $r) => static::canManage($r)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPortalAccounts::route('/'),
        ];
    }
}
