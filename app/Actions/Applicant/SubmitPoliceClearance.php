<?php

namespace App\Actions\Applicant;

use App\Enums\DocumentType;
use App\Enums\PccVerificationStatus;
use App\Models\ApplicantProfile;
use App\Models\PoliceClearanceRecord;
use App\Notifications\TemplatedNotification;
use App\Models\User;
use App\Support\Audit;
use App\Support\StaffNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applicant uploads a (new or renewed) police clearance certificate.
 *
 * The record starts as Pending Verification and is NEVER treated as valid
 * until an authorised administrator approves it (Phase 7). A currently
 * approved certificate stays in force until the new one is approved.
 */
class SubmitPoliceClearance
{
    public function __construct(private readonly UploadApplicantDocument $upload) {}

    public function __invoke(ApplicantProfile $profile, UploadedFile $file, Carbon $issueDate, User $uploader, ?string $certificateNumber = null, ?string $authority = null): PoliceClearanceRecord
    {
        $document = ($this->upload)($profile, DocumentType::PoliceClearance, $file, $uploader, __('Police clearance issued :date', ['date' => $issueDate->format('j M Y')]));

        $record = DB::transaction(function () use ($profile, $document, $issueDate, $certificateNumber, $authority) {
            // An earlier upload still awaiting review is replaced by this one
            // (kept in history, removed from the verification queue).
            PoliceClearanceRecord::query()
                ->where('applicant_profile_id', $profile->id)
                ->where('verification_status', PccVerificationStatus::PendingVerification)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $record = new PoliceClearanceRecord([
                'applicant_profile_id' => $profile->id,
                'applicant_document_id' => $document->id,
                'certificate_number' => $certificateNumber ?: null,
                'issue_date' => $issueDate,
                'issuing_authority' => $authority ?: null,
                'uploaded_at' => now(),
            ]);
            $record->verification_status = PccVerificationStatus::PendingVerification;
            $record->save();

            return $record;
        });

        Audit::log('pcc.uploaded', $record, new: ['issue_date' => $issueDate->toDateString(), 'document_id' => $document->id]);

        $this->notifyVerifiers($profile);

        return $record;
    }

    private function notifyVerifiers(ApplicantProfile $profile): void
    {
        StaffNotifier::notify('police_clearance.verify', new TemplatedNotification(
            'pcc.uploaded.admin',
            ['applicant_name' => $profile->full_name],
            url(config('portal.admin_path')),
            __('Open admin portal'),
        ));
    }
}
