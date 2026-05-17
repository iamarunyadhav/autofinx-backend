<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FutureTradeLogicalTrade extends BaseModel
{
    protected $table = 'future_trade_logical_trades';

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CLOSING = 'CLOSING';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_ERROR = 'ERROR';
    public const STATUS_RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    public const ACTIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSING];

    protected $casts = [
        'entry_price' => 'decimal:10',
        'exit_price' => 'decimal:10',
        'quantity' => 'decimal:10',
        'leverage' => 'decimal:2',
        'gross_pnl' => 'decimal:10',
        'fees' => 'decimal:10',
        'net_pnl' => 'decimal:10',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FutureTradeLogicalTradeEvent::class, 'future_trade_logical_trade_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
