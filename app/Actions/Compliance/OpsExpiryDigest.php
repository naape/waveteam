<?php

namespace App\Actions\Compliance;

use App\Enums\AssignmentStatus;
use App\Models\WorkforceAssignment;
use App\Notifications\TemplatedNotification;
use App\Support\StaffNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Weekly heads-up for Operations: people on (or about to start) assignments
 * that require police clearance, whose certificate lapses before the
 * assignment ends or within the next 30 days.
 */
class OpsExpiryDigest
{
    /** @return Collection<int, array{assignment: WorkforceAssignment, renewal: ?Carbon, reason: string}> */
    public function findAtRisk(?Carbon $today = null): Collection
    {
        $today ??= today();
        $horizon = $today->copy()->addDays(30);

        return WorkforceAssignment::query()
            ->whereIn('status', [AssignmentStatus::Confirmed, AssignmentStatus::Active])
            ->whereHas('serviceRequest', fn ($q) => $q->where('requires_police_clearance', true))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->with(['serviceRequest', 'applicantProfile.currentPoliceClearance'])
            ->get()
            ->map(function (WorkforceAssignment $a) use ($today, $horizon) {
                $renewal = $a->applicantProfile->currentPoliceClearance?->renewalDate();
                $mustCover = $a->end_date ? min($a->end_date, $horizon) : $horizon;

                $reason = match (true) {
                    $renewal === null => __('no verified certificate'),
                    $renewal->lt($today) => __('expired :d', ['d' => $renewal->format('j M Y')]),
                    $renewal->lt($mustCover) => __('renewal due :d', ['d' => $renewal->format('j M Y')]),
                    default => null,
                };

                return $reason ? ['assignment' => $a, 'renewal' => $renewal, 'reason' => $reason] : null;
            })
            ->filter()
            ->values();
    }

    /** @return int number of at-risk assignments reported */
    public function __invoke(?Carbon $today = null): int
    {
        $items = $this->findAtRisk($today);

        if ($items->isEmpty()) {
            return 0;
        }

        $list = $items->map(fn ($i) => sprintf('- %s — %s (%s): %s',
            $i['assignment']->applicantProfile->full_name,
            $i['assignment']->role_title,
            $i['assignment']->serviceRequest->reference,
            $i['reason'],
        ))->implode("\n");

        StaffNotifier::notify('workforce.assign', new TemplatedNotification('pcc.ops_digest', [
            'count' => $items->count(),
            'list' => $list,
        ], url(config('portal.admin_path')), __('Open admin portal')));

        return $items->count();
    }
}
