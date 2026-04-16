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
