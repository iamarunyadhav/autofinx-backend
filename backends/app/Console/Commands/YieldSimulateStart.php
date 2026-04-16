<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Profit\Yield\YieldEngine;
use App\Models\ModuleSetting;

class YieldSimulateStart extends Command
{
    protected $signature = 'yield:simulate-start {--once} {--interval=3600} {--workspace=}';
    protected $description = 'Simulate yield accrual (paper)';

    public function handle(YieldEngine $engine): int
    {
        $workspace = $this->option('workspace');
        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        do {
            $setting = ModuleSetting::where('workspace_id', $workspace)->where('module_key', 'yield')->first();
            if (($setting?->mode ?? 'off') !== 'off') {
                $engine->accrue($workspace ?? '', 800, 'USDT');
                $this->info('Yield accrued');
            } else {
                $this->info('Yield module off');
            }
            if ($once) {
                break;
            }
            sleep($interval);
        } while (true);
        return Command::SUCCESS;
    }
}
