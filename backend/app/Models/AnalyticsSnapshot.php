<?php

namespace App\Models;

class AnalyticsSnapshot extends BaseModel
{
    protected $casts = [
        'metrics_json' => 'array',
        'captured_at' => 'datetime',
    ];
}
