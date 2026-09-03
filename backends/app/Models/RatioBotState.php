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
        'current_stage_index' => 'integer',
        'used_buy_allocation_pct' => 'decimal:4',
        'used_sell_allocation_pct' => 'decimal:4',
        'observed_low_price' => 'decimal:8',
        'observed_high_price' => 'decimal:8',
        'review_alerted_at' => 'datetime',
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
        'last_feed_event_at'   => 'datetime',
        'last_feed_healthy_at' => 'datetime',
        'degradation_started_at' => 'datetime',
        'recovered_at' => 'datetime',
        'feed_age_seconds' => 'integer',
        'reconnect_attempts' => 'integer',
        'consecutive_healthy_events' => 'integer',
        'operator_action_required' => 'boolean',
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
        'reserve_tranches_remaining' => 'integer',
        'reserve_quote_remaining' => 'decimal:8',
        'sell_tranches_remaining' => 'integer',
        'realized_pnl'          => 'decimal:8',
        'recovery_started_at'   => 'datetime',
        'recovery_review_at'    => 'datetime',
        'recovery_used_at'      => 'datetime',
        'current_cycle_number'  => 'integer',
    ];

    // Valid position states
    public const STATE_IDLE                    = 'idle';
    public const STATE_HOLDING                 = 'holding';
    public const STATE_PARTIAL_HOLDING         = 'partial_holding';
    public const STATE_FLAT                    = 'flat';
    public const STATE_PAUSED                  = 'paused';
    public const STATE_COOLDOWN                = 'cooldown';
    public const STATE_BLOCKED                 = 'blocked';
    public const STATE_AWAITING_EXECUTION      = 'awaiting_execution_result';
    public const STATE_ERROR_REVIEW            = 'error_review_required';

    // V2 (gated behind config.v2_enabled)
    public const STATE_PARTIAL_SOLD             = 'partial_sold';
    public const STATE_RECOVERY_MONITORING      = 'recovery_monitoring';
    public const STATE_CASH_RECOVERY_MONITORING = 'cash_recovery_monitoring';
    public const STATE_STRATEGY_RANGE_EXHAUSTED_DOWN = 'strategy_range_exhausted_down';
    public const STATE_STRATEGY_RANGE_EXHAUSTED_UP = 'strategy_range_exhausted_up';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function isTrading(): bool
    {
        return in_array($this->position_state, [
            self::STATE_HOLDING,
            self::STATE_PARTIAL_HOLDING,
            self::STATE_PARTIAL_SOLD,
            self::STATE_FLAT,
            self::STATE_RECOVERY_MONITORING,
            self::STATE_CASH_RECOVERY_MONITORING,
            self::STATE_STRATEGY_RANGE_EXHAUSTED_DOWN,
            self::STATE_STRATEGY_RANGE_EXHAUSTED_UP,
        ], true);
    }

    public function isPartialSold(): bool
    {
        return $this->position_state === self::STATE_PARTIAL_SOLD;
    }

    public function isRecoveryMonitoring(): bool
    {
        return in_array($this->position_state, [
            self::STATE_RECOVERY_MONITORING,
            self::STATE_CASH_RECOVERY_MONITORING,
        ], true);
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

    public function isPartialHolding(): bool
    {
        return $this->position_state === self::STATE_PARTIAL_HOLDING;
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
            return (int) ($this->sell_tranches_remaining ?? 0) > 0 && (float) ($this->last_buy_quantity ?? 0) > 0
                ? self::STATE_PARTIAL_SOLD
                : self::STATE_FLAT;
        }

        if ((float) ($this->last_buy_price ?? 0) > 0) {
            return (int) ($this->reserve_tranches_remaining ?? 0) > 0
                ? self::STATE_PARTIAL_HOLDING
                : self::STATE_HOLDING;
        }

        return self::STATE_IDLE;
    }

    public function hasOpenPosition(): bool
    {
        return ((float) ($this->last_position_qty ?? 0)) > 0;
    }
}
