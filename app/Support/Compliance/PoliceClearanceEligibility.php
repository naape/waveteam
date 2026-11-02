<?php

namespace App\Support\Compliance;

use App\Enums\ApplicantStatus;
use App\Enums\PccVerificationStatus;
use App\Models\ApplicantProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Can this person be placed on a site that requires police clearance until
 * this date?" — the single rule used by matching and assignment (Phase 8).
 *
 * Eligible only if the certificate in force is approved and its renewal date
 * covers the whole period. An expired certificate therefore makes an
 * applicant ineligible automatically — no batch job has to flip a flag.
 */
class PoliceClearanceEligibility
{
    public static function isEligible(ApplicantProfile $profile, Carbon $until): bool
    {
        $record = $profile->currentPoliceClearance;

        return $profile->applicant_status === ApplicantStatus::Active
            && $record !== null
            && $record->superseded_at === null
            && $record->isValidOn($until)
            && $record->isValidOn(today());
    }

    /** Explains why not, for the UI. */
    public static function reason(ApplicantProfile $profile, Carbon $until): ?string
    {
        $record = $profile->currentPoliceClearance;

        return match (true) {
            $profile->applicant_status !== ApplicantStatus::Active => __('Applicant is :s.', ['s' => $profile->applicant_status->getLabel()]),
            $record === null => __('No verified police clearance certificate.'),
            ! $record->isValidOn(today()) => __('Police clearance expired on :d.', ['d' => $record->renewalDate()?->format('j M Y')]),
            ! $record->isValidOn($until) => __('Police clearance must be renewed by :d, before the assignment ends.', ['d' => $record->renewalDate()?->format('j M Y')]),
            default => null,
        };
    }

    /** Query scope equivalent, for filtering candidate lists in SQL. */
    public static function scope(Builder $query, Carbon $until): Builder
    {
        $date = max($until, today())->toDateString();

        return $query->where('applicant_status', ApplicantStatus::Active)
            ->whereHas('currentPoliceClearance', fn ($q) => $q
                ->where('verification_status', PccVerificationStatus::Approved)
                ->whereNull('superseded_at')
                ->whereRaw('COALESCE(next_renewal_due, valid_until) >= ?', [$date]));
    }
}
