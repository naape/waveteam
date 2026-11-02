<?php

namespace App\Filament\Resources\NotificationTemplates\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditNotificationTemplate extends EditRecord
{
    protected static string $resource = NotificationTemplateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->is_mandatory) {
            $data['is_enabled'] = true;
        }
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function afterSave(): void
    {
        Audit::log('notification_template.updated', $this->record, new: ['key' => $this->record->key, 'is_enabled' => $this->record->is_enabled]);
    }
}
