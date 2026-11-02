<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use BackedEnum;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Roles and their permissions. The Super Admin role is fixed (always has
 * everything) and cannot be edited or deleted here.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $slug = 'roles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('roles.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->name !== RolesAndPermissionsSeeder::SUPER_ADMIN;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Permissions grouped by module for a readable checklist. */
    public static function permissionGroups(): array
    {
        return Permission::query()->orderBy('name')->get()
            ->groupBy(fn ($p) => Str::of($p->name)->before('.')->replace('_', ' ')->title()->toString())
            ->map(fn ($group) => $group->mapWithKeys(fn ($p) => [$p->id => Str::of($p->name)->after('.')->replace('_', ' ')->ucfirst()->toString()]))
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (static::permissionGroups() as $module => $options) {
            $sections[] = CheckboxList::make('permissions_'.Str::slug($module, '_'))
                ->label($module)
                ->options($options)
                ->columns(2)
                ->bulkToggleable();
        }

        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(60)->unique(Role::class, 'name', ignoreRecord: true)
                    ->notIn([RolesAndPermissionsSeeder::SUPER_ADMIN]),
            ]),
            Section::make(__('Permissions'))->description(__('“view pii” shows full NIC/passport numbers. “police clearance view” opens police clearance certificates.'))
                ->columns(3)->columnSpanFull()->schema($sections),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('permissions_count')->counts('permissions')->label(__('Permissions')),
                TextColumn::make('users_count')->counts('users')->label(__('Staff')),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
