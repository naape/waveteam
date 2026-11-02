<?php

namespace App\Actions\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\ApplicantDocument;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Staff decisions about applicants: profile verification, applicant status
 * and (non-police-clearance) document review.
 */
class ApplicantReview
{
    public function setVerification(ApplicantProfile $profile, VerificationStatus $status, User $by, ?string $note = null): void
    {
        $old = $profile->verification_status;

        $profile->forceFill([
            'verification_status' => $status,
            'verified_by' => $status === VerificationStatus::Verified ? $by->id : null,
            'verified_at' => $status === VerificationStatus::Verified ? now() : null,
        ])->save();

        Audit::log('applicant.verification_changed', $profile, ['verification_status' => $old?->value], ['verification_status' => $status->value], array_filter(['note' => $note]));

        if ($status === VerificationStatus::Verified && $old !== VerificationStatus::Verified) {
            $profile->user->notify(new TemplatedNotification('profile.verified', actionUrl: route('applicant.dashboard'), actionText: __('Go to dashboard')));
        }
    }

    public function setStatus(ApplicantProfile $profile, ApplicantStatus $status, User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        $old = $profile->applicant_status;
        $profile->forceFill(['applicant_status' => $status])->save();

        Audit::log('applicant.status_changed', $profile, ['applicant_status' => $old?->value], ['applicant_status' => $status->value], ['reason' => $reason, 'by' => $by->id]);
    }

    public function reviewDocument(ApplicantDocument $document, bool $approve, User $by, ?string $reason = null): void
    {
        if ($document->document_type === DocumentType::PoliceClearance) {
            // Police clearance has its own verification flow with renewal dates.
            throw ValidationException::withMessages(['document' => __('Police clearance certificates are verified in the Police Clearance queue.')]);
        }

        if (! $approve && blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('Please give a reason so the applicant can correct it.')]);
        }

        $document->forceFill([
            'status' => $approve ? DocumentStatus::Verified : DocumentStatus::Rejected,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'rejection_reason' => $approve ? null : $reason,
        ])->save();

        Audit::log($approve ? 'document.verified' : 'document.rejected', $document, meta: array_filter(['reason' => $reason, 'type' => $document->document_type->value]));

        if (! $approve) {
            $document->applicantProfile->user->notify(new TemplatedNotification('document.rejected', [
                'document' => $document->document_type->getLabel(),
                'reason' => $reason,
            ], route('applicant.documents.index'), __('Upload a corrected document')));
        }
    }
}
