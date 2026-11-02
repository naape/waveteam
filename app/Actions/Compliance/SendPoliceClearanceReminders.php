<?php

namespace App\Actions\Compliance;

use App\Enums\PccVerificationStatus;
use App\Models\PoliceClearanceRecord;
use App\Models\PoliceClearanceReminder;
use App\Notifications\TemplatedNotification;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Daily renewal reminders for certificates in force.
 *
 *  - Reminder days come from settings (default 30, 14, 7).
 *  - Each threshold is sent at most once per certificate, guaranteed by the
 *    unique (record, type) key — re-running the job, or two servers running
 *    it at once, can never send duplicates.
 *  - If the job missed some days (or a certificate was approved late), only
 *    the most urgent threshold is sent; the larger ones are recorded as
 *    skipped so the applicant doesn't get three emails at once.
 *  - No reminders while a renewal is already uploaded and awaiting review.
 *  - After the renewal date passes, one "expired" notice is sent.
 */
class SendPoliceClearanceReminders
{
    /** @return array{reminders:int, expired:int, skipped:int} */
    public function __invoke(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $days = collect(Settings::get('pcc.reminder_days'))->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0)->unique()->sortDesc()->values();
        $maxDays = (int) ($days->first() ?? 30);
        $stats = ['reminders' => 0, 'expired' => 0, 'skipped' => 0];

        PoliceClearanceRecord::query()
            ->where('verification_status', PccVerificationStatus::Approved)
            ->whereNull('superseded_at')
            ->whereRaw('COALESCE(next_renewal_due, valid_until) <= ?', [$today->copy()->addDays($maxDays)->toDateString()])
            ->whereHas('applicantProfile', fn ($q) => $q->whereColumn('applicant_profiles.current_police_clearance_id', 'police_clearance_records.id'))
            ->with(['applicantProfile.user', 'reminders'])
            ->chunkById(200, function ($records) use ($today, $days, &$stats) {
                foreach ($records as $record) {
                    $this->process($record, $today, $days->all(), $stats);
                }
            });

        return $stats;
    }

    private function process(PoliceClearanceRecord $record, Carbon $today, array $thresholds, array &$stats): void
    {
        $user = $record->applicantProfile?->user;
        if (! $user || ! $user->isActive()) {
            return;
        }

        $due = $record->renewalDate();
        $sent = $record->reminders->pluck('reminder_type')->all();

        // Expired: one notice, regardless of any pending renewal (they are
        // ineligible until the new certificate is verified).
        if ($today->gt($due)) {
            if (! in_array('expired', $sent, true) && $this->claim($record, 'expired')) {
                $user->notify(new TemplatedNotification('pcc.expired', ['renewal_date' => $due->format('j F Y')],
                    route('applicant.police-clearance.index'), __('Upload a new certificate')));
                Audit::log('pcc.expired_notice_sent', $record);
                $stats['expired']++;
            }

            return;
        }

        // Renewal already uploaded and waiting for review → don't nag.
        $renewalPending = PoliceClearanceRecord::query()
            ->where('applicant_profile_id', $record->applicant_profile_id)
            ->where('verification_status', PccVerificationStatus::PendingVerification)
            ->whereNull('superseded_at')
            ->where('uploaded_at', '>', $record->verified_at ?? $record->uploaded_at)
            ->exists();
        if ($renewalPending) {
            return;
        }

        $daysLeft = (int) $today->diffInDays($due, false);
        $reached = array_values(array_filter($thresholds, fn ($t) => $daysLeft <= $t));
        if ($reached === []) {
            return;
        }

        $mostUrgent = min($reached);
        $type = "d{$mostUrgent}";
        if (in_array($type, $sent, true)) {
            return;
        }

        // Record the larger, missed thresholds as handled so they never fire later.
        foreach ($reached as $t) {
            if ($t !== $mostUrgent && ! in_array("d{$t}", $sent, true)) {
                $this->claim($record, "d{$t}");
                $stats['skipped']++;
            }
        }

        if ($this->claim($record, $type)) {
            $user->notify(new TemplatedNotification('pcc.reminder', [
                'days' => $daysLeft,
                'renewal_date' => $due->format('j F Y'),
            ], route('applicant.police-clearance.index'), __('Upload renewed certificate')));
            $stats['reminders']++;
        }
    }

    /** Atomically reserve a reminder slot; false if another run already took it. */
    private function claim(PoliceClearanceRecord $record, string $type): bool
    {
        try {
            PoliceClearanceReminder::create(['police_clearance_record_id' => $record->id, 'reminder_type' => $type, 'sent_at' => now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
