<?php

namespace App\Support\Workforce;

use App\Enums\AccountStatus;
use App\Enums\ApplicantStatus;
use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\VerificationStatus;
use App\Models\ApplicantProfile;
use App\Models\JobCategory;
use App\Models\ServiceRequest;
use App\Support\Compliance\PoliceClearanceEligibility;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Finds and ranks applicants for a service request.
 *
 * 1. HARD FILTERS (SQL) — a candidate must pass all of these:
 *    - active account, active applicant, VERIFIED profile;
 *    - in a job category that can deliver the service category;
 *    - available on the start date (or from a date on/before it);
 *    - no overlapping proposed/confirmed/active assignment;
 *    - police clearance in force for the whole period, when the request
 *      requires it (PoliceClearanceEligibility — the Phase 7 rule);
 *    - optionally: meets the minimum experience / willing to work in the district.
 *
 * 2. SCORE (0–100) using the admin-configurable weights:
 *    skills · experience · qualifications · location · profile completeness.
 *    Every factor produces a plain-English reason so staff can see *why*.
 */
class WorkforceMatcher
{
    /** @var array<string, bool> */
    public const DEFAULT_OPTIONS = [
        'strict_experience' => true,
        'district_only' => false,
    ];

    /** @return Collection<int, MatchResult> */
    public function match(ServiceRequest $request, array $options = [], int $limit = 50): Collection
    {
        $options += self::DEFAULT_OPTIONS;
        $request->loadMissing(['skills', 'district', 'serviceCategory']);
        [$start, $end] = self::period($request);

        $candidates = $this->candidateQuery($request, $start, $end, $options)
            ->with(['skills', 'preferredDistricts:id', 'district:id,province', 'currentPoliceClearance', 'user:id,status'])
            ->withCount(['certifications', 'educations'])
            ->get();

        $weights = Settings::get('matching.weights');

        return $candidates
            ->map(fn (ApplicantProfile $p) => $this->score($p, $request, $weights, $start, $end))
            ->sortByDesc(fn (MatchResult $r) => [$r->score, $r->profile->total_experience_years])
            ->take($limit)
            ->values();
    }

    /** The assignment period implied by the request: [start, end]. */
    public static function period(ServiceRequest $request): array
    {
        $start = ($request->start_date && $request->start_date->isFuture()) ? $request->start_date->copy() : today();
        $end = $request->expectedEndDate();
        $end = ($end && $end->gte($start)) ? $end->copy() : $start->copy()->addMonth();

        return [$start, $end];
    }

    public function candidateQuery(ServiceRequest $request, Carbon $start, Carbon $end, array $options = []): Builder
    {
        $options += self::DEFAULT_OPTIONS;

        $query = ApplicantProfile::query()
            ->where('verification_status', VerificationStatus::Verified)
            ->where('applicant_status', ApplicantStatus::Active)
            ->whereHas('user', fn ($q) => $q->where('status', AccountStatus::Active))
            // Availability on the start date.
            ->where(fn ($q) => $q
                ->whereIn('availability_status', [AvailabilityStatus::Available, AvailabilityStatus::Engaged])
                ->orWhere(fn ($q) => $q->where('availability_status', AvailabilityStatus::AvailableFrom)->whereDate('available_from', '<=', $start)))
            // No clashing assignment (engaged people are fine if free for this period).
            ->whereDoesntHave('assignments', fn ($q) => self::overlapping($q, $start, $end))
            // Not already on this request.
            ->whereDoesntHave('assignments', fn ($q) => $q->where('service_request_id', $request->id)
                ->whereNotIn('status', [AssignmentStatus::Cancelled, AssignmentStatus::Released]));

        $categoryIds = $this->jobCategoryIds($request);
        if ($categoryIds !== null) {
            $query->whereHas('jobCategories', fn ($q) => $q->whereIn('job_categories.id', $categoryIds));
        }

        if ($request->requires_police_clearance) {
            PoliceClearanceEligibility::scope($query, $end);
        }

        if ($options['strict_experience'] && (float) $request->required_experience_years > 0) {
            $query->where('total_experience_years', '>=', (float) $request->required_experience_years);
        }

        if ($options['district_only'] && $request->district_id) {
            $query->where(fn ($q) => $q->where('district_id', $request->district_id)
                ->orWhereHas('preferredDistricts', fn ($d) => $d->whereKey($request->district_id)));
        }

        return $query;
    }

    /** Assignments overlapping [start, end] that still hold the person. */
    public static function overlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereIn('status', [AssignmentStatus::Proposed, AssignmentStatus::Confirmed, AssignmentStatus::Active])
            ->whereDate('start_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start));
    }

    /** @return array<int,int>|null null = any category */
    public function jobCategoryIds(ServiceRequest $request): ?array
    {
        $slugs = config('portal.service_category_job_categories.'.$request->serviceCategory?->slug);

        return empty($slugs) ? null : JobCategory::whereIn('slug', $slugs)->pluck('id')->all();
    }

    private function score(ApplicantProfile $p, ServiceRequest $request, array $weights, Carbon $start, Carbon $end): MatchResult
    {
        $reasons = [];
        $warnings = [];
        $b = [];

        // --- Skills -------------------------------------------------------------
        $required = $request->skills;
        $have = $p->skills->keyBy('id');
        if ($required->isNotEmpty()) {
            $points = 0.0;
            $matched = [];
            foreach ($required as $skill) {
                $mine = $have->get($skill->id);
                if (! $mine) {
                    continue;
                }
                $need = (float) $skill->pivot->min_years;
                $full = (float) $mine->pivot->years >= $need;
                $points += $full ? 1 : 0.5;
                $matched[] = $skill->name.($full ? '' : ' ('.__('below :y yrs', ['y' => $need]).')');
            }
            $b['skills'] = $points / $required->count();
            $reasons[] = $matched
                ? __('Skills: :m of :n required (:list)', ['m' => count($matched), 'n' => $required->count(), 'list' => implode(', ', $matched)])
                : __('Skills: none of the :n required skills listed', ['n' => $required->count()]);
        } else {
            $b['skills'] = $p->skills->isNotEmpty() ? min(1, 0.5 + $p->skills->count() * 0.1) : 0.3;
            $reasons[] = __('Skills: :n listed (no specific skills requested)', ['n' => $p->skills->count()]);
        }

        // --- Experience -----------------------------------------------------------
        $years = (float) $p->total_experience_years;
        $need = (float) $request->required_experience_years;
        $b['experience'] = $need > 0 ? min(1, $years / $need) : min(1, $years / 5);
        $reasons[] = $need > 0
            ? __('Experience: :y yrs (needs :n)', ['y' => $years, 'n' => $need])
            : __('Experience: :y yrs', ['y' => $years]);
        if ($need > 0 && $years < $need) {
            $warnings[] = __('Below the requested experience.');
        }

        // --- Qualifications -------------------------------------------------------
        $b['qualifications'] = min(1, ($p->certifications_count > 0 ? 0.6 : 0) + ($p->educations_count > 0 ? 0.4 : 0));
        $reasons[] = __('Qualifications: :c certification(s), :e education entr(ies)', ['c' => $p->certifications_count, 'e' => $p->educations_count]);

        // --- Location ---------------------------------------------------------------
        $districtId = $request->district_id;
        $b['location'] = match (true) {
            $districtId && $p->preferredDistricts->contains('id', $districtId) => 1.0,
            $districtId && $p->district_id === $districtId => 0.8,
            $districtId && $p->district?->province && $p->district->province === $request->district?->province => 0.5,
            default => 0.0,
        };
        $reasons[] = match ($b['location']) {
            1.0 => __('Location: willing to work in :d', ['d' => $request->district?->name_en]),
            0.8 => __('Location: lives in :d', ['d' => $request->district?->name_en]),
            0.5 => __('Location: same province'),
            default => __('Location: outside preferred areas'),
        };

        // --- Completeness -----------------------------------------------------------
        $b['completeness'] = $p->profile_completion / 100;

        // --- Police clearance note (eligibility already enforced as a hard filter)
        if ($request->requires_police_clearance) {
            $due = $p->currentPoliceClearance?->renewalDate();
            $reasons[] = __('Police clearance valid until :d', ['d' => $due?->format('j M Y')]);
            if ($due && $due->lt($end->copy()->addDays(30))) {
                $warnings[] = __('Police clearance renewal falls soon after the assignment.');
            }
        }

        if ($p->availability_status === AvailabilityStatus::Engaged) {
            $warnings[] = __('Currently on another assignment (free for these dates).');
        }

        $total = 0.0;
        foreach (['skills', 'experience', 'qualifications', 'location', 'completeness'] as $k) {
            $total += ($b[$k] ?? 0) * (float) ($weights[$k] ?? 0);
        }

        return new MatchResult($p, (int) round($total), $b, $reasons, $warnings);
    }
}
