<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeBotState extends BaseModel
{
    protected $table = 'future_trade_bot_states';

    protected $casts = [
        'current_price' => 'decimal:8',
        'entry_price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'peak_price' => 'decimal:8',
        'bottom_price' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'unrealized_pnl_percent' => 'decimal:6',
        'realized_pnl' => 'decimal:8',
        'daily_profit' => 'decimal:8',
        'daily_loss' => 'decimal:8',
        'trade_count_today' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'confidence' => 'decimal:4',
        'last_scan_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'trading_day' => 'date',
        'daily_stop_until' => 'datetime',
        'scanner_snapshot' => 'array',
        'chart_points' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
