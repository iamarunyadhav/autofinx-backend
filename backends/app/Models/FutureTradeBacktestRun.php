<?php

namespace App\Models;

class FutureTradeBacktestRun extends BaseModel
{
    protected $table = 'future_trade_backtest_runs';

    protected $casts = [
        'symbols'           => 'array',
        'settings_snapshot' => 'array',
        'by_symbol'         => 'array',
        'by_exit_reason'    => 'array',
        'initial_capital_usdt'    => 'float',
        'total_pnl_usdt'          => 'float',
        'total_pnl_percent'       => 'float',
        'max_drawdown_usdt'       => 'float',
        'max_drawdown_percent'    => 'float',
        'profit_factor'           => 'float',
        'sharpe_ratio'            => 'float',
        'win_rate'                => 'float',
        'total_trades'            => 'integer',
        'wins'                    => 'integer',
        'losses'                  => 'integer',
        'avg_trade_duration_minutes' => 'integer',
        'from_date'         => 'date',
        'to_date'           => 'date',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public function trades()
    {
        return $this->hasMany(FutureTradeBacktestTrade::class, 'future_trade_backtest_run_id');
    }

    public function config()
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
