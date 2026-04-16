<?php

namespace App\Models;

class OperationalReadinessSnapshot extends BaseModel
{
    protected $casts = [
        'reason_codes_json' => 'array',
        'warning_codes_json' => 'array',
        'blocker_codes_json' => 'array',
        'details_json' => 'array',
        'health_json' => 'array',
        'checked_at' => 'datetime',
    ];
}
