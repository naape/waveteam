<?php

namespace App\Filament\Resources\Staff;

use App\Enums\AccountStatus;
use App\Filament\Actions\AccountStatusActions;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Models\User;
use App\Support\Audit;
use BackedEnum;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * WaveTeam staff accounts (admin portal users) and their roles.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->staff()->with('roles');
    }

    // ---- Authorisation (permission: staff.manage) -----------------------------

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('staff.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        // Only a Super Admin may edit a Super Admin (prevents account takeover
        // by changing their email and resetting the password).
        return static::canViewAny() && $record->isStaff()
            && (! $record->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) || static::isSuperAdmin());
    }

    public static function canDelete($record): bool
    {
        return false; // staff are deactivated, never deleted (audit trail)
    }

    public static function isSuperAdmin(?User $user = null): bool
    {
        return ($user ?? auth()->user())?->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) ?? false;
    }

    // ---- Form -------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Staff member'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(150),
                    TextInput::make('email')->email()->required()->maxLength(190)
                        ->unique(User::class, 'email', ignoreRecord: true),
                ]),
            Section::make(__('Roles'))
                ->description(__('Permissions come from roles. Only a Super Admin can grant the Super Admin role.'))
                ->schema([
                    CheckboxList::make('roles')
                        ->hiddenLabel()
                        ->relationship('roles', 'name', fn (Builder $query) => static::isSuperAdmin()
                            ? $query
                            : $query->where('name', '!=', RolesAndPermissionsSeeder::SUPER_ADMIN))
                        ->descriptions(fn () => Role::query()->with('permissions')->get()
                            ->mapWithKeys(fn (Role $r) => [$r->id => $r->name === RolesAndPermissionsSeeder::SUPER_ADMIN
                                ? __('Full access to everything')
                                : __(':count permissions', ['count' => $r->permissions->count()])])->all())
                        ->columns(2)
                        ->required()
                        // Nobody edits their own roles (prevents self-escalation and lock-out).
                        ->disabled(fn (?User $record) => $record?->is(auth()->user())),
                ]),
        ]);
    }

    // ---- Table ------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('roles.name')->badge()->color('primary')->label(__('Roles')),
                IconColumn::make('mfa')->label(__('2FA'))->boolean()
                    ->state(fn (User $record) => filled($record->getAppAuthenticationSecret())),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->since()->sortable()->placeholder(__('Never')),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(AccountStatus::options()),
                SelectFilter::make('roles')->relationship('roles', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                AccountStatusActions::deactivate(fn (User $r) => static::canViewAny()
                    && (! $r->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) || static::isSuperAdmin())),
                AccountStatusActions::reactivate(fn () => static::canViewAny()),
                static::resetMfaAction(),
            ]);
    }

    /** Super Admin can clear a colleague's 2FA (lost phone); they must re-enrol at next sign-in. */
    public static function resetMfaAction(): Action
    {
        return Action::make('resetMfa')
            ->label(__('Reset 2FA'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (User $record) => static::isSuperAdmin() && ! $record->is(auth()->user())
                && filled($record->getAppAuthenticationSecret()))
            ->requiresConfirmation()
            ->modalDescription(__('The user must set up a new authenticator app at their next sign-in. Verify their identity before doing this.'))
            ->action(function (User $record) {
                $record->saveAppAuthenticationSecret(null);
                $record->saveAppAuthenticationRecoveryCodes(null);
                Audit::log('staff.mfa_reset', $record);
                Notification::make()->title(__('Two-factor authentication reset'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
