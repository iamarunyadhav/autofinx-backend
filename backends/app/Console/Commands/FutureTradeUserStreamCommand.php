<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FutureTradeUserStreamCommand extends Command
{
    protected $signature = 'future-trade:user-stream
        {--workspace= : Limit to a single workspace}
        {--duration=55 : Bounded loop duration in seconds (0 = until exit)}
        {--reconnect-sleep=3 : Sleep seconds between reconnects}
        {--max-keepalive=1800 : Keep-alive interval in seconds (Binance recommends ≤1800)}';

    protected $description = 'Consume Binance Futures user-data stream(s) for Future Trade configs and update logical_trades + heartbeat.';

    public function handle(FutureTradeUserStreamService $userStream): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $duration = max(0, (int) $this->option('duration'));
        $reconnectSleep = max(1, (int) $this->option('reconnect-sleep'));
        $keepAliveEvery = max(60, (int) $this->option('max-keepalive'));
        $perConfigSlice = 10;

        $lockKey = 'autofinx_ft:user_stream_lock' . ($workspaceId !== '' ? ':' . $workspaceId : '');
        $lock = Cache::lock($lockKey, max(30, $duration + 10));
        if (! $lock->get()) {
            $this->warn('[FT USER STREAM SKIPPED] another consumer already holds the lock');
            return self::SUCCESS;
        }

        $startedAt = time();
        try {
            $configs = FutureTradeBotConfig::query()
                ->where('enable_live_trading', true)
                ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->get();
            if ($configs->isEmpty()) {
                $this->info('No live-enabled Future Trade configs to attach.');
                return self::SUCCESS;
            }

            // Bounded loop: keep one config alive at a time inside this process.
            // (Shared-host friendly — for multi-config support, run with --workspace
            //  per cron entry.)
            if ($configs->count() === 1) {
                $this->consumeOnce($userStream, $configs->first(), $startedAt, $duration, $reconnectSleep, $keepAliveEvery);

                return self::SUCCESS;
            }

            while ($duration === 0 || (time() - $startedAt) < $duration) {
                foreach ($configs as $config) {
                    $remaining = $duration > 0 ? max(0, $duration - (time() - $startedAt)) : $perConfigSlice;
                    if ($duration > 0 && $remaining <= 0) {
                        break 2;
                    }

                    $sliceDuration = $duration > 0
                        ? max(1, min($perConfigSlice, $remaining))
                        : $perConfigSlice;

                    $this->consumeOnce($userStream, $config, time(), $sliceDuration, $reconnectSleep, $keepAliveEvery);
                }
            }
        } finally {
            optional($lock)->release();
        }

        return self::SUCCESS;
    }

    private function consumeOnce(
        FutureTradeUserStreamService $userStream,
        FutureTradeBotConfig $config,
        int $startedAt,
        int $duration,
        int $reconnectSleep,
        int $keepAliveEvery,
    ): void {
        $listenKey = $userStream->ensureListenKey($config);
        if (! $listenKey) {
            $this->warn('[FT USER STREAM] ' . $config->workspace_id . ' — credentials missing or stream init failed');
            return;
        }
        $wsBase = rtrim($userStream->wsBase($config), '/') . '/ws/' . $listenKey;
        $client = new SimpleWebSocketClient();

        $reconnects = 0;
        $lastKeepAlive = time();
        $lastHeartbeat = 0;

        try {
            while (true) {
                if ($duration > 0 && (time() - $startedAt) >= $duration) {
                    break;
                }
                try {
                    $client->connect($wsBase);
                    $userStream->setStatus($config, FutureTradeUserStreamService::STATUS_CONNECTED);
                    $userStream->recordHeartbeat($config, 'connect');
                    $lastHeartbeat = time();
                    $stableConnectedSince = time();
                    $this->line(sprintf(
                        '[FT USER STREAM] connected — %s (reconnect #%d)',
                        $config->workspace_id,
                        $reconnects
                    ));

                    while (true) {
                        if ($duration > 0 && (time() - $startedAt) >= $duration) {
                            $client->close();
                            return;
                        }
                        if ((time() - $lastKeepAlive) >= $keepAliveEvery) {
                            $userStream->keepAlive($config);
                            $lastKeepAlive = time();
                        }
                        if ((time() - $lastHeartbeat) >= 5) {
                            $userStream->recordHeartbeat($config, 'loop');
                            $lastHeartbeat = time();
                        }
                        $frame = $client->readFrame(1);
                        if ($frame === null) {
                            continue;
                        }
                        if ($frame['type'] === 'close') {
                            $userStream->setStatus($config, FutureTradeUserStreamService::STATUS_DEGRADED, [
                                'last_error' => 'remote close',
                            ]);
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
                        try {
                            $userStream->ingestEvent($config, $payload);
                        } catch (Throwable $e) {
                            Log::warning('[FT USER STREAM INGEST ERROR]', [
                                'config_id' => $config->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Reset reconnect counter after 60 s of stable messages.
                        if ($reconnects > 0 && (time() - $stableConnectedSince) >= 60) {
                            $reconnects = 0;
                        }
                    }
                } catch (Throwable $e) {
                    $userStream->setStatus($config, FutureTradeUserStreamService::STATUS_DEGRADED, [
                        'last_error' => $e->getMessage(),
                    ]);
                    $this->warn(sprintf(
                        '[FT USER STREAM ERROR] %s (reconnect #%d): %s',
                        $config->workspace_id,
                        $reconnects + 1,
                        $e->getMessage()
                    ));
                } finally {
                    $client->close();
                }

                // Exponential backoff — no hard cap on reconnect attempts.
                // Supervisor restarts the process when --duration expires; the 5-reconnect
                // hard limit was preventing recovery after transient network outages.
                $reconnects++;
                $backoff = min(30, $reconnectSleep * (int) pow(2, min($reconnects - 1, 4)));
                $jitter   = rand(0, (int) ceil($backoff * 0.25));
                $sleepFor = $backoff + $jitter;
                $this->line(sprintf(
                    '[FT USER STREAM] Reconnecting in %d s (attempt %d)...',
                    $sleepFor,
                    $reconnects
                ));
                sleep($sleepFor);
            }
        } finally {
            $client->close();
        }
    }
}
