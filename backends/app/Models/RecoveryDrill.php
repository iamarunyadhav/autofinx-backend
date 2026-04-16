<?php

namespace App\Models;

class RecoveryDrill extends BaseModel
{
    protected $casts = [
        'actions_json' => 'array',
        'findings_json' => 'array',
        'recommendations_json' => 'array',
        'evidence_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}

