<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotState extends BaseModel
{
    protected $casts = [
        'last_buy_price'        => 'decimal:8',
        'last_sell_price'       => 'decimal:8',
        'last_buy_quantity'     => 'decimal:8',
        'last_sell_quantity'    => 'decimal:8',
        'active_anchor_price'   => 'decimal:8',
        'last_seen_price'       => 'decimal:8',
        'current_ratio'         => 'decimal:6',
        'consecutive_order_failures' => 'integer',
        'last_trade_time'       => 'datetime',
        'paused_at'             => 'datetime',
        'cooldown_until'        => 'datetime',
        'awaiting_since'        => 'datetime',
        'recovery_action_required' => 'boolean',
        'sell_armed'            => 'boolean',
        'sell_peak_ratio'       => 'decimal:6',
        'sell_peak_price'       => 'decimal:8',
        'buy_armed'             => 'boolean',
        'buy_trough_ratio'      => 'decimal:6',
        'buy_trough_price'      => 'decimal:8',
        'pending_trigger_started_at' => 'datetime',
        'pending_trigger_updates' => 'integer',
        'pending_trigger_ratio' => 'decimal:6',
        'pending_trigger_price' => 'decimal:8',
        'last_evaluated_at'     => 'datetime',
        'last_price_timestamp'  => 'datetime',
        'feed_status_updated_at' => 'datetime',
        'last_signal_at'        => 'datetime',
        'last_fill_price'       => 'decimal:8',
        'last_position_qty'     => 'decimal:8',
        'entry_price'           => 'decimal:8',
        'unrealized_pnl'        => 'decimal:8',
        'last_candle_close_at'  => 'datetime',
        'runtime_meta'          => 'array',
        'trade_day'             => 'date',
        'daily_trade_count'     => 'integer',
        'daily_loss_total'      => 'decimal:8',
        'consecutive_losses'    => 'integer',
    ];

    // Valid position states
    public const STATE_IDLE                    = 'idle';
    public const STATE_HOLDING                 = 'holding';
    public const STATE_FLAT                    = 'flat';
    public const STATE_PAUSED                  = 'paused';
    public const STATE_COOLDOWN                = 'cooldown';
    public const STATE_BLOCKED                 = 'blocked';
    public const STATE_AWAITING_EXECUTION      = 'awaiting_execution_result';
    public const STATE_ERROR_REVIEW            = 'error_review_required';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function isTrading(): bool
    {
        return in_array($this->position_state, [self::STATE_HOLDING, self::STATE_FLAT], true);
    }

    public function isBlocked(): bool
    {
        return in_array($this->position_state, [
            self::STATE_PAUSED,
            self::STATE_BLOCKED,
            self::STATE_COOLDOWN,
            self::STATE_ERROR_REVIEW,
        ], true);
    }

    public function isHolding(): bool
    {
        return $this->position_state === self::STATE_HOLDING;
    }

    public function isFlat(): bool
    {
        return $this->position_state === self::STATE_FLAT;
    }

    public function isIdle(): bool
    {
        return $this->position_state === self::STATE_IDLE;
    }

    public function inCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    public function resumableTradingState(): string
    {
        if ($this->last_trade_side === 'SELL') {
            return self::STATE_FLAT;
        }

        if ((float) ($this->last_buy_price ?? 0) > 0) {
            return self::STATE_HOLDING;
        }

        return self::STATE_IDLE;
    }

    public function hasOpenPosition(): bool
    {
        return ((float) ($this->last_position_qty ?? 0)) > 0;
    }
}
