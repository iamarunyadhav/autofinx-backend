<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForexGridBotLevel extends BaseModel
{
    protected $casts = [
        'level_index' => 'integer',
        'entry_price' => 'decimal:6',
        'lot_size' => 'decimal:4',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'realized_pnl' => 'decimal:6',
    ];

    public const SIDE_BUY = 'buy';
    public const SIDE_SELL = 'sell';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FILLED = 'filled';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public function config(): BelongsTo
    {
        return $this->belongsTo(ForexGridBotConfig::class, 'forex_grid_bot_config_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FILLED], true);
    }
}
