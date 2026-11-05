<?php

namespace App\Models;

use App\Enums\PccVerificationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded police clearance certificate. Full history is kept; a
 * certificate is only ever "valid" after an authorised admin approves it.
 */
class PoliceClearanceRecord extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'verification_status', 'verified_by', 'verified_at', 'valid_until', 'next_renewal_due'];

    protected $hidden = ['certificate_number'];

    protected function casts(): array
    {
        return [
            'certificate_number' => 'encrypted',
            'issue_date' => 'date',
            'uploaded_at' => 'datetime',
            'verification_status' => PccVerificationStatus::class,
            'valid_until' => 'date',
            'next_renewal_due' => 'date',
            'verified_at' => 'datetime',
            'superseded_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function applicantProfile(): BelongsTo
    {
        return $this->belongsTo(ApplicantProfile::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicantDocument::class, 'applicant_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PoliceClearanceReminder::class);
    }

    /** The date by which the applicant must renew (admin may set it earlier than expiry). */
    public function renewalDate(): ?Carbon
    {
        return $this->next_renewal_due ?? $this->valid_until;
    }

    /**
     * Display status is derived (never stored) so it can never go stale.
     */
    public function displayStatus(?Carbon $today = null): PoliceClearanceStatus
    {
        $today ??= today();

        return match ($this->verification_status) {
            PccVerificationStatus::Rejected => PoliceClearanceStatus::Rejected,
            PccVerificationStatus::PendingVerification => PoliceClearanceStatus::PendingVerification,
            PccVerificationStatus::Approved => $this->approvedStatus($today),
        };
    }

    private function approvedStatus(Carbon $today): PoliceClearanceStatus
    {
        $due = $this->renewalDate();

        if ($due === null || $today->gt($due)) {
            return PoliceClearanceStatus::Expired;
        }

        $window = (int) Settings::get('pcc.expiring_soon_days');

        return $today->gte($due->copy()->subDays($window))
            ? PoliceClearanceStatus::ExpiringSoon
            : PoliceClearanceStatus::Valid;
    }

    /** Approved and still in force on the given date (used by matching/assignments). */
    public function isValidOn(Carbon $date): bool
    {
        return $this->verification_status === PccVerificationStatus::Approved
            && $this->renewalDate() !== null
            && $date->lte($this->renewalDate());
    }

    /** Suggested expiry when an admin approves (they may override). */
    public function suggestedValidUntil(): Carbon
    {
        return $this->issue_date->copy()->addMonths((int) Settings::get('pcc.default_validity_months'));
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('verification_status', PccVerificationStatus::Approved);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('verification_status', PccVerificationStatus::PendingVerification);
    }

    /** Approved, not superseded, renewal due within N days (or already past). */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->approved()
            ->whereNull('superseded_at')
            ->whereRaw('COALESCE(next_renewal_due, valid_until) <= ?', [today()->addDays($days)->toDateString()]);
    }
}
