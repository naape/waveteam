<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_enabled' => 'boolean',
            'is_mandatory' => 'boolean',
        ];
    }
}
