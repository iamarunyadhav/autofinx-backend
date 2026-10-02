<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlCandle extends Model
{
    protected $table = 'ml_candles';

    protected $guarded = [];

    protected $casts = ['open_time_ms' => 'integer', 'close_time_ms' => 'integer', 'open' => 'float', 'high' => 'float', 'low' => 'float', 'close' => 'float', 'volume' => 'float', 'taker_buy_volume' => 'float', 'quote_volume' => 'float', 'trade_count' => 'integer', 'fetched_at' => 'datetime'];
}
