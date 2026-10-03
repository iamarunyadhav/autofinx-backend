<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Simulated ML Paper Autopilot trade — separate from quick_trades and every wallet; never an order. */
class MlPaperAutopilotTrade extends Model
{
    protected $table = 'ml_paper_autopilot_trades';

    protected $guarded = [];

    protected $casts = [
        'prediction_id' => 'integer', 'horizon_minutes' => 'integer',
        'p_buy' => 'float', 'p_sell' => 'float', 'expected_net_pct' => 'float',
        'signal_time_ms' => 'integer', 'signal_price' => 'float', 'entry_time_ms' => 'integer', 'entry_price' => 'float',
        'expires_at_ms' => 'integer', 'exit_time_ms' => 'integer', 'exit_price' => 'float',
        'stake_usd' => 'float', 'cost_pct' => 'float', 'funding_buy_pct' => 'float',
        'gross_return_pct' => 'float', 'net_return_pct' => 'float', 'pnl_usd' => 'float',
    ];
}
