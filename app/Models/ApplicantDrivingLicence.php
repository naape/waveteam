<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantDrivingLicence extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['licence_number'];

    protected function casts(): array
    {
        return [
            'licence_number' => 'encrypted',
            'vehicle_classes' => 'array',
            'expires_on' => 'date',
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
