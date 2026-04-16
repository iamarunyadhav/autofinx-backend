<?php

namespace Services\BacktestService\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBacktest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'backtest';

    public function __construct(
        public string $strategyId,
        public string $symbol,
        public string $timeframe = '1h'
    ) {
    }

    public function handle(): void
    {
        // Stub: compute fake stats
        // In a full implementation, fetch candles and apply strategy logic
    }
}
