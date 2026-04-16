<?php

namespace App\Models;

class MarketPriceCache extends BaseModel
{
    protected $table = 'market_prices_cache';

    protected $casts = [
        'last_price' => 'decimal:8',
        'last_ts' => 'datetime',
    ];
}
