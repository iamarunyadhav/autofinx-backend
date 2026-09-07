<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBrainDecisionLog extends BaseModel
{
    protected $casts = [
        'market_price' => 'decimal:8',
        'shadow_mode' => 'boolean',
        'trend_score_breakdown' => 'array',
        'portfolio_guard_result' => 'array',
        'sell_candidates' => 'array',
        'emergency_assessment' => 'array',
        'observation_window_minutes' => 'integer',
        'overall_cycle_profit_at_decision_time' => 'decimal:8',
        'executed' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
