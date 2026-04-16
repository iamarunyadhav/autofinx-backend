<?php

namespace App\Models;

class OperationalDriftDetection extends BaseModel
{
    protected $casts = [
        'details_json' => 'array',
        'evidence_json' => 'array',
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}

