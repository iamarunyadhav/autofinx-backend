<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotForecastBacktest extends BaseModel
{
    protected $casts = [
        'horizon_seconds'     => 'integer',
        'sample_size'         => 'integer',
        'hits'                => 'integer',
        'hit_rate_pct'        => 'decimal:2',
        'avg_confidence_pct'  => 'decimal:2',
        'computed_at'         => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
