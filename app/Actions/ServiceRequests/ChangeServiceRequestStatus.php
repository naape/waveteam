<?php

namespace App\Actions\ServiceRequests;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestStatusHistory;
use App\Models\User;
use App\Support\Audit;

/**
 * The single place a service request's status changes. Records history and
 * the audit trail. Callers decide who is notified.
 */
class ChangeServiceRequestStatus
{
    public function __invoke(ServiceRequest $request, ServiceRequestStatus $to, ?User $by, ?string $note = null): void
    {
        $from = $request->status;

        if ($from === $to) {
            return;
        }

        $request->status = $to;
        if ($to === ServiceRequestStatus::Completed) {
            $request->completed_at = now();
        }
        if ($to === ServiceRequestStatus::Cancelled) {
            $request->cancelled_at = now();
            $request->cancel_reason = $note;
        }
        $request->save();

        ServiceRequestStatusHistory::create([
            'service_request_id' => $request->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => $by?->id,
            'note' => $note,
        ]);

        Audit::log('service_request.status_changed', $request, ['status' => $from?->value], ['status' => $to->value], array_filter(['note' => $note]));
    }
}
