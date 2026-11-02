<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a Filament resource's abilities to spatie permission names.
 * A resource declares, e.g.:
 *
 *   protected static array $permissions = ['view' => 'applicants.view', 'edit' => 'applicants.update'];
 *
 * Any ability not listed is denied (deny by default).
 */
trait AuthorizesWithPermissions
{
    protected static function allows(string $ability): bool
    {
        $permission = static::$permissions[$ability] ?? null;

        return $permission !== null && (bool) auth()->user()?->can($permission);
    }

    public static function canViewAny(): bool
    {
        return static::allows('view');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('view');
    }

    public static function canCreate(): bool
    {
        return static::allows('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allows('edit');
    }

    public static function canDelete(Model $record): bool
    {
        return static::allows('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::allows('delete');
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }
}
