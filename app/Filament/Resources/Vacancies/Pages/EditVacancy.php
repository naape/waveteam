<?php

namespace App\Filament\Resources\Vacancies\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Vacancies\VacancyResource;
use App\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditVacancy extends EditRecord
{
    protected static string $resource = VacancyResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Slug stays stable once created so links shared with applicants keep working.
        if (($data['status'] ?? null) === VacancyStatus::Published->value && ! $this->record->published_at) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        Audit::log('vacancy.updated', $this->record, new: ['status' => $this->record->status->value]);
    }
}
