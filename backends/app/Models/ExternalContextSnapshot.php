<?php

namespace App\Models;

class ExternalContextSnapshot extends BaseModel
{
    protected $casts = [
        'age_minutes' => 'integer',
        'confidence_adjustment_hint' => 'decimal:4',
        'no_trade_hint' => 'boolean',
        'pause_review_hint' => 'boolean',
        'event_flags' => 'array',
        'high_severity_events' => 'array',
        'summary_json' => 'array',
        'source_health_json' => 'array',
        'computed_at' => 'datetime',
    ];
}

