<?php

namespace App\Models;

class ExternalContextEvent extends BaseModel
{
    protected $casts = [
        'occurred_at' => 'datetime',
        'fetched_at' => 'datetime',
        'raw_payload_json' => 'array',
        'normalized_payload_json' => 'array',
    ];
}

