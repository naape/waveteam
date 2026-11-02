<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = Arr::only($this->record->getOriginal(), ['name', 'organization_type', 'registration_number', 'email', 'phone', 'address', 'billing_address']);
    }

    protected function afterSave(): void
    {
        Audit::log('organization.updated_by_staff', $this->record, $this->before, Arr::only($this->record->getAttributes(), array_keys($this->before)));
    }
}
