<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PositionExitState extends BaseModel
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING = 'closing';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_UNCERTAIN = 'uncertain';

    public const MODE_REVIEW_REQUIRED = 'review_required';
    public const MODE_ARMED = 'armed';
    public const MODE_PAUSED = 'paused';
    public const MODE_BLOCKED = 'blocked';
    public const MODE_CLOSING = 'closing';
    public const MODE_CLOSED = 'closed';

    protected $casts = [
        'state_version' => 'integer',
        'exit_policy_snapshot' => 'array',
        'stop_loss_price' => 'decimal:8',
        'take_profit_price' => 'decimal:8',
        'time_stop_at' => 'datetime',
        'trailing_high_water_mark' => 'decimal:8',
        'trailing_stop_price' => 'decimal:8',
        'partial_progress' => 'array',
        'active_native_order_refs' => 'array',
        'last_evaluated_at' => 'datetime',
        'last_action_at' => 'datetime',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function activeExitOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'active_exit_order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PositionExitEvent::class);
    }

    public function effectiveState(): string
    {
        if ($this->status === self::STATUS_CLOSED) {
            return self::MODE_CLOSED;
        }

        if ($this->status === self::STATUS_UNCERTAIN) {
            return self::STATUS_UNCERTAIN;
        }

        if ($this->status === self::STATUS_CLOSING) {
            return self::MODE_CLOSING;
        }

        return (string) $this->managed_mode;
    }
}
