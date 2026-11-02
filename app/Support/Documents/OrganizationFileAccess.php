<?php

namespace App\Support\Documents;

use App\Enums\QuotationStatus;
use App\Models\OrganizationDocument;
use App\Models\Quotation;
use App\Models\ServiceRequestAttachment;
use App\Models\ServiceRequestMessage;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a user may open a file that belongs to the organisation
 * side of the system, by finding what the file is attached to.
 *
 * A file is reachable only through one of:
 *   - organisation supporting documents
 *   - service request attachments marked visible to the client
 *   - non-internal message attachments
 *   - quotation PDFs that have been sent (never drafts)
 */
class OrganizationFileAccess
{
    /** @return array{0: bool, 1: ?string} [allowed, context label for the audit log] */
    public static function check(User $user, StoredFile $file): array
    {
        $staffCan = fn (string $perm) => $user->isStaff() && $user->can($perm);
        $orgId = $user->isOrgContact() ? $user->organizationMembership?->organization_id : null;

        if ($doc = OrganizationDocument::where('stored_file_id', $file->id)->first()) {
            return [$orgId === $doc->organization_id || $staffCan('organizations.view'), 'organization_document'];
        }

        if ($att = ServiceRequestAttachment::with('serviceRequest:id,organization_id')->where('stored_file_id', $file->id)->first()) {
            $client = $att->visible_to_client && $orgId === $att->serviceRequest->organization_id;

            return [$client || $staffCan('service_requests.view'), 'service_request_attachment'];
        }

        $messageId = DB::table('service_request_message_files')->where('stored_file_id', $file->id)->value('service_request_message_id');
        if ($messageId && ($message = ServiceRequestMessage::with('serviceRequest:id,organization_id')->find($messageId))) {
            $client = ! $message->is_internal && $orgId === $message->serviceRequest->organization_id;

            return [$client || $staffCan('service_requests.view'), 'message_attachment'];
        }

        if ($quote = Quotation::with('serviceRequest:id,organization_id')->where('pdf_file_id', $file->id)->first()) {
            $client = $quote->status !== QuotationStatus::Draft && $orgId === $quote->serviceRequest->organization_id;

            return [$client || $staffCan('quotations.view'), 'quotation_pdf'];
        }

        return [false, null];
    }
}
