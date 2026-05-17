<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeLogicalTradeEvent extends BaseModel
{
    protected $table = 'future_trade_logical_trade_events';

    public const EVENT_SIGNAL_RAW = 'SIGNAL_RAW';
    public const EVENT_SIGNAL_CONFIRMED = 'SIGNAL_CONFIRMED';
    public const EVENT_ENTRY_SUBMITTED = 'ENTRY_SUBMITTED';
    public const EVENT_ENTRY_FILLED = 'ENTRY_FILLED';
    public const EVENT_EXIT_SUBMITTED = 'EXIT_SUBMITTED';
    public const EVENT_EXIT_FILLED = 'EXIT_FILLED';
    public const EVENT_RECONCILED = 'RECONCILED';
    public const EVENT_BLOCKED = 'BLOCKED';
    public const EVENT_ERROR = 'ERROR';

    protected $casts = [
        'price' => 'decimal:10',
        'quantity' => 'decimal:10',
        'payload' => 'array',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(FutureTradeLogicalTrade::class, 'future_trade_logical_trade_id');
    }
}
