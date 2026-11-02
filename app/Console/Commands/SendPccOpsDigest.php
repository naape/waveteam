<?php

namespace App\Console\Commands;

use App\Actions\Compliance\OpsExpiryDigest;
use Illuminate\Console\Command;

class SendPccOpsDigest extends Command
{
    protected $signature = 'pcc:ops-expiry-digest';

    protected $description = 'Weekly email to Operations listing assignments at risk from expiring police clearance.';

    public function handle(OpsExpiryDigest $digest): int
    {
        $this->info('At-risk assignments reported: '.$digest());

        return self::SUCCESS;
    }
}
