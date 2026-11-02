<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\VacancyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobVacancy extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => VacancyStatus::class,
            'requires_police_clearance' => 'boolean',
            'closing_date' => 'date',
            'published_at' => 'datetime',
            'min_experience_years' => 'decimal:1',
        ];
    }

    public function jobCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function districts(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'job_vacancy_districts');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', VacancyStatus::Published)
            ->where(fn ($q) => $q->whereNull('closing_date')->orWhere('closing_date', '>=', today()));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
