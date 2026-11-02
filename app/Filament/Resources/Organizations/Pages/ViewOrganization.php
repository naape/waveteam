<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    /** Eager-load everything the page shows (strict mode forbids lazy loading). */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['members.user', 'documents.file', 'serviceRequests']);
    }

    protected function getHeaderActions(): array
    {
        return [...OrganizationResource::statusActions(), EditAction::make()];
    }
}
