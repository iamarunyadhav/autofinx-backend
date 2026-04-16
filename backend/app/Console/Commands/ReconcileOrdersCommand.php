<?php

namespace App\Console\Commands;

use App\Jobs\OrdersReconcileJob;
use Illuminate\Console\Command;

class ReconcileOrdersCommand extends Command
{
    protected $signature = 'reconcile:orders {--integration=} {--once} {--interval=60}';
    protected $description = 'Reconcile live orders with exchange';

    public function handle(): int
    {
        $integration = $this->option('integration');
        $once = $this->option('once');
        $interval = (int) $this->option('interval');

        do {
            OrdersReconcileJob::dispatchSync($integration);
            if ($once) break;
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
