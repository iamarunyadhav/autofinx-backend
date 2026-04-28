<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceFuturesUserStreamService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Domain\Trading\ReconciliationService;
use App\Models\Integration;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;

class BinanceFuturesUserStreamCommand extends Command
{
    protected $signature = 'trading:binance-futures-user-stream
        {--workspace= : Workspace UUID}
        {--integration= : Binance integration UUID}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--reconnect-sleep=3 : Reconnect delay in seconds}
        {--stop-on-exit : Close listen key on command exit}';

    protected $description = 'Consume Binance USD-M futures user stream websocket and reconcile execution updates.';

    public function handle(
        BinanceFuturesUserStreamService $streams,
        ReconciliationService $reconciliation,
        FuturesPositionSyncService $positions,
        FuturesConfigService $configs,
    ): int {
        $integration = $this->resolveIntegration();
        if (! $integration) {
            $this->error('No active Binance integration found for futures user stream.');

            return self::FAILURE;
        }

        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $reconnectSleep = max(1, (int) $this->option('reconnect-sleep'));
        $stopOnExit = (bool) $this->option('stop-on-exit');
        $startedAt = time();
        $keepAliveEverySec = max(60, (int) env('BINANCE_FUTURES_USER_STREAM_KEEPALIVE_SEC', 1800));
        $lastKeepAlive = 0;
        $client = new SimpleWebSocketClient();

        try {
            while (true) {
                if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                    $this->info('Max runtime reached, stopping futures user-stream consumer.');
                    break;
                }

                try {
                    $stream = $streams->getOrStart($integration);
                    $client->connect((string) $stream['ws_url']);
                    $this->line('Connected to Binance futures user stream.');
                    $lastKeepAlive = time();

                    while (true) {
                        if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                            $client->close();
                            break 2;
                        }

                        if ((time() - $lastKeepAlive) >= $keepAliveEverySec) {
                            $streams->keepAlive($integration);
                            $lastKeepAlive = time();
                        }

                        $frame = $client->readFrame(1);
                        if ($frame === null) {
                            continue;
                        }
                        if ($frame['type'] === 'close') {
                            $streams->markError($integration, 'Remote websocket closed the futures user stream.');
                            $client->close();
                            break;
                        }
                        if ($frame['type'] !== 'text') {
                            continue;
                        }

                        $payload = json_decode((string) $frame['payload'], true);
                        if (! is_array($payload)) {
                            continue;
                        }

                        $streams->markFrameActivity($integration);
                        $order = $reconciliation->ingestExchangeEvent($payload);
                        if ($order) {
                            $positions->sync((string) $integration->workspace_id, $integration, $configs->effective((string) $integration->workspace_id));
                            $this->line(sprintf('Reconciled futures order %s => %s', (string) $order->id, (string) $order->status));
                        }
                    }
                } catch (\Throwable $e) {
                    $streams->markError($integration, $e->getMessage());
                    $this->warn('Futures user stream error: ' . $e->getMessage());
                } finally {
                    $client->close();
                }

                sleep($reconnectSleep);
            }
        } finally {
            if ($stopOnExit) {
                try {
                    $streams->stop($integration);
                } catch (\Throwable) {
                    // ignore cleanup failures
                }
            }
        }

        return self::SUCCESS;
    }

    private function resolveIntegration(): ?Integration
    {
        $integrationId = trim((string) $this->option('integration'));
        if ($integrationId !== '') {
            return Integration::query()
                ->whereKey($integrationId)
                ->where('provider_key', 'binance')
                ->where('status', 'active')
                ->first();
        }

        $workspaceId = trim((string) $this->option('workspace'));

        return Integration::query()
            ->where('provider_key', 'binance')
            ->where('status', 'active')
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->latest('updated_at')
            ->first();
    }
}
