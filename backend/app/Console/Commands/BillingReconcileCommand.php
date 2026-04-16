<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\Billing\ReconcilePendingPaymentsJob;

class BillingReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile';
    protected $description = 'Reconcile pending payments with gateways';

    public function handle(): int
    {
        ReconcilePendingPaymentsJob::dispatchSync();
        $this->info('Reconciliation completed');
        return self::SUCCESS;
    }
}
