<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ApplicantSkill extends Pivot
{
    protected $table = 'applicant_skills';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'years' => 'decimal:1',
        ];
    }
}
