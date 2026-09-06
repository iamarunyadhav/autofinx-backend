<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotLot extends BaseModel
{
    protected $casts = [
        'allocation_pct' => 'decimal:4',
        'buy_price' => 'decimal:8',
        'original_qty' => 'decimal:8',
        'remaining_qty' => 'decimal:8',
        'spent_quote' => 'decimal:8',
        'sell_fills' => 'array',
        'realized_pnl' => 'decimal:8',
        'bought_at' => 'datetime',
        'closed_at' => 'datetime',
        'cycle_number' => 'integer',
        'is_recovery_average' => 'boolean',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const TYPE_NORMAL = 'NORMAL';
    public const TYPE_RECOVERY = 'RECOVERY';
    public const TYPE_SLOT_FUNDED = 'SLOT_FUNDED';
    public const TYPE_FUTURES_RECOVERY = 'FUTURES_RECOVERY';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function buyDecision(): BelongsTo
    {
        return $this->belongsTo(RatioBotDecision::class, 'buy_decision_id');
    }
}
