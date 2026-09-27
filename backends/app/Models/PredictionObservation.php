<?php

namespace App\Models;

class PredictionObservation extends BaseModel
{
    protected $fillable = [
        'correlation_id', 'symbol', 'market_type', 'timeframe', 'horizon_seconds',
        'provider', 'provider_version', 'predicted_direction', 'confidence',
        'entry_price', 'expiry_price', 'return_pct', 'direction_correct', 'status',
        'predicted_at', 'evaluated_at', 'metadata',
    ];

    protected $casts = [
        'confidence' => 'float', 'entry_price' => 'float', 'expiry_price' => 'float',
        'return_pct' => 'float', 'direction_correct' => 'boolean',
        'predicted_at' => 'datetime', 'evaluated_at' => 'datetime', 'metadata' => 'array',
    ];
}
