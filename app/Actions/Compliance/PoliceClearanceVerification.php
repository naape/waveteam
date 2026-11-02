<?php

namespace App\Actions\Compliance;

use App\Enums\DocumentStatus;
use App\Enums\PccVerificationStatus;
use App\Models\PoliceClearanceRecord;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compliance decisions on police clearance certificates.
 *
 * Rules (from the brief):
 *  - an upload is never valid until an authorised administrator approves it;
 *  - the administrator sets the applicable expiry / renewal date (no fixed
 *    period is assumed — the configured validity is only a suggestion);
 *  - history is never deleted: the previously approved certificate is marked
 *    superseded, not removed.
 */
class PoliceClearanceVerification
{
    public function approve(PoliceClearanceRecord $record, User $by, Carbon $validUntil, ?Carbon $renewalDue = null, ?string $notes = null): void
    {
        $this->authorize($by);

        if ($record->verification_status !== PccVerificationStatus::PendingVerification || $record->superseded_at) {
            throw ValidationException::withMessages(['record' => __('Only a pending, current certificate can be approved.')]);
        }

        $renewalDue ??= $validUntil->copy();

        $errors = [];
        if ($validUntil->lte($record->issue_date)) {
            $errors['valid_until'] = __('The expiry date must be after the issue date.');
        } elseif ($validUntil->lt(today())) {
            $errors['valid_until'] = __('This certificate has already expired — reject it and ask for a new one.');
        }
        if ($renewalDue->gt($validUntil)) {
            $errors['next_renewal_due'] = __('The renewal date cannot be after the expiry date.');
        } elseif ($renewalDue->lt(today())) {
            $errors['next_renewal_due'] = __('The renewal date must be today or later.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $profile = $record->applicantProfile;

        DB::transaction(function () use ($record, $by, $validUntil, $renewalDue, $notes, $profile) {
            // The certificate it replaces stays in history, marked superseded.
            PoliceClearanceRecord::query()
                ->where('applicant_profile_id', $profile->id)
                ->where('verification_status', PccVerificationStatus::Approved)
                ->whereNull('superseded_at')
                ->whereKeyNot($record->id)
                ->update(['superseded_at' => now()]);

            $record->forceFill([
                'verification_status' => PccVerificationStatus::Approved,
                'valid_until' => $validUntil->toDateString(),
                'next_renewal_due' => $renewalDue->toDateString(),
                'verified_by' => $by->id,
                'verified_at' => now(),
                'admin_notes' => $notes,
                'rejection_reason' => null,
            ])->save();

            $record->document?->forceFill(['status' => DocumentStatus::Verified, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();

            $profile->forceFill(['current_police_clearance_id' => $record->id])->save();
        });

        Audit::log('pcc.approved', $record, new: [
            'valid_until' => $validUntil->toDateString(),
            'next_renewal_due' => $renewalDue->toDateString(),
            'suggested_valid_until' => $record->suggestedValidUntil()->toDateString(),
        ], meta: array_filter(['notes' => $notes]));

        $profile->user->notify(new TemplatedNotification('pcc.approved', [
            'renewal_date' => $renewalDue->format('j F Y'),
        ], route('applicant.police-clearance.index'), __('View certificate status')));
    }

    public function reject(PoliceClearanceRecord $record, User $by, string $reason): void
    {
        $this->authorize($by);

        if ($record->verification_status !== PccVerificationStatus::PendingVerification) {
            throw ValidationException::withMessages(['record' => __('Only a pending certificate can be rejected.')]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Please give a reason so the applicant can correct it.')]);
        }

        DB::transaction(function () use ($record, $by, $reason) {
            $record->forceFill([
                'verification_status' => PccVerificationStatus::Rejected,
                'verified_by' => $by->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $record->document?->forceFill(['status' => DocumentStatus::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'rejection_reason' => $reason])->save();
        });

        Audit::log('pcc.rejected', $record, meta: ['reason' => $reason]);

        $record->applicantProfile->user->notify(new TemplatedNotification('pcc.rejected', ['reason' => $reason],
            route('applicant.police-clearance.index'), __('Upload a valid certificate')));
    }

    /**
     * Change the renewal date of the certificate currently in force (e.g. a
     * client requires renewal earlier). Resets reminders so the applicant is
     * reminded against the new date.
     */
    public function changeRenewalDate(PoliceClearanceRecord $record, User $by, Carbon $renewalDue, string $reason): void
    {
        $this->authorize($by);

        if ($record->verification_status !== PccVerificationStatus::Approved || $record->superseded_at) {
            throw ValidationException::withMessages(['next_renewal_due' => __('Only the approved certificate in force can be changed.')]);
        }
        if ($renewalDue->gt($record->valid_until) || $renewalDue->lt(today())) {
            throw ValidationException::withMessages(['next_renewal_due' => __('Choose a date between today and the expiry date (:d).', ['d' => $record->valid_until->format('j M Y')])]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        $old = $record->next_renewal_due?->toDateString();

        DB::transaction(function () use ($record, $renewalDue) {
            $record->forceFill(['next_renewal_due' => $renewalDue->toDateString()])->save();
            $record->reminders()->delete();
        });

        Audit::log('pcc.renewal_date_changed', $record, ['next_renewal_due' => $old], ['next_renewal_due' => $renewalDue->toDateString()], ['reason' => $reason]);
    }

    private function authorize(User $by): void
    {
        if (! $by->isStaff() || ! $by->can('police_clearance.verify')) {
            throw ValidationException::withMessages(['record' => __('You are not authorised to verify police clearance certificates.')]);
        }
    }
}
