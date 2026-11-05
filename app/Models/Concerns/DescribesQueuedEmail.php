<?php

namespace App\Models\Concerns;

use App\Support\Mail\QueuedEmail;
use Illuminate\Database\Eloquent\Builder;

trait DescribesQueuedEmail
{
    private ?array $emailInfo = null;

    /**
     * Report exports and in-portal notices share the queue; only the mail channel
     * of a notification belongs on the Email queue page. Each `__` is one escaped
     * quote in the JSON payload.
     */
    public function scopeEmails(Builder $query): Builder
    {
        return $query->where('payload', 'like', '%channels__;a:1:{i:0;s:4:__mail%');
    }

    /** @return array{subject: string, to: string} */
    public function emailInfo(): array
    {
        return $this->emailInfo ??= QueuedEmail::describe((string) $this->payload);
    }
}
