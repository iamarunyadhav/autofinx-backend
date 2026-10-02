<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Binance Futures funding history for the ML shadow cost model — shadow only. */
class MlFundingRate extends Model
{
    protected $table = 'ml_funding_rates';

    protected $guarded = [];

    protected $casts = ['funding_time_ms' => 'integer', 'funding_rate' => 'float', 'mark_price' => 'float'];
}
