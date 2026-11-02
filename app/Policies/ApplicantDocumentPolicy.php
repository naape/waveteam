<?php

namespace App\Policies;

use App\Enums\DocumentType;
use App\Models\ApplicantDocument;
use App\Models\User;

/**
 * Who may open an applicant's document.
 *  - The applicant: their own documents (all versions).
 *  - Staff: police clearance needs `police_clearance.view`; photos need
 *    `applicants.view`; everything else needs `documents.view`.
 *  - Organisation contacts: never (they see verified status only).
 */
class ApplicantDocumentPolicy
{
    public function view(User $user, ApplicantDocument $document): bool
    {
        if ($user->isApplicant()) {
            return $document->applicantProfile?->user_id === $user->id;
        }

        if (! $user->isStaff()) {
            return false;
        }

        return match ($document->document_type) {
            DocumentType::PoliceClearance => $user->can('police_clearance.view'),
            DocumentType::Photo => $user->can('applicants.view'),
            default => $user->can('documents.view'),
        };
    }
}
