<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\PoliceClearanceStatus;
use App\Enums\VerificationStatus;
use App\Support\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApplicantProfile extends Model
{
    use HasFactory, SoftDeletes;

    /** Verification / status / pointer fields are set only by admin actions. */
    protected $guarded = [
        'id', 'user_id', 'nic_hash', 'verification_status', 'verified_by', 'verified_at',
        'applicant_status', 'current_police_clearance_id', 'profile_completion',
    ];

    protected $hidden = ['nic_number', 'nic_hash', 'passport_number'];

    protected function casts(): array
    {
        return [
            'nic_number' => 'encrypted',
            'passport_number' => 'encrypted',
            'date_of_birth' => 'date',
            'available_from' => 'date',
            'verified_at' => 'datetime',
            'total_experience_years' => 'decimal:1',
            'availability_status' => AvailabilityStatus::class,
            'verification_status' => VerificationStatus::class,
            'applicant_status' => ApplicantStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            if ($profile->isDirty('nic_number')) {
                $profile->nic_number = BlindIndex::normalizeNic($profile->nic_number);
                $profile->nic_hash = BlindIndex::nic($profile->nic_number);
            }
        });
    }

    public static function findByNic(string $nic): ?self
    {
        return static::where('nic_hash', BlindIndex::nic($nic))->first();
    }

    /** Masked NIC for display to users without full PII permission. */
    public function maskedNic(): ?string
    {
        $nic = $this->nic_number;

        return $nic ? str_repeat('•', max(strlen($nic) - 4, 0)).substr($nic, -4) : null;
    }

    // ---- Relationships ----------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'photo_file_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(ApplicantEducation::class)->orderBy('sort');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(ApplicantCertification::class)->orderBy('sort');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'applicant_skills')
            ->using(ApplicantSkill::class)
            ->withPivot(['years', 'level'])
            ->withTimestamps();
    }

    public function jobCategories(): BelongsToMany
    {
        return $this->belongsToMany(JobCategory::class, 'applicant_job_categories');
    }

    public function preferredDistricts(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'applicant_preferred_districts');
    }

    public function employments(): HasMany
    {
        return $this->hasMany(ApplicantEmployment::class)->orderBy('sort');
    }

    public function languages(): HasMany
    {
        return $this->hasMany(ApplicantLanguage::class);
    }

    public function drivingLicences(): HasMany
    {
        return $this->hasMany(ApplicantDrivingLicence::class);
    }

    public function militaryServices(): HasMany
    {
        return $this->hasMany(ApplicantMilitaryService::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function policeClearanceRecords(): HasMany
    {
        return $this->hasMany(PoliceClearanceRecord::class)->latest('uploaded_at');
    }

    public function currentPoliceClearance(): BelongsTo
    {
        return $this->belongsTo(PoliceClearanceRecord::class, 'current_police_clearance_id');
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkforceAssignment::class);
    }

    // ---- Police clearance -------------------------------------------------------

    /**
     * Overall status shown on dashboards. A pending upload is surfaced first so
     * the applicant sees it is awaiting review, otherwise the current approved one.
     */
    public function policeClearanceStatus(): PoliceClearanceStatus
    {
        $latest = $this->policeClearanceRecords()->whereNull('superseded_at')->first();

        if ($latest === null) {
            return PoliceClearanceStatus::Missing;
        }

        if ($latest->id !== $this->current_police_clearance_id && $this->currentPoliceClearance) {
            // A newer upload is pending/rejected while an approved one still exists.
            $current = $this->currentPoliceClearance->displayStatus();

            return $current === PoliceClearanceStatus::Valid ? $current : $latest->displayStatus();
        }

        return $latest->displayStatus();
    }

    // ---- Scopes -----------------------------------------------------------------

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', VerificationStatus::Verified);
    }

    /** Filter by police clearance status (mirrors policeClearanceStatus()). */
    public function scopeWherePccStatus(Builder $query, PoliceClearanceStatus|string $status): Builder
    {
        $status = $status instanceof PoliceClearanceStatus ? $status : PoliceClearanceStatus::from($status);
        $today = today()->toDateString();
        $soon = today()->addDays((int) \App\Support\Settings::get('pcc.expiring_soon_days'))->toDateString();
        $due = 'COALESCE(next_renewal_due, valid_until)';
        $approved = fn ($q) => $q->where('verification_status', 'approved');

        return match ($status) {
            PoliceClearanceStatus::Valid => $query->whereHas('currentPoliceClearance', fn ($q) => $approved($q)->whereRaw("{$due} > ?", [$soon])),
            PoliceClearanceStatus::ExpiringSoon => $query->whereHas('currentPoliceClearance', fn ($q) => $approved($q)->whereRaw("{$due} between ? and ?", [$today, $soon])),
            PoliceClearanceStatus::Expired => $query->whereHas('currentPoliceClearance', fn ($q) => $approved($q)->whereRaw("{$due} < ?", [$today])),
            PoliceClearanceStatus::PendingVerification => $query->whereHas('policeClearanceRecords', fn ($q) => $q->where('verification_status', 'pending_verification')->whereNull('superseded_at')),
            PoliceClearanceStatus::Rejected => $query->whereNull('current_police_clearance_id')->whereHas('policeClearanceRecords', fn ($q) => $q->where('verification_status', 'rejected')->whereNull('superseded_at')),
            PoliceClearanceStatus::Missing => $query->whereDoesntHave('policeClearanceRecords'),
        };
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('applicant_status', ApplicantStatus::Active);
    }
}
