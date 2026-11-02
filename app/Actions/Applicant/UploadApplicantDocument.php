<?php

namespace App\Actions\Applicant;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\ApplicantDocument;
use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use App\Support\Profile\ProfileCompletion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores a new document version. For single-instance types (CV, NIC, licence,
 * photo, police clearance) the previous current version is superseded —
 * never deleted, so the full history is kept.
 */
class UploadApplicantDocument
{
    public const SINGLE_INSTANCE = [
        DocumentType::Cv, DocumentType::Nic, DocumentType::DrivingLicence,
        DocumentType::Photo, DocumentType::PoliceClearance,
    ];

    public function __construct(private readonly DocumentVault $vault) {}

    public function __invoke(ApplicantProfile $profile, DocumentType $type, UploadedFile $file, User $uploader, ?string $title = null, string $field = 'file'): ApplicantDocument
    {
        $allowed = $type === DocumentType::Photo ? ['image/jpeg', 'image/png'] : null;
        $stored = $this->vault->store($file, $uploader, $allowed, $field);

        $document = DB::transaction(function () use ($profile, $type, $stored, $title) {
            $version = (int) $profile->documents()->withTrashed()->where('document_type', $type)->max('version') + 1;

            $document = new ApplicantDocument([
                'applicant_profile_id' => $profile->id,
                'document_type' => $type,
                'title' => $title,
                'stored_file_id' => $stored->id,
                'version' => $version,
                'is_current' => true,
            ]);
            $document->status = DocumentStatus::PendingVerification;
            $document->save();

            if (in_array($type, self::SINGLE_INSTANCE, true)) {
                $profile->documents()
                    ->where('document_type', $type)
                    ->where('is_current', true)
                    ->whereKeyNot($document->id)
                    ->get()
                    ->each(function (ApplicantDocument $old) use ($document) {
                        $old->forceFill([
                            'is_current' => false,
                            'status' => DocumentStatus::Superseded,
                            'superseded_by_id' => $document->id,
                        ])->save();
                    });
            }

            if ($type === DocumentType::Photo) {
                $profile->forceFill(['photo_file_id' => $stored->id])->save();
            }

            return $document;
        });

        Audit::log('document.uploaded', $document, new: ['type' => $type->value, 'version' => $document->version, 'file' => $stored->uuid]);
        ProfileCompletion::refresh($profile->fresh());

        return $document;
    }
}
