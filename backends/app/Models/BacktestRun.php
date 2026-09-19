<?php

namespace App\Models;

class BacktestRun extends BaseModel
{
    protected $table = 'backtest_runs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'horizon_seconds' => 'integer', 'candle_limit' => 'integer',
        'candles_written' => 'integer', 'result_rows' => 'integer',
        'queued_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
