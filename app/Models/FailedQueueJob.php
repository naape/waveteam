<?php

namespace App\Models;

use App\Models\Concerns\DescribesQueuedEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Read-only view of Laravel's `failed_jobs` table. */
class FailedQueueJob extends Model
{
    use DescribesQueuedEmail;

    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /** The first line of the error, without the stack trace. */
    public function reason(): string
    {
        return Str::limit(strtok((string) $this->exception, "\n") ?: '', 200);
    }
}
