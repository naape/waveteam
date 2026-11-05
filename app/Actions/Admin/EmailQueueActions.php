<?php

namespace App\Actions\Admin;

use App\Models\FailedQueueJob;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;

/**
 * Staff actions on emails that could not be sent.
 */
class EmailQueueActions
{
    /** Puts the email back in the queue; the next cron run sends it. */
    public function retry(FailedQueueJob $job): void
    {
        $meta = $this->meta($job);
        Artisan::call('queue:retry', ['id' => [$job->uuid]]);
        Audit::log('email_queue.retried', meta: $meta);
    }

    public function retryAll(): int
    {
        $count = FailedQueueJob::query()->emails()->count();
        FailedQueueJob::query()->emails()->each(fn (FailedQueueJob $job) => Artisan::call('queue:retry', ['id' => [$job->uuid]]));
        Audit::log('email_queue.retried_all', meta: ['count' => $count]);

        return $count;
    }

    public function forget(FailedQueueJob $job): void
    {
        $meta = $this->meta($job);
        Artisan::call('queue:forget', ['id' => $job->uuid]);
        Audit::log('email_queue.deleted', meta: $meta);
    }

    private function meta(FailedQueueJob $job): array
    {
        return ['uuid' => $job->uuid, 'to' => $job->emailInfo()['to'], 'subject' => $job->emailInfo()['subject']];
    }
}
