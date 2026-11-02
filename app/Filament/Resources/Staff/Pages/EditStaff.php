<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = [
            'name' => $this->record->name,
            'email' => $this->record->email,
            'roles' => $this->record->roles()->pluck('name')->sort()->values()->all(),
        ];
    }

    protected function afterSave(): void
    {
        $after = [
            'name' => $this->record->name,
            'email' => $this->record->email,
            'roles' => $this->record->roles()->pluck('name')->sort()->values()->all(),
        ];

        if ($after !== $this->before) {
            Audit::log('staff.updated', $this->record, $this->before, $after);
        }
    }
}
