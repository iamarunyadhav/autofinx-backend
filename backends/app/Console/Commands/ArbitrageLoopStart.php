<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Profit\Arbitrage\ArbitrageEngine;
use App\Models\ModuleSetting;
use App\Models\ArbitrageOpportunity;

class ArbitrageLoopStart extends Command
{
    protected $signature = 'arbitrage:loop-start {--once} {--interval=30} {--workspace=}';
    protected $description = 'Scan arbitrage opportunities (paper by default)';

    public function handle(ArbitrageEngine $engine): int
    {
        $workspace = $this->option('workspace');
        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        do {
            $setting = ModuleSetting::where('workspace_id', $workspace)->where('module_key', 'arbitrage')->first();
            $mode = $setting?->mode ?? 'off';
            if ($mode === 'off') {
                $this->info('Arbitrage module off');
            } else {
                $tickers = [
                    'BTCUSDT' => ['binance' => 43000, 'coinbase' => 43200],
                    'ETHUSDT' => ['binance' => 2300, 'coinbase' => 2325],
                ];
                $ops = $engine->findOpportunities($tickers);
                foreach ($ops as $op) {
                    $engine->record($workspace, $op);
                    $this->info("Opportunity {$op['symbol']} {$op['spread']}%");
                }
            }
            if ($once) {
                break;
            }
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
