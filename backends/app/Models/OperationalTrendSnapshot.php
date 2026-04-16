<?php

namespace App\Models;

class OperationalTrendSnapshot extends BaseModel
{
    protected $casts = [
        'metrics_json' => 'array',
        'evidence_json' => 'array',
        'observed_at' => 'datetime',
    ];
}

