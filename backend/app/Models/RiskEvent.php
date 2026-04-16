<?php

namespace App\Models;

class RiskEvent extends BaseModel
{
    protected $casts = [
        'reason_json' => 'array',
        'triggered_at' => 'datetime',
    ];
}
