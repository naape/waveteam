<?php

namespace App\Filament\Resources\Staff;

use App\Actions\Admin\StaffAccountActions;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;
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
        $query = parent::getEloquentQuery()->staff()->with('roles');

        // Only staff who may remove colleagues can see (and restore) removed ones.
        return static::canRemoveStaff()
            ? $query->withoutGlobalScopes([SoftDeletingScope::class])
            : $query;
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
        return static::canViewAny() && $record->isStaff() && ! $record->trashed()
            && (! $record->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) || static::isSuperAdmin());
    }

    public static function canDelete($record): bool
    {
        return false; // staff are removed with the Remove action, which keeps the account (audit trail)
    }

    public static function canRemoveStaff(): bool
    {
        return auth()->user()?->can('staff.delete') ?? false;
    }

    /** Only a Super Admin may remove or restore a Super Admin. */
    private static function canChangeRemoval(User $record): bool
    {
        return static::canRemoveStaff()
            && (! $record->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) || static::isSuperAdmin());
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
                TextColumn::make('status')->badge()
                    ->description(fn (User $record) => $record->trashed() ? __('Removed') : null),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->since()->sortable()->placeholder(__('Never')),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->options(AccountStatus::options()),
                SelectFilter::make('roles')->relationship('roles', 'name'),
                TrashedFilter::make()->label(__('Removed staff'))
                    ->placeholder(__('Hide removed'))->trueLabel(__('Show removed too'))->falseLabel(__('Only removed'))
                    ->visible(fn () => static::canRemoveStaff()),
            ])
            ->recordActions([
                EditAction::make()->hidden(fn (User $record) => $record->trashed()),
                AccountStatusActions::deactivate(fn (User $r) => static::canViewAny()
                    && (! $r->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) || static::isSuperAdmin())),
                AccountStatusActions::reactivate(fn (User $r) => static::canViewAny() && ! $r->trashed()),
                static::resetMfaAction(),
                static::removeAction(),
                static::restoreAction(),
            ]);
    }

    /** Super Admin can clear a colleague's 2FA (lost phone); they must re-enrol at next sign-in. */
    public static function resetMfaAction(): Action
    {
        return Action::make('resetMfa')
            ->label(__('Reset 2FA'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (User $record) => static::isSuperAdmin() && ! $record->is(auth()->user()) && ! $record->trashed()
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

    public static function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('Remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (User $record) => static::canChangeRemoval($record) && ! $record->trashed() && ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => __('Remove :name?', ['name' => $record->name]))
            ->modalDescription(__('They are signed out on all devices and disappear from the staff list. Their name stays on past work and in the audit log, and the account can be restored.'))
            ->modalSubmitActionLabel(__('Remove'))
            ->schema([
                Textarea::make('reason')->label(__('Reason (recorded in the audit log)'))->required()->maxLength(500),
            ])
            ->action(function (User $record, array $data, Action $action) {
                try {
                    app(StaffAccountActions::class)->remove($record, auth()->user(), $data['reason']);
                } catch (ValidationException $e) {
                    Notification::make()->title(__('This staff member cannot be removed yet'))
                        ->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();
                    $action->halt();
                }

                Notification::make()->title(__('Staff member removed'))->success()->send();
            });
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label(__('Restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (User $record) => static::canChangeRemoval($record) && $record->trashed())
            ->requiresConfirmation()
            ->modalDescription(__('The account comes back deactivated. Reactivate it when the person should be able to sign in again.'))
            ->action(function (User $record) {
                app(StaffAccountActions::class)->restore($record, auth()->user());
                Notification::make()->title(__('Staff member restored'))->body(__('Reactivate the account to let them sign in.'))->success()->send();
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
