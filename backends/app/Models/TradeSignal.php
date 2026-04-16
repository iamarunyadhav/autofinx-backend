<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeSignal extends BaseModel
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_EXECUTED = 'EXECUTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $casts = [
        'decision_price' => 'decimal:8',
        'max_slippage_pct' => 'decimal:4',
        'decision_ts' => 'datetime',
        'executed_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
