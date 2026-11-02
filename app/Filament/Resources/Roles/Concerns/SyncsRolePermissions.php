<?php

namespace App\Filament\Resources\Roles\Concerns;

use App\Support\Audit;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The form splits permissions into one checkbox list per module; merge them
 * back into a single set and sync, with an audit entry of the difference.
 */
trait SyncsRolePermissions
{
    protected array $permissionIds = [];

    protected function extractPermissions(array $data): array
    {
        $this->permissionIds = collect($data)
            ->filter(fn ($v, $k) => str_starts_with($k, 'permissions_'))
            ->flatten()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return collect($data)->reject(fn ($v, $k) => str_starts_with($k, 'permissions_'))->all();
    }

    protected function fillPermissions(array $data): array
    {
        $assigned = $this->record->permissions->pluck('id')->all();
        foreach (Permission::all() as $p) {
            $key = 'permissions_'.Str::slug(Str::of($p->name)->before('.')->replace('_', ' ')->title()->toString(), '_');
            if (in_array($p->id, $assigned, true)) {
                $data[$key][] = $p->id;
            }
        }

        return $data;
    }

    protected function syncPermissions(string $action): void
    {
        $before = $this->record->permissions->pluck('name')->sort()->values()->all();
        $this->record->syncPermissions(Permission::whereIn('id', $this->permissionIds)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = $this->record->fresh('permissions')->permissions->pluck('name')->sort()->values()->all();

        Audit::log($action, $this->record, ['permissions' => $before], ['permissions' => $after]);
    }
}
