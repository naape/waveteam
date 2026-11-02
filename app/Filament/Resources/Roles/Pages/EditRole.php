<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Concerns\SyncsRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    use SyncsRolePermissions;

    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillPermissions($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractPermissions($data);
    }

    protected function afterSave(): void
    {
        $this->syncPermissions('role.updated');
    }
}
