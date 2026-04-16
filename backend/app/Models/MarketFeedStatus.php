<?php

namespace App\Models;

class MarketFeedStatus extends BaseModel
{
    protected $table = 'market_feed_status';

    protected $casts = [
        'last_tick_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
