<?php

namespace App\Actions\Workforce;

use App\Actions\ServiceRequests\ChangeServiceRequestStatus;
use App\Enums\AccountStatus;
use App\Enums\ApplicantStatus;
use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VerificationStatus;
use App\Models\ApplicantProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkforceAssignment;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Compliance\PoliceClearanceEligibility;
use App\Support\Workforce\WorkforceMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigning people to service requests and moving assignments through
 * proposed → confirmed → active → completed (or released / cancelled).
 *
 * The same eligibility rules as matching are re-checked here, so an
 * assignment can never be created by going around the matching screen.
 */
class AssignmentActions
{
    public const ASSIGNABLE_REQUEST = [ServiceRequestStatus::Approved, ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress];

    /** Allowed lifecycle moves. */
    public const TRANSITIONS = [
        'proposed' => ['confirmed', 'cancelled'],
        'confirmed' => ['active', 'released', 'cancelled'],
        'active' => ['completed', 'released'],
        'completed' => [],
        'released' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly ChangeServiceRequestStatus $changeRequestStatus) {}

    public function assign(
        ServiceRequest $request,
        ApplicantProfile $profile,
        string $roleTitle,
        Carbon $start,
        ?Carbon $end,
        User $by,
        ?string $pccOverrideReason = null,
        ?string $notes = null,
    ): WorkforceAssignment {
        if (! $by->can('workforce.assign')) {
            throw ValidationException::withMessages(['applicant' => __('You are not authorised to assign workforce.')]);
        }
        if (! in_array($request->status, self::ASSIGNABLE_REQUEST, true)) {
            throw ValidationException::withMessages(['applicant' => __('People can be assigned only after the request is approved.')]);
        }
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => __('The end date must be on or after the start date.')]);
        }

        $profile->loadMissing(['user', 'currentPoliceClearance']);

        if ($profile->verification_status !== VerificationStatus::Verified
            || $profile->applicant_status !== ApplicantStatus::Active
            || $profile->user?->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['applicant' => __('Only active, verified applicants can be assigned.')]);
        }

        $until = $end ?? $start->copy()->addMonth();

        $clash = $profile->assignments()->where(fn ($q) => WorkforceMatcher::overlapping($q, $start, $until))->with('serviceRequest:id,reference')->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_date' => __('Clashes with :ref (:from – :to).', [
                'ref' => $clash->serviceRequest->reference, 'from' => $clash->start_date->format('j M'), 'to' => $clash->end_date?->format('j M') ?? '…',
            ])]);
        }

        $override = false;
        if ($request->requires_police_clearance && ! PoliceClearanceEligibility::isEligible($profile, $until)) {
            $why = PoliceClearanceEligibility::reason($profile, $until);

            // The brief: only eligible people where clearance is required.
            // A Super Admin may override in an exceptional case, with a reason on record.
            if (blank($pccOverrideReason) || ! $by->can('workforce.override_pcc')) {
                throw ValidationException::withMessages(['applicant' => __('Not eligible for this site: :why', ['why' => $why])]);
            }
            $override = true;
        }

        $assignment = DB::transaction(function () use ($request, $profile, $roleTitle, $start, $end, $by, $override, $pccOverrideReason, $notes) {
            $a = new WorkforceAssignment([
                'service_request_id' => $request->id,
                'applicant_profile_id' => $profile->id,
                'role_title' => $roleTitle,
                'start_date' => $start->toDateString(),
                'end_date' => $end?->toDateString(),
                'status' => AssignmentStatus::Proposed,
                'police_clearance_record_id' => $profile->current_police_clearance_id,
                'assigned_by' => $by->id,
                'notes' => $notes,
            ]);
            $a->forceFill(['pcc_override' => $override, 'pcc_override_reason' => $override ? $pccOverrideReason : null])->save();

            return $a;
        });

        Audit::log('assignment.created', $assignment, new: [
            'request' => $request->reference, 'applicant_profile_id' => $profile->id, 'role' => $roleTitle,
            'start' => $start->toDateString(), 'end' => $end?->toDateString(),
        ], meta: array_filter(['pcc_override_reason' => $override ? $pccOverrideReason : null]));

        $profile->user->notify(new TemplatedNotification('assignment.created', [
            'role_title' => $roleTitle,
            'project_title' => $request->title,
            'start_date' => $start->format('j F Y'),
        ], route('applicant.dashboard'), __('View assignment')));

        return $assignment;
    }

    public function transition(WorkforceAssignment $assignment, AssignmentStatus $to, User $by, ?string $note = null): void
    {
        if (! $by->can('workforce.assign')) {
            throw ValidationException::withMessages(['status' => __('You are not authorised to manage assignments.')]);
        }

        $from = $assignment->status;
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw ValidationException::withMessages(['status' => __('An assignment cannot move from :from to :to.', ['from' => $from->getLabel(), 'to' => $to->getLabel()])]);
        }
        if (in_array($to, [AssignmentStatus::Released, AssignmentStatus::Cancelled], true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('A reason is required.')]);
        }

        $request = $assignment->serviceRequest;
        $profile = $assignment->applicantProfile;

        // Re-check clearance at the moment someone actually goes on site.
        if ($to === AssignmentStatus::Active && $request->requires_police_clearance && ! $assignment->pcc_override) {
            $until = $assignment->end_date ?? today()->addMonth();
            if (! PoliceClearanceEligibility::isEligible($profile->fresh('currentPoliceClearance'), $until)) {
                throw ValidationException::withMessages(['status' => __('Cannot start: :why', ['why' => PoliceClearanceEligibility::reason($profile, $until)])]);
            }
        }

        DB::transaction(function () use ($assignment, $to, $by, $note, $request, $profile) {
            $assignment->forceFill([
                'status' => $to,
                'end_date' => $to === AssignmentStatus::Completed && ! $assignment->end_date ? today()->toDateString() : $assignment->end_date,
                'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '').($note ? now()->format('Y-m-d').' '.$to->value.': '.$note : '')) ?: null,
            ])->save();

            // Request moves forward with its team.
            if ($to === AssignmentStatus::Confirmed && $request->status === ServiceRequestStatus::Approved) {
                ($this->changeRequestStatus)($request, ServiceRequestStatus::TeamAssigned, $by, 'First team member confirmed');
            }
            if ($to === AssignmentStatus::Active && $request->status === ServiceRequestStatus::TeamAssigned) {
                ($this->changeRequestStatus)($request, ServiceRequestStatus::InProgress, $by, 'Team on site');
            }

            $this->syncAvailability($profile);
        });

        Audit::log('assignment.status_changed', $assignment, ['status' => $from->value], ['status' => $to->value], array_filter(['note' => $note]));

        $profile->user->notify(new TemplatedNotification('assignment.status_changed', [
            'role_title' => $assignment->role_title,
            'project_title' => $request->title,
            'status' => $to->getLabel(),
            'note' => in_array($to, [AssignmentStatus::Released, AssignmentStatus::Cancelled], true) ? '' : (string) $note,
        ], route('applicant.dashboard'), __('View assignment')));
    }

    /**
     * "Engaged" while on an active assignment; back to "Available" when the
     * last one ends. Applicant-chosen statuses are otherwise left alone.
     */
    public function syncAvailability(ApplicantProfile $profile): void
    {
        $engaged = $profile->assignments()->where('status', AssignmentStatus::Active)->exists();

        if ($engaged && $profile->availability_status !== AvailabilityStatus::Engaged) {
            $profile->forceFill(['availability_status' => AvailabilityStatus::Engaged, 'available_from' => null])->save();
        } elseif (! $engaged && $profile->availability_status === AvailabilityStatus::Engaged) {
            $profile->forceFill(['availability_status' => AvailabilityStatus::Available])->save();
        }
    }
}
