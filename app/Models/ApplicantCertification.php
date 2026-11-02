<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantCertification extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
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
