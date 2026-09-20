<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fast2XState extends BaseModel
{
    protected $table = 'fast2x_states';

    protected $casts = [
        'entry_price' => 'decimal:8',
        'current_price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'current_peak' => 'decimal:8',
        'current_bottom' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'unrealized_pnl_percent' => 'decimal:6',
        'realized_pnl' => 'decimal:8',
        'net_pnl' => 'decimal:8',
        'net_pnl_percent' => 'decimal:6',
        'daily_profit' => 'decimal:8',
        'daily_loss' => 'decimal:8',
        'wins' => 'integer',
        'losses' => 'integer',
        'trade_count_today' => 'integer',
        'latency_ms' => 'integer',
        'last_price_update_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'order_in_progress' => 'boolean',
        'runtime_meta' => 'array',
    ];

    public const IDLE = 'IDLE';
    public const WAITING_FOR_SIGNAL = 'WAITING_FOR_SIGNAL';
    public const ENTERING_TRADE = 'ENTERING_TRADE';
    public const IN_POSITION = 'IN_POSITION';
    public const PROFIT_LOCK = 'PROFIT_LOCK';
    public const LOSS_ROLLBACK = 'LOSS_ROLLBACK';
    public const COOLDOWN = 'COOLDOWN';
    public const PAUSED = 'PAUSED';
    public const STOPPED = 'STOPPED';
    public const ERROR = 'ERROR';

    public function config(): BelongsTo
    {
        return $this->belongsTo(Fast2XConfig::class, 'fast2x_config_id');
    }
}
