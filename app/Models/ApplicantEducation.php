<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantEducation extends Model
{
    use HasFactory;

    // "education" is uncountable to Laravel's pluraliser, so name the table explicitly.
    protected $table = 'applicant_educations';

    protected $guarded = ['id', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function applicantProfile(): BelongsTo
    {
        return $this->belongsTo(ApplicantProfile::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicantDocument::class, 'document_id');
    }
}
