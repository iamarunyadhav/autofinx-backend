<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeBotLog extends BaseModel
{
    protected $table = 'future_trade_bot_logs';

    protected $casts = [
        'price' => 'decimal:8',
        'confidence' => 'decimal:4',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
