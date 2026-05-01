<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeBotTrade extends BaseModel
{
    protected $table = 'future_trade_bot_trades';

    protected $casts = [
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'leverage' => 'decimal:2',
        'gross_pnl' => 'decimal:8',
        'fees' => 'decimal:8',
        'net_pnl' => 'decimal:8',
        'meta' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
