<?php

namespace App\Console\Commands;

use App\Domain\Fast2X\BinanceSpotProvider;
use App\Domain\Fast2X\Fast2XService;
use Illuminate\Console\Command;

class Fast2XRunCommand extends Command
{
    protected $signature = 'fast2x:run {workspace_id} {--symbol=}';
    protected $description = 'Run the Fast2X Binance Spot WebSocket paper trading loop.';

    public function handle(Fast2XService $fast2x, BinanceSpotProvider $provider): int
    {
        $config = $fast2x->getOrCreateConfig((string) $this->argument('workspace_id'));
        if ($this->option('symbol')) {
            $config = $fast2x->updateConfig($config, ['settings' => ['symbol' => strtoupper((string) $this->option('symbol'))]]);
        }

        $provider->connectWebSocket($config->normalizedSymbol());
        $this->info('Fast2X WebSocket loop connected for ' . $config->normalizedSymbol());

        while (true) {
            $tick = $provider->readNextTick($config->normalizedSymbol(), 5);
            if ($tick) {
                $latency = max(0, (int) floor(microtime(true) * 1000) - (int) $tick['eventTime']);
                $fast2x->processTick($config->fresh('state'), $tick, $latency, true);
            }
        }
    }
}
