<?php

namespace App\Console\Commands;

use App\Support\OrderStatusPoller;
use Illuminate\Console\Command;

class OrderStatusPollCommand extends Command
{
    protected $signature = 'orders:poll-status {--limit=50}';
    protected $description = 'Compatibility poller for pending Binance provider orders.';

    public function handle(OrderStatusPoller $poller): int
    {
        $limit = (int) $this->option('limit');
        $summary = $poller->poll($limit);

        $this->info("Polled {$summary['polled']} orders, updated {$summary['updated']}, failed {$summary['failed']}");
        return Command::SUCCESS;
    }
}
