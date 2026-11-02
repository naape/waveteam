<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkforceAssignment extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'pcc_override', 'pcc_override_reason'];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'pcc_override' => 'boolean',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function applicantProfile(): BelongsTo
    {
        return $this->belongsTo(ApplicantProfile::class);
    }

    public function policeClearance(): BelongsTo
    {
        return $this->belongsTo(PoliceClearanceRecord::class, 'police_clearance_record_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
