<?php

namespace App\Console\Commands;

use App\Jobs\TradesReconcileJob;
use Illuminate\Console\Command;

class ReconcileTradesCommand extends Command
{
    protected $signature = 'reconcile:trades {--integration=} {--once} {--interval=120}';
    protected $description = 'Reconcile trades from exchange';

    public function handle(): int
    {
        $integration = $this->option('integration');
        $once = $this->option('once');
        $interval = (int) $this->option('interval');
        do {
            TradesReconcileJob::dispatchSync($integration);
            if ($once) break;
            sleep($interval);
        } while (true);
        return Command::SUCCESS;
    }
}
