<?php

namespace App\Filament\Resources\Vacancies\Pages;

use App\Enums\VacancyStatus;
use App\Filament\Resources\Vacancies\VacancyResource;
use App\Models\JobVacancy;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateVacancy extends CreateRecord
{
    protected static string $resource = VacancyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = self::uniqueSlug($data['title']);
        $data['created_by'] = auth()->id();
        if (($data['status'] ?? null) === VacancyStatus::Published->value) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        Audit::log('vacancy.created', $this->record, new: ['title' => $this->record->title, 'status' => $this->record->status->value]);
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'vacancy';
        $slug = $base;
        $i = 2;
        while (JobVacancy::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
