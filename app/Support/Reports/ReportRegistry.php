<?php

namespace App\Support\Reports;

use App\Enums\ApplicantStatus;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\PoliceClearanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VerificationStatus;
use App\Models\ApplicantProfile;
use App\Models\District;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\Organization;
use App\Models\PoliceClearanceRecord;
use App\Models\ServiceRequest;
use App\Models\WorkforceAssignment;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The 12 reports required by the brief. Personal identifiers (NIC,
 * passport) are deliberately never included in any report.
 */
class ReportRegistry
{
    /** Filter keys a report may declare. */
    public const FILTERS = ['from', 'to', 'job_category_id', 'district_id', 'status', 'days'];

    /** @return array<string, Report> */
    public static function all(): array
    {
        $reports = [
            self::applicants(),
            self::applicantsByCategory(),
            self::applicantsByDistrict(),
            self::verification(),
            self::policeClearance(),
            self::upcomingRenewals(),
            self::jobApplications(),
            self::selectedApplicants(),
            self::organizations(),
            self::serviceRequests(),
            self::completedProjects(),
            self::assignments(),
        ];

        return collect($reports)->keyBy('key')->all();
    }

    public static function get(string $key): Report
    {
        return self::all()[$key] ?? abort(404);
    }

    /** Select options for status filters, per report. */
    public static function statusOptions(string $key): array
    {
        return match ($key) {
            'applicants', 'verification' => VerificationStatus::options(),
            'police_clearance' => PoliceClearanceStatus::options(),
            'job_applications' => ApplicationStatus::options(),
            'organizations' => OrganizationStatus::options(),
            'service_requests' => ServiceRequestStatus::options(),
            'assignments' => AssignmentStatus::options(),
            default => [],
        };
    }

    // ---- helpers ---------------------------------------------------------------

    private static function d(?Carbon $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function dateRange(Builder $q, array $f, string $column): Builder
    {
        return $q
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate($column, '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate($column, '<=', $v));
    }

    private static function applicantQuery(array $f): Builder
    {
        $q = ApplicantProfile::query()
            ->when($f['job_category_id'] ?? null, fn ($q, $v) => $q->whereHas('jobCategories', fn ($c) => $c->whereKey($v)))
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v));

        return self::dateRange($q, $f, 'applicant_profiles.created_at');
    }

    /**
     * Lazily stream models through a mapper (chunked, memory-safe). Uses
     * offset chunks (not lazyById) so a report's own ordering is preserved.
     */
    private static function stream(Builder $q, callable $map): iterable
    {
        foreach ($q->lazy(500) as $model) {
            yield $map($model);
        }
    }

    // ---- 1. Total registered applicants --------------------------------------

    private static function applicants(): Report
    {
        $q = fn (array $f) => self::applicantQuery($f)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v));

        return new Report(
            'applicants', __('Registered applicants'), __('All applicants with contact, trade and status.'),
            [
                'name' => __('Name'), 'email' => __('Email'), 'mobile' => __('Mobile'), 'district' => __('District'),
                'categories' => __('Job categories'), 'experience' => __('Experience (yrs)'), 'completion' => __('Profile %'),
                'verification' => __('Verification'), 'status' => __('Status'), 'pcc' => __('Police clearance'), 'registered' => __('Registered'),
            ],
            ['from', 'to', 'job_category_id', 'district_id', 'status'],
            fn (array $f) => self::stream($q($f)->with(['user:id,email', 'district:id,name_en', 'jobCategories:id,name', 'currentPoliceClearance']), fn (ApplicantProfile $p) => [
                'name' => $p->full_name, 'email' => $p->user?->email, 'mobile' => $p->contact_number,
                'district' => $p->district?->name_en, 'categories' => $p->jobCategories->pluck('name')->implode(', '),
                'experience' => (float) $p->total_experience_years, 'completion' => $p->profile_completion,
                'verification' => $p->verification_status->getLabel(), 'status' => $p->applicant_status->getLabel(),
                'pcc' => $p->policeClearanceStatus()->getLabel(), 'registered' => self::d($p->created_at),
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => [
                __('Total') => self::applicantQuery($f)->count(),
                __('Verified') => self::applicantQuery($f)->where('verification_status', VerificationStatus::Verified)->count(),
                __('Active') => self::applicantQuery($f)->where('applicant_status', ApplicantStatus::Active)->count(),
                __('Registered this month') => ApplicantProfile::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        );
    }

    // ---- 2. Applicants by job category ----------------------------------------

    private static function applicantsByCategory(): Report
    {
        $rows = function (array $f) {
            $base = self::applicantQuery($f)->select('applicant_profiles.id');

            return JobCategory::query()->orderBy('sort')->get()->map(function (JobCategory $c) use ($base) {
                $ids = (clone $base)->whereHas('jobCategories', fn ($q) => $q->whereKey($c->id));

                return [
                    'category' => $c->name,
                    'total' => (clone $ids)->count(),
                    'verified' => (clone $ids)->where('verification_status', VerificationStatus::Verified)->count(),
                    'pcc_valid' => (clone $ids)->wherePccStatus(PoliceClearanceStatus::Valid)->count(),
                    'available' => (clone $ids)->where('availability_status', 'available')->count(),
                ];
            });
        };

        return new Report(
            'applicants_by_category', __('Applicants by job category'), __('Headcount per trade, with verified, cleared and available numbers. An applicant can appear in several categories.'),
            ['category' => __('Job category'), 'total' => __('Applicants'), 'verified' => __('Verified'), 'pcc_valid' => __('Clearance valid'), 'available' => __('Available now')],
            ['from', 'to', 'district_id'],
            $rows,
            fn (array $f) => JobCategory::count(),
            orientation: 'portrait',
        );
    }

    // ---- 3. Applicants by district ---------------------------------------------

    private static function applicantsByDistrict(): Report
    {
        $rows = function (array $f) {
            $counts = self::applicantQuery($f)->toBase()
                ->select('district_id', DB::raw('count(*) as total'),
                    DB::raw("sum(verification_status = 'verified') as verified"))
                ->groupBy('district_id')->get()->keyBy('district_id');

            return District::orderBy('province')->orderBy('name_en')->get()->map(fn (District $d) => [
                'district' => $d->name_en,
                'province' => $d->province,
                'total' => (int) ($counts[$d->id]->total ?? 0),
                'verified' => (int) ($counts[$d->id]->verified ?? 0),
            ])->push([
                'district' => __('Not specified'), 'province' => '—',
                'total' => (int) ($counts['']->total ?? 0), 'verified' => (int) ($counts['']->verified ?? 0),
            ]);
        };

        return new Report(
            'applicants_by_district', __('Applicants by district'), __('Where applicants live, by district and province.'),
            ['district' => __('District'), 'province' => __('Province'), 'total' => __('Applicants'), 'verified' => __('Verified')],
            ['from', 'to', 'job_category_id'],
            $rows,
            fn (array $f) => District::count() + 1,
            orientation: 'portrait',
        );
    }

    // ---- 4. Verified and unverified applicants ---------------------------------

    private static function verification(): Report
    {
        $q = fn (array $f) => self::applicantQuery($f)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->orderBy('verification_status');

        return new Report(
            'verification', __('Verified and unverified applicants'), __('Profile verification status, who verified and when.'),
            ['name' => __('Name'), 'email' => __('Email'), 'verification' => __('Verification'), 'verified_by' => __('Verified by'), 'verified_at' => __('Verified on'), 'completion' => __('Profile %'), 'registered' => __('Registered')],
            ['from', 'to', 'job_category_id', 'district_id', 'status'],
            fn (array $f) => self::stream($q($f)->with(['user:id,email', 'verifier:id,name']), fn (ApplicantProfile $p) => [
                'name' => $p->full_name, 'email' => $p->user?->email, 'verification' => $p->verification_status->getLabel(),
                'verified_by' => $p->verifier?->name, 'verified_at' => self::d($p->verified_at),
                'completion' => $p->profile_completion, 'registered' => self::d($p->created_at),
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => collect(VerificationStatus::cases())->mapWithKeys(fn ($s) => [
                $s->getLabel() => self::applicantQuery($f)->where('verification_status', $s)->count(),
            ])->all(),
        );
    }

    // ---- 5. Valid and expired police clearance ---------------------------------

    private static function policeClearance(): Report
    {
        $q = fn (array $f) => self::applicantQuery($f)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->wherePccStatus($v));

        return new Report(
            'police_clearance', __('Police clearance status'), __('Every applicant’s clearance status, with issue and renewal dates.'),
            ['name' => __('Name'), 'mobile' => __('Mobile'), 'status' => __('Clearance status'), 'issued' => __('Issued'), 'renewal' => __('Renewal due'), 'verified_by' => __('Verified by')],
            ['job_category_id', 'district_id', 'status'],
            fn (array $f) => self::stream($q($f)->with(['currentPoliceClearance.verifier:id,name']), fn (ApplicantProfile $p) => [
                'name' => $p->full_name, 'mobile' => $p->contact_number, 'status' => $p->policeClearanceStatus()->getLabel(),
                'issued' => self::d($p->currentPoliceClearance?->issue_date), 'renewal' => self::d($p->currentPoliceClearance?->renewalDate()),
                'verified_by' => $p->currentPoliceClearance?->verifier?->name,
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => collect(PoliceClearanceStatus::cases())->mapWithKeys(fn ($s) => [
                $s->getLabel() => self::applicantQuery($f)->wherePccStatus($s)->count(),
            ])->all(),
        );
    }

    // ---- 6. Upcoming police clearance renewals ---------------------------------

    private static function upcomingRenewals(): Report
    {
        $q = function (array $f) {
            $days = max(1, min(365, (int) ($f['days'] ?? 60)));

            return PoliceClearanceRecord::query()
                ->dueWithin($days)
                ->whereRaw('COALESCE(next_renewal_due, valid_until) >= ?', [today()->toDateString()])
                ->whereHas('applicantProfile', fn ($p) => $p
                    ->whereColumn('applicant_profiles.current_police_clearance_id', 'police_clearance_records.id')
                    ->when($f['job_category_id'] ?? null, fn ($q, $v) => $q->whereHas('jobCategories', fn ($c) => $c->whereKey($v)))
                    ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v)))
                ->orderByRaw('COALESCE(next_renewal_due, valid_until)');
        };

        return new Report(
            'upcoming_renewals', __('Upcoming police clearance renewals'), __('Certificates in force that are due for renewal in the coming days.'),
            ['name' => __('Name'), 'mobile' => __('Mobile'), 'email' => __('Email'), 'renewal' => __('Renewal due'), 'days_left' => __('Days left'), 'renewal_uploaded' => __('Renewal uploaded?')],
            ['days', 'job_category_id', 'district_id'],
            fn (array $f) => (function () use ($q, $f) {
                foreach ($q($f)->with(['applicantProfile.user:id,email', 'applicantProfile.policeClearanceRecords'])->lazy(500) as $r) {
                    $p = $r->applicantProfile;
                    yield [
                        'name' => $p->full_name, 'mobile' => $p->contact_number, 'email' => $p->user?->email,
                        'renewal' => self::d($r->renewalDate()), 'days_left' => (int) today()->diffInDays($r->renewalDate(), false),
                        'renewal_uploaded' => $p->policeClearanceRecords->contains(fn ($x) => $x->verification_status->value === 'pending_verification' && ! $x->superseded_at) ? __('Yes') : __('No'),
                    ];
                }
            })(),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => [
                __('Due in 7 days') => $q(['days' => 7] + $f)->count(),
                __('Due in 30 days') => $q(['days' => 30] + $f)->count(),
                __('Due in :n days', ['n' => (int) ($f['days'] ?? 60)]) => $q($f)->count(),
                __('Already expired') => ApplicantProfile::query()->wherePccStatus(PoliceClearanceStatus::Expired)->count(),
            ],
        );
    }

    // ---- 7. Job applications ----------------------------------------------------

    private static function applicationQuery(array $f): Builder
    {
        $q = JobApplication::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['job_category_id'] ?? null, fn ($q, $v) => $q->whereHas('vacancy', fn ($j) => $j->where('job_category_id', $v)))
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->whereHas('applicantProfile', fn ($p) => $p->where('district_id', $v)));

        return self::dateRange($q, $f, 'submitted_at');
    }

    private static function applicationRow(JobApplication $a): array
    {
        return [
            'applicant' => $a->applicantProfile->full_name, 'email' => $a->applicantProfile->user?->email,
            'vacancy' => $a->vacancy->title, 'category' => $a->vacancy->jobCategory?->name,
            'submitted' => self::d($a->submitted_at), 'status' => $a->status->getLabel(),
        ];
    }

    private static function jobApplications(): Report
    {
        return new Report(
            'job_applications', __('Job applications'), __('Applications received, by vacancy and status.'),
            ['applicant' => __('Applicant'), 'email' => __('Email'), 'vacancy' => __('Vacancy'), 'category' => __('Category'), 'submitted' => __('Applied'), 'status' => __('Status')],
            ['from', 'to', 'job_category_id', 'district_id', 'status'],
            fn (array $f) => self::stream(self::applicationQuery($f)->with(['applicantProfile.user:id,email', 'vacancy.jobCategory']), fn ($a) => self::applicationRow($a)),
            fn (array $f) => self::applicationQuery($f)->count(),
            fn (array $f) => collect(ApplicationStatus::cases())->mapWithKeys(fn ($s) => [
                $s->getLabel() => self::applicationQuery(['status' => $s->value] + $f)->count(),
            ])->all(),
        );
    }

    // ---- 8. Selected applicants ---------------------------------------------------

    private static function selectedApplicants(): Report
    {
        $q = fn (array $f) => self::applicationQuery(['status' => ApplicationStatus::Selected->value] + $f);

        return new Report(
            'selected_applicants', __('Selected applicants'), __('Applicants selected for vacancies.'),
            ['applicant' => __('Applicant'), 'email' => __('Email'), 'vacancy' => __('Vacancy'), 'category' => __('Category'), 'submitted' => __('Applied'), 'status' => __('Status')],
            ['from', 'to', 'job_category_id', 'district_id'],
            fn (array $f) => self::stream($q($f)->with(['applicantProfile.user:id,email', 'vacancy.jobCategory']), fn ($a) => self::applicationRow($a)),
            fn (array $f) => $q($f)->count(),
        );
    }

    // ---- 9. Registered organisations ----------------------------------------------

    private static function organizations(): Report
    {
        $q = fn (array $f) => self::dateRange(Organization::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->withCount(['serviceRequests', 'serviceRequests as completed_count' => fn ($q) => $q->where('status', ServiceRequestStatus::Completed)]), $f, 'organizations.created_at');

        return new Report(
            'organizations', __('Registered organisations'), __('Client organisations with their request activity.'),
            ['name' => __('Organisation'), 'type' => __('Type'), 'registration' => __('Reg. no.'), 'email' => __('Email'), 'phone' => __('Phone'), 'status' => __('Status'), 'requests' => __('Requests'), 'completed' => __('Completed'), 'registered' => __('Registered')],
            ['from', 'to', 'status'],
            fn (array $f) => self::stream($q($f), fn (Organization $o) => [
                'name' => $o->name, 'type' => $o->organization_type->getLabel(), 'registration' => $o->registration_number,
                'email' => $o->email, 'phone' => $o->phone, 'status' => $o->status->getLabel(),
                'requests' => $o->service_requests_count, 'completed' => $o->completed_count, 'registered' => self::d($o->created_at),
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => collect(OrganizationStatus::cases())->mapWithKeys(fn ($s) => [$s->getLabel() => $q(['status' => $s->value] + $f)->count()])->all(),
        );
    }

    // ---- 10. Service requests ------------------------------------------------------

    private static function requestQuery(array $f): Builder
    {
        return self::dateRange(ServiceRequest::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v)), $f, 'service_requests.created_at');
    }

    private static function serviceRequests(): Report
    {
        return new Report(
            'service_requests', __('Service requests'), __('All client requests with status, size and accepted value.'),
            ['reference' => __('Reference'), 'organization' => __('Organisation'), 'category' => __('Service'), 'title' => __('Title'), 'district' => __('District'), 'headcount' => __('People'), 'submitted' => __('Submitted'), 'status' => __('Status'), 'accepted_value' => __('Accepted quotation (LKR)')],
            ['from', 'to', 'district_id', 'status'],
            fn (array $f) => self::stream(self::requestQuery($f)->with(['organization:id,name', 'serviceCategory:id,name', 'district:id,name_en', 'quotations' => fn ($q) => $q->where('status', QuotationStatus::Accepted)]), fn (ServiceRequest $r) => [
                'reference' => $r->reference, 'organization' => $r->organization->name, 'category' => $r->serviceCategory->name,
                'title' => $r->title, 'district' => $r->district?->name_en, 'headcount' => $r->required_headcount,
                'submitted' => self::d($r->created_at), 'status' => $r->status->getLabel(),
                'accepted_value' => $r->quotations->first() ? (float) $r->quotations->first()->total : null,
            ]),
            fn (array $f) => self::requestQuery($f)->count(),
            fn (array $f) => [
                __('Total') => self::requestQuery($f)->count(),
                __('Open') => self::requestQuery($f)->whereNotIn('status', [ServiceRequestStatus::Completed, ServiceRequestStatus::Cancelled])->count(),
                __('Completed') => self::requestQuery(['status' => 'completed'] + $f)->count(),
                __('Cancelled') => self::requestQuery(['status' => 'cancelled'] + $f)->count(),
                __('Accepted value (LKR)') => number_format((float) DB::table('quotations')->where('status', 'accepted')
                    ->whereIn('service_request_id', self::requestQuery($f)->select('id'))->sum('total'), 2),
            ],
        );
    }

    // ---- 11. Completed projects ------------------------------------------------------

    private static function completedProjects(): Report
    {
        $q = fn (array $f) => self::dateRange(ServiceRequest::query()->where('status', ServiceRequestStatus::Completed)
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v))
            ->withCount(['assignments as team_size' => fn ($q) => $q->where('status', AssignmentStatus::Completed)]), $f, 'completed_at');

        return new Report(
            'completed_projects', __('Completed projects'), __('Finished projects with dates, team size and accepted value.'),
            ['reference' => __('Reference'), 'organization' => __('Organisation'), 'title' => __('Title'), 'started' => __('Start'), 'completed' => __('Completed'), 'team' => __('Team size'), 'value' => __('Value (LKR)')],
            ['from', 'to', 'district_id'],
            fn (array $f) => self::stream($q($f)->with(['organization:id,name', 'quotations' => fn ($q) => $q->where('status', QuotationStatus::Accepted)]), fn (ServiceRequest $r) => [
                'reference' => $r->reference, 'organization' => $r->organization->name, 'title' => $r->title,
                'started' => self::d($r->start_date), 'completed' => self::d($r->completed_at), 'team' => $r->team_size,
                'value' => $r->quotations->first() ? (float) $r->quotations->first()->total : null,
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => [__('Completed projects') => $q($f)->count()],
        );
    }

    // ---- 12. Workforce assignments ----------------------------------------------------

    private static function assignments(): Report
    {
        $q = fn (array $f) => self::dateRange(WorkforceAssignment::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['district_id'] ?? null, fn ($q, $v) => $q->whereHas('serviceRequest', fn ($r) => $r->where('district_id', $v))), $f, 'start_date')
            ->orderBy('start_date');

        return new Report(
            'assignments', __('Workforce assignments'), __('Who worked where, in which role and when.'),
            ['person' => __('Person'), 'role' => __('Role'), 'reference' => __('Request'), 'organization' => __('Organisation'), 'start' => __('Start'), 'end' => __('End'), 'status' => __('Status'), 'override' => __('Clearance override')],
            ['from', 'to', 'district_id', 'status'],
            fn (array $f) => self::stream($q($f)->with(['applicantProfile:id,full_name', 'serviceRequest.organization:id,name']), fn (WorkforceAssignment $a) => [
                'person' => $a->applicantProfile->full_name, 'role' => $a->role_title, 'reference' => $a->serviceRequest->reference,
                'organization' => $a->serviceRequest->organization->name, 'start' => self::d($a->start_date), 'end' => self::d($a->end_date),
                'status' => $a->status->getLabel(), 'override' => $a->pcc_override ? __('Yes') : '',
            ]),
            fn (array $f) => $q($f)->count(),
            fn (array $f) => collect(AssignmentStatus::cases())->mapWithKeys(fn ($s) => [$s->getLabel() => $q(['status' => $s->value] + $f)->count()])->all(),
        );
    }
}
