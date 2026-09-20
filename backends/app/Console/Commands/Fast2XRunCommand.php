<?php

namespace App\Console\Commands;

use App\Domain\Fast2X\BinanceSpotProvider;
use App\Domain\Fast2X\Fast2XService;
use Illuminate\Console\Command;
use Throwable;

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

        $symbol = $config->normalizedSymbol();
        $pollingIntervalMs = max(500, (int) config('fast2x.polling_interval_ms', 1000));
        $websocketRetrySeconds = max(10, (int) config('fast2x.websocket_retry_seconds', 30));
        $lastWebSocketRetryAt = 0;

        while (true) {
            $config = $config->fresh('state');

            // Heartbeat written every loop iteration, regardless of whether
            // this cycle actually fetched a tick — this is what
            // `fast2x:supervise` (routes/console.php, every minute) reads to
            // tell "process crashed/hung" apart from "process alive but the
            // exchange feed is temporarily stale", which only touches
            // last_price_update_at further down.
            $config->state?->update(['last_heartbeat_at' => now()]);

            if (! $provider->isWebSocketConnected() && (time() - $lastWebSocketRetryAt) >= $websocketRetrySeconds) {
                try {
                    $provider->configuredConnectWebSocket($config);
                    $config->state?->update(['websocket_status' => 'Connected']);
                    $this->info('Fast2X WebSocket loop connected for ' . $symbol);
                } catch (Throwable $e) {
                    $provider->usePollingFallback($e->getMessage());
                    $config->state?->update(['websocket_status' => 'Polling Fallback']);
                    $this->warn('Fast2X WebSocket unavailable for ' . $symbol . '. Falling back to REST polling: ' . $e->getMessage());
                }

                $lastWebSocketRetryAt = time();
            }

            if ($provider->isWebSocketConnected()) {
                $tick = $provider->readNextTick($symbol, 5);
                if ($tick) {
                    $latency = max(0, (int) floor(microtime(true) * 1000) - (int) $tick['eventTime']);
                    $fast2x->processTick($config->fresh('state'), $tick, $latency, true);
                }

                continue;
            }

            try {
                $ticker = $provider->configuredTicker($config);
                $config->state?->update(['websocket_status' => 'Polling Fallback']);
                $fast2x->processTick($config->fresh('state'), $ticker['tick'], (int) $ticker['latencyMs'], true);
            } catch (Throwable $e) {
                $config->state?->update(['websocket_status' => 'Disconnected']);
                $this->error('Fast2X polling failed for ' . $symbol . ': ' . $e->getMessage());
            }

            usleep($pollingIntervalMs * 1000);
        }
    }
}
