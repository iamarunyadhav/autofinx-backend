<?php

namespace App\Models;

class StrategyPerformanceSnapshot extends BaseModel
{
    protected $casts = [
        'metrics_json' => 'array',
        'captured_at' => 'datetime',
    ];
}
