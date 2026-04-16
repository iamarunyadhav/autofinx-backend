<?php

namespace App\Models;

class ChaosSimulation extends BaseModel
{
    protected $casts = [
        'expected_behavior_json' => 'array',
        'actual_behavior_json' => 'array',
        'findings_json' => 'array',
        'evidence_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}

