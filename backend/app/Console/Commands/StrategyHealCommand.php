<?php

namespace App\Console\Commands;

use App\Support\StrategyLifecycleService;
use Illuminate\Console\Command;

class StrategyHealCommand extends Command
{
    protected $signature = 'strategy:heal';
    protected $description = 'Auto-restart strategies after maintenance/connection recovery and queue failed order retries.';

    public function handle(StrategyLifecycleService $service): int
    {
        $summary = $service->heal();

        $this->info('Strategies restarted: ' . count($summary['restarted']));
        $this->info('Skipped (not healthy yet): ' . count($summary['skipped']));
        $this->info('Orders queued for retry: ' . count($summary['order_retries']));

        return Command::SUCCESS;
    }
}
