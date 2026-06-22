<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class FutureTradeReconcileBinanceCommand extends Command
{
    protected $signature = 'future-trade:reconcile-binance {--workspace=} {--symbol=}';

    protected $description = 'Alias for future-trade:reconcile with Binance naming.';

    public function handle(): int
    {
        return Artisan::call('future-trade:reconcile', [
            '--workspace' => $this->option('workspace'),
            '--symbol' => $this->option('symbol'),
        ]);
    }
}
