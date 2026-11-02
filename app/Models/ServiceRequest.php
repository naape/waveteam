<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'reference', 'status', 'assigned_manager_id', 'show_team_to_client', 'completed_at', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'status' => ServiceRequestStatus::class,
            'start_date' => 'date',
            'requires_police_clearance' => 'boolean',
            'show_team_to_client' => 'boolean',
            'budget_amount' => 'decimal:2',
            'required_experience_years' => 'decimal:1',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_manager_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'service_request_skills')->withPivot('min_years');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ServiceRequestStatusHistory::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ServiceRequestAttachment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ServiceRequestMessage::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkforceAssignment::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference ??= static::nextReference();
        });
    }

    /** URLs use the human reference (SR-2026-00042), not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public static function nextReference(): string
    {
        $year = now()->year;
        $last = static::withTrashed()->where('reference', 'like', "SR-{$year}-%")->max('reference');
        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('SR-%d-%05d', $year, $seq);
    }

    /** Expected end date derived from start + duration (used by PCC eligibility). */
    public function expectedEndDate(): ?\Illuminate\Support\Carbon
    {
        if (! $this->start_date || ! $this->expected_duration_value) {
            return $this->start_date;
        }

        return match ($this->expected_duration_unit) {
            'weeks' => $this->start_date->copy()->addWeeks($this->expected_duration_value),
            'months' => $this->start_date->copy()->addMonths($this->expected_duration_value),
            default => $this->start_date->copy()->addDays($this->expected_duration_value),
        };
    }
}
