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
        'stop_loss' => 'decimal:8',
        'take_profit' => 'decimal:8',
        'trailing_take_profit' => 'decimal:8',
        'peak_price' => 'decimal:8',
        'bottom_price' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'unrealized_pnl_percent' => 'decimal:6',
        'realized_pnl' => 'decimal:8',
        'total_profit' => 'decimal:8',
        'daily_profit' => 'decimal:8',
        'daily_loss' => 'decimal:8',
        'daily_loss_locked' => 'boolean',
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
        'open_position' => 'array',
        'runtime_json' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
