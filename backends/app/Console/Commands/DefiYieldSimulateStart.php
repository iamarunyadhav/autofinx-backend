<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Profit\DeFi\DeFiEngine;
use App\Models\ModuleSetting;

class DefiYieldSimulateStart extends Command
{
    protected $signature = 'defi:yield-simulate-start {--once} {--interval=3600} {--workspace=}';
    protected $description = 'Simulate DeFi positions (read-only)';

    public function handle(DeFiEngine $engine): int
    {
        $workspace = $this->option('workspace');
        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        do {
            $setting = ModuleSetting::where('workspace_id', $workspace)->where('module_key', 'defi')->first();
            if (($setting?->mode ?? 'off') !== 'off') {
                $engine->simulate($workspace ?? '', 'sim', 'USDC', 500);
                $this->info('DeFi position simulated');
            } else {
                $this->info('DeFi module off');
            }
            if ($once) {
                break;
            }
            sleep($interval);
        } while (true);
        return Command::SUCCESS;
    }
}
