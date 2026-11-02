<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function applicants(): HasMany
    {
        return $this->hasMany(ApplicantProfile::class);
    }

    public function localizedName(): string
    {
        return match (app()->getLocale()) {
            'si' => $this->name_si ?: $this->name_en,
            'ta' => $this->name_ta ?: $this->name_en,
            default => $this->name_en,
        };
    }
}
