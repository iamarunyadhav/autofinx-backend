<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotBrainSnapshot extends BaseModel
{
    protected $casts = [
        'horizon_seconds'      => 'integer',
        'price_at_evaluation'  => 'decimal:8',
        'confidence'           => 'integer',
        'bullish_score'        => 'decimal:2',
        'neutral_score'        => 'decimal:2',
        'bearish_score'        => 'decimal:2',
        'reasons'              => 'array',
        'forecast_confidence'  => 'decimal:2',
        'expected_mid_price'   => 'decimal:8',
        'expected_low'         => 'decimal:8',
        'expected_high'        => 'decimal:8',
        'atr_pct'              => 'decimal:4',
        'raw_payload'          => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function conflictsWith(string $side, int $minConfidence): bool
    {
        $direction = strtolower((string) $this->direction);
        $confidence = (int) $this->confidence;

        if ($confidence < $minConfidence) {
            return false;
        }

        return ($side === 'BUY' && $direction === 'sell')
            || ($side === 'SELL' && $direction === 'buy');
    }
}
