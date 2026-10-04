<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * `ts` is the candle open time stored as UTC wall time: every writer (the
 * websocket kline feed, the REST backfill, OANDA) builds it from exchange
 * epoch times, and the unique key (exchange, symbol, timeframe, ts) relies on
 * them agreeing. UtcDateTime reads it back as the true instant; bind UTC
 * Carbons (->utc()) in where() clauses on ts.
 */
class MarketOhlc extends BaseModel
{
    protected $table = 'market_ohlc';

    protected $casts = [
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
        'ts' => UtcDateTime::class,
    ];
}
