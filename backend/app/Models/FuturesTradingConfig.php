<?php

namespace App\Models;

class FuturesTradingConfig extends BaseModel
{
    protected $casts = [
        'futures_enabled' => 'boolean',
        'allowed_futures_symbols' => 'array',
        'blocked_futures_symbols' => 'array',
        'max_futures_trade_size_pct' => 'decimal:4',
        'max_futures_notional' => 'decimal:8',
        'max_leverage' => 'decimal:4',
        'allowed_margin_modes' => 'array',
        'allowed_position_modes' => 'array',
        'max_open_futures_positions' => 'integer',
        'max_futures_daily_loss_pct' => 'decimal:4',
        'max_futures_drawdown_pct' => 'decimal:4',
        'min_liquidation_buffer_pct' => 'decimal:4',
        'require_reduce_only_for_protective_exit' => 'boolean',
        'require_manual_approval_for_futures' => 'boolean',
        'futures_confidence_threshold' => 'decimal:4',
        'futures_force_testnet' => 'boolean',
    ];
}
