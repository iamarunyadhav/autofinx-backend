<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobotSession extends BaseModel
{
    protected $casts = [
        'indicator_set' => 'array',
        'base_stake' => 'decimal:8',
        'profit_target' => 'decimal:8',
        'loss_limit' => 'decimal:8',
        'max_consecutive_losses' => 'integer',
        'max_active_trades' => 'integer',
        'confidence_threshold' => 'integer',
        'total_pnl' => 'decimal:8',
        'win_count' => 'integer',
        'loss_count' => 'integer',
        'consecutive_losses' => 'integer',
        'last_signal_at' => 'datetime',
        'last_trade_at' => 'datetime',
        'runtime_json' => 'array',
        'stop_loss_pct' => 'decimal:4',
        'take_profit_pct' => 'decimal:4',
        'quick_trade_stop_loss_pct' => 'decimal:2',
        'quick_trade_take_profit_pct' => 'decimal:2',
        'quick_trade_leverage_enabled' => 'boolean',
        'quick_trade_leverage' => 'decimal:2',
        'multi_symbol' => 'boolean',
        'symbol_list' => 'array',
        'peak_profit' => 'decimal:8',
        'active_entry_price' => 'decimal:8',
        'active_since' => 'datetime',
        'trade_stats_json' => 'array',
        'cooldown_seconds' => 'integer',
        'daily_loss_limit' => 'decimal:8',
        'signal_streak' => 'integer',
        'last_signal_direction_at' => 'datetime',
        'auto_enabled_at' => 'datetime',
    ];

    public function quickTrades(): HasMany
    {
        return $this->hasMany(QuickTrade::class);
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
