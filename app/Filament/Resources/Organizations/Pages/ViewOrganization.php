<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Enums\UserType;
use App\Filament\Actions\PasswordActions;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Actions\ActionGroup;
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
        $contacts = fn (Organization $r) => $r->members->pluck('user')->filter(fn ($u) => $u?->user_type === UserType::OrgContact)->values();
        $canManage = fn () => auth()->user()->can('organizations.manage');

        return [
            ...OrganizationResource::statusActions(),
            EditAction::make(),
            ActionGroup::make([
                PasswordActions::set($contacts, $canManage),
                PasswordActions::resetLink($contacts, $canManage),
            ])->label(__('Contact password'))->icon(\Filament\Support\Icons\Heroicon::OutlinedKey)->button()->color('gray'),
        ];
    }
}
