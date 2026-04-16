<?php

namespace App\Models;

class MaintenanceConfidenceSnapshot extends BaseModel
{
    protected $casts = [
        'confidence_score' => 'decimal:2',
        'rationale_json' => 'array',
        'evidence_json' => 'array',
        'observed_at' => 'datetime',
    ];
}

