<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ReconcileOrdersJob;

class OpsReconcileOrdersCommand extends Command
{
    protected $signature = 'ops:reconcile-orders {--once}';
    protected $description = 'Reconcile stale pending orders and mark failures';

    public function handle(): int
    {
        $once = $this->option('once');
        if ($once) {
            ReconcileOrdersJob::dispatchSync();
            $this->info('Reconcile run once.');
            return self::SUCCESS;
        }

        while (true) {
            ReconcileOrdersJob::dispatchSync();
            $this->info('Reconcile cycle done.');
            sleep(60);
        }
    }
}
