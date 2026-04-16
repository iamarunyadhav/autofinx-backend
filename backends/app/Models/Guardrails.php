<?php

namespace App\Models;

class Guardrails extends BaseModel
{
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'max_slippage_pct' => 'decimal:4',
        'max_spread_pct' => 'decimal:4',
        'max_open_orders' => 'integer',
        'loss_streak_cooldown_minutes' => 'integer',
        'max_daily_loss_pct' => 'decimal:4',
        'trailing_stop_pct' => 'decimal:4',
        'take_profit_pct' => 'decimal:4',
        'volatility_block_pct' => 'decimal:4',
        'circuit_breaker_failures' => 'integer',
    ];
}
