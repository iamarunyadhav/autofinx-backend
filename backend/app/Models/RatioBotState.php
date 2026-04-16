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
        'last_evaluated_at'     => 'datetime',
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
        return $this->position_state === self::STATE_COOLDOWN
            && $this->cooldown_until !== null
            && $this->cooldown_until->isFuture();
    }
}
