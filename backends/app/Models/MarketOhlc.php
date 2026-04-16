<?php

namespace App\Models;

class MarketOhlc extends BaseModel
{
    protected $table = 'market_ohlc';

    protected $casts = [
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
        'ts' => 'datetime',
    ];
}
