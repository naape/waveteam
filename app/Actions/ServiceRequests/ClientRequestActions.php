<?php

namespace App\Actions\ServiceRequests;

use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestMessage;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Documents\DocumentVault;
use App\Support\StaffNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Things an organisation contact can do on an existing request:
 * message WaveTeam, cancel, and accept / reject a quotation.
 */
class ClientRequestActions
{
    public const CANCELLABLE = [
        ServiceRequestStatus::Submitted, ServiceRequestStatus::UnderReview,
        ServiceRequestStatus::QuotationPreparation, ServiceRequestStatus::QuotationSent,
    ];

    public function __construct(
        private readonly ChangeServiceRequestStatus $changeStatus,
        private readonly DocumentVault $vault,
    ) {}

    /** @param array<int, UploadedFile> $files */
    public function postMessage(ServiceRequest $request, User $sender, string $body, array $files = []): ServiceRequestMessage
    {
        if ($request->status === ServiceRequestStatus::Cancelled) {
            throw ValidationException::withMessages(['body' => __('This request has been cancelled.')]);
        }

        $stored = [];
        foreach ($files as $i => $file) {
            $stored[] = $this->vault->store($file, $sender, DocumentVault::PROJECT_TYPES, "files.{$i}");
        }

        $message = DB::transaction(function () use ($request, $sender, $body, $stored) {
            $message = ServiceRequestMessage::create([
                'service_request_id' => $request->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'is_internal' => false,
            ]);
            $message->files()->attach(collect($stored)->pluck('id'));

            return $message;
        });

        Audit::log('service_request.message_posted', $request, meta: ['message_id' => $message->id, 'files' => count($stored)]);

        StaffNotifier::notify('service_requests.manage', new TemplatedNotification('service_request.message.admin', [
            'reference' => $request->reference,
            'title' => $request->title,
            'organization_name' => $request->organization->name,
            'sender' => $sender->name,
            'excerpt' => Str::limit($body, 300),
        ], url(config('portal.admin_path')), __('Open admin portal')), $request->manager);

        return $message;
    }

    public function cancel(ServiceRequest $request, User $by, string $reason): void
    {
        if (! in_array($request->status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages(['reason' => __('This request can no longer be cancelled online. Please message WaveTeam.')]);
        }

        DB::transaction(function () use ($request, $by, $reason) {
            ($this->changeStatus)($request, ServiceRequestStatus::Cancelled, $by, $reason);

            // Any open quotation lapses with the request.
            $request->quotations()->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent])
                ->update(['status' => QuotationStatus::Superseded]);
        });

        StaffNotifier::notify('service_requests.manage', new TemplatedNotification('service_request.cancelled.admin', [
            'reference' => $request->reference,
            'title' => $request->title,
            'organization_name' => $request->organization->name,
            'reason' => $reason,
        ], url(config('portal.admin_path')), __('Open admin portal')), $request->manager);
    }

    public function respondToQuotation(Quotation $quotation, User $by, bool $accept, ?string $comment = null): void
    {
        $request = $quotation->serviceRequest;

        $isOpen = $quotation->status === QuotationStatus::Sent
            && ($quotation->valid_until === null || ! $quotation->valid_until->isPast())
            && $request->status === ServiceRequestStatus::QuotationSent;

        if (! $isOpen) {
            throw ValidationException::withMessages(['quotation' => __('This quotation can no longer be accepted or rejected. Please message WaveTeam.')]);
        }

        if (! $accept && blank($comment)) {
            throw ValidationException::withMessages(['comment' => __('Please tell us why you are rejecting the quotation.')]);
        }

        DB::transaction(function () use ($quotation, $request, $by, $accept, $comment) {
            $quotation->forceFill([
                'status' => $accept ? QuotationStatus::Accepted : QuotationStatus::Rejected,
                'responded_at' => now(),
                'responded_by' => $by->id,
                'client_comment' => $comment,
            ])->save();

            ($this->changeStatus)(
                $request,
                $accept ? ServiceRequestStatus::Approved : ServiceRequestStatus::UnderReview,
                $by,
                ($accept ? 'Quotation accepted: ' : 'Quotation rejected: ').$quotation->reference,
            );
        });

        Audit::log($accept ? 'quotation.accepted' : 'quotation.rejected', $quotation, meta: array_filter(['comment' => $comment]));

        StaffNotifier::notify('quotations.manage', new TemplatedNotification('quotation.responded.admin', [
            'quotation_reference' => $quotation->reference,
            'reference' => $request->reference,
            'organization_name' => $request->organization->name,
            'decision' => $accept ? __('accepted') : __('rejected'),
            'comment' => $comment ?: '—',
        ], url(config('portal.admin_path')), __('Open admin portal')), $request->manager);
    }
}
