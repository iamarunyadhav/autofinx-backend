<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PepperstoneBasket extends BaseModel
{
    protected $casts = [
        'max_levels' => 'integer',
        'opened_levels' => 'integer',
        'lot_per_level' => 'decimal:4',
        'average_entry' => 'decimal:6',
        'floating_pnl' => 'decimal:6',
        'take_profit_target' => 'decimal:6',
        'maximum_allowed_loss' => 'decimal:6',
        'v3_confidence' => 'decimal:4',
        'mtf_confidence' => 'decimal:4',
        'mtf_agreement_score' => 'decimal:4',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const DIRECTION_BUY = 'buy';
    public const DIRECTION_SELL = 'sell';

    // State machine per the approved plan's §14 -- adopted verbatim.
    public const STATUS_IDLE = 'idle';
    public const STATUS_WATCHING = 'watching';
    public const STATUS_READY = 'ready';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXITING = 'exiting';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_RISK_BLOCKED = 'risk_blocked';

    /**
     * Non-closed statuses -- used everywhere "only one active basket per
     * config at a time" needs to be enforced/queried.
     */
    public const OPEN_STATUSES = [
        self::STATUS_IDLE,
        self::STATUS_WATCHING,
        self::STATUS_READY,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_EXITING,
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(PepperstoneBotConfig::class, 'pepperstone_bot_config_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(PepperstoneBasketSlot::class, 'pepperstone_basket_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
