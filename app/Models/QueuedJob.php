<?php

namespace App\Models;

use App\Models\Concerns\DescribesQueuedEmail;
use Illuminate\Database\Eloquent\Model;

/** Read-only view of Laravel's `jobs` table. */
class QueuedJob extends Model
{
    use DescribesQueuedEmail;

    protected $table = 'jobs';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->reserved_at !== null => __('Sending'),
            $this->attempts > 0 => __('Retrying'),
            default => __('Waiting'),
        };
    }
}
