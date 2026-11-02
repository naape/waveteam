<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCategory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function applicants(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ApplicantProfile::class, 'applicant_job_categories');
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(JobVacancy::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }
}
