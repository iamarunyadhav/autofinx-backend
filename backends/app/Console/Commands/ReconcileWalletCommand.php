<?php

namespace App\Console\Commands;

use App\Jobs\WalletReconcileJob;
use Illuminate\Console\Command;

class ReconcileWalletCommand extends Command
{
    protected $signature = 'reconcile:wallet {--integration=} {--once} {--interval=300}';
    protected $description = 'Reconcile wallet balances with exchange';

    public function handle(): int
    {
        $integration = $this->option('integration');
        $once = $this->option('once');
        $interval = (int) $this->option('interval');
        do {
            WalletReconcileJob::dispatchSync($integration);
            if ($once) break;
            sleep($interval);
        } while (true);
        return Command::SUCCESS;
    }
}
