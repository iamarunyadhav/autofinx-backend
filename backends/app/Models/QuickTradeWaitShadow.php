<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shadow record of a fee-aware WAIT decision nobody traded (observation
 * only -- see QuickTradeWaitShadowService). Market-level, not
 * workspace-owned: the forecast decision it records is the same for every
 * workspace looking at that symbol/timeframe/expiry/candle close.
 */
class QuickTradeWaitShadow extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'expiry_seconds' => 'integer',
        'decision_candle_close_ms' => 'integer',
        'decision_candle_close_at' => 'datetime',
        'reference_at' => 'datetime',
        'target_ms' => 'integer',
        'expiry_score' => 'float',
        'expected_move_pct' => 'float',
        'required_move_pct' => 'float',
        'max_achievable_move_pct' => 'float',
        'reference_price' => 'float',
        'horizon_seconds' => 'integer',
        'horizon_candles' => 'integer',
        'cost_model' => 'array',
        'reference_notional_usdt' => 'float',
        'expiry_price' => 'float',
        'expiry_sample_delay_ms' => 'integer',
        'gross_move_pct' => 'float',
        'hypothetical_gross_usdt' => 'float',
        'hypothetical_cost_usdt' => 'float',
        'hypothetical_net_usdt' => 'float',
        'wait_saved_usdt' => 'float',
        'wait_missed_usdt' => 'float',
        'evaluated_at' => 'datetime',
    ];
}
