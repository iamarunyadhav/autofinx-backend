<?php

namespace App\Models;

class FutureTradeBacktestTrade extends BaseModel
{
    protected $table = 'future_trade_backtest_trades';

    protected $casts = [
        'entry_price'       => 'float',
        'exit_price'        => 'float',
        'quantity'          => 'float',
        'leverage'          => 'float',
        'gross_pnl'         => 'float',
        'fees'              => 'float',
        'net_pnl'           => 'float',
        'entry_confidence'  => 'float',
        'duration_minutes'  => 'integer',
        'meta'              => 'array',
        'entry_time'        => 'datetime',
        'exit_time'         => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(FutureTradeBacktestRun::class, 'future_trade_backtest_run_id');
    }
}
