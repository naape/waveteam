<?php

namespace App\Actions\Admin;

use App\Models\FailedQueueJob;
use App\Models\QueuedJob;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;

/**
 * Staff actions on emails that are waiting or could not be sent.
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

    /**
     * Stops a waiting email being sent. Returns false if the queue worker had
     * already picked it up, since it can no longer be stopped.
     */
    public function deleteWaiting(QueuedJob $job): bool
    {
        $meta = $this->meta($job);

        if (! QueuedJob::query()->whereKey($job->getKey())->whereNull('reserved_at')->delete()) {
            return false;
        }

        Audit::log('email_queue.waiting_deleted', meta: $meta);

        return true;
    }

    public function deleteAllWaiting(): int
    {
        $count = QueuedJob::query()->emails()->whereNull('reserved_at')->delete();
        Audit::log('email_queue.waiting_deleted_all', meta: ['count' => $count]);

        return $count;
    }

    private function meta(FailedQueueJob|QueuedJob $job): array
    {
        return ['uuid' => $job->uuid ?? null, 'to' => $job->emailInfo()['to'], 'subject' => $job->emailInfo()['subject']];
    }
}
