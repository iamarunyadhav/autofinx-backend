<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RatioBotConfig extends BaseModel
{
    protected $casts = [
        'enabled'                     => 'boolean',
        'base_quote_amount'           => 'decimal:8',
        'max_trade_size_pct'          => 'decimal:4',
        'reserve_cash_buffer_pct'     => 'decimal:4',
        'sell_threshold_ratio'        => 'decimal:6',
        'buy_threshold_ratio'         => 'decimal:6',
        'allow_partial_sell'          => 'boolean',
        'allow_partial_buy'           => 'boolean',
        'partial_sell_pct'            => 'decimal:4',
        'partial_buy_pct'             => 'decimal:4',
        'poll_interval_seconds'       => 'integer',
        'cooldown_seconds_after_trade'=> 'integer',
        'daily_loss_limit_pct'        => 'decimal:4',
        'max_drawdown_pct'            => 'decimal:4',
        'max_open_orders'             => 'integer',
        'max_position_pct'            => 'decimal:4',
        'kill_switch_enabled'         => 'boolean',
        'pause_on_order_failure_streak' => 'integer',
        'pause_on_slippage_spike'     => 'boolean',
        'pause_on_exchange_unhealthy' => 'boolean',
        'pause_on_stale_feed'         => 'boolean',
        'require_stop_loss'           => 'boolean',
        'require_take_profit'         => 'boolean',
        'require_time_stop'           => 'boolean',
        'stop_loss_pct'               => 'decimal:4',
        'take_profit_pct'             => 'decimal:4',
        'time_stop_minutes'           => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(RatioBotState::class, 'ratio_bot_config_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RatioBotDecision::class, 'ratio_bot_config_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RatioBotEvent::class, 'ratio_bot_config_id');
    }

    public function isLiveMode(): bool
    {
        return $this->execution_mode === 'live';
    }

    public function isAutoMode(): bool
    {
        return $this->mode === 'restricted_auto';
    }

    public function isSandbox(): bool
    {
        return $this->execution_mode === 'sandbox';
    }
}
