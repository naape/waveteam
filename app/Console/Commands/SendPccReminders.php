<?php

namespace App\Console\Commands;

use App\Actions\Compliance\SendPoliceClearanceReminders;
use Illuminate\Console\Command;

class SendPccReminders extends Command
{
    protected $signature = 'pcc:send-reminders';

    protected $description = 'Send police clearance renewal reminders and expiry notices (idempotent; safe to re-run).';

    public function handle(SendPoliceClearanceReminders $send): int
    {
        $stats = $send();
        $this->info("Reminders sent: {$stats['reminders']} · expiry notices: {$stats['expired']} · missed thresholds skipped: {$stats['skipped']}");

        return self::SUCCESS;
    }
}
