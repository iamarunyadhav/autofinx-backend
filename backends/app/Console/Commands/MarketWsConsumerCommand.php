<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MarketWsConsumerCommand extends Command
{
    protected $signature = 'market:ws-consume
        {--exchange=binance}
        {--market-type=spot : spot|futures}
        {--workspace= : Limit active-bot symbol discovery to one workspace}
        {--bot= : Limit stream discovery to one ratio bot config id}
        {--symbols= : Comma-separated symbols; defaults to active ratio bot symbols}
        {--streams=trade,kline_1m,miniTicker : Binance stream suffixes}
        {--sleep=1}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--reconnect-sleep=3 : Reconnect delay in seconds}
        {--testnet : Use Binance testnet websocket base when available}
        {--once}
        {--store-ohlc}
        {--ohlc-intervals=1m : Comma-separated OHLC intervals to persist during --once polling fallback}
        {--no-dispatch : Do not dispatch ratio bot evaluations on feed updates}';

    protected $description = 'Consume Binance market websocket streams, with REST polling fallback for --once runs.';

    public function handle(RealtimeMarketFeedService $feeds): int
    {
        $marketType = strtolower((string) $this->option('market-type'));
        if (! in_array($marketType, ['spot', 'futures'], true)) {
            $this->error('market-type must be either spot or futures.');

            return self::FAILURE;
        }

        $symbols = $this->resolveSymbols($feeds, $marketType);
        if ($symbols === []) {
            $this->warn('No symbols resolved for market feed consumption.');

            return self::SUCCESS;
        }

        $streams = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            explode(',', (string) $this->option('streams'))
        )));
        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $dispatchEvaluations = ! (bool) $this->option('no-dispatch');
        $heartbeatPath = storage_path('framework/autofinx-live-feed-heartbeat.json');
        $lockKey = sprintf(
            'autofinx:runtime:market-ws-consume:%s:%s:%s',
            $marketType,
            md5(implode(',', $symbols)),
            md5(implode(',', $streams))
        );
        $lockTtl = (bool) $this->option('once')
            ? 120
            : ($maxRuntime > 0 ? $maxRuntime + 60 : 86400);
        $lock = Cache::lock($lockKey, $lockTtl);

        if (! $lock->get()) {
            if (! $this->heartbeatFresh($heartbeatPath)) {
                $this->warn('Stale market ws lock detected without a fresh heartbeat; force releasing lock and retrying.');
                Cache::lock($lockKey, 1)->forceRelease();
                $lock = Cache::lock($lockKey, $lockTtl);
            }

            if (! $lock->get()) {
                $this->warn('Another market ws consumer is already running for this market/symbol/stream scope.');

                return self::SUCCESS;
            }
        }

        try {
            if ((bool) $this->option('once')) {
                return $this->pollOnce($feeds, $symbols, $marketType, $dispatchEvaluations);
            }

            $reconnectSleepBase = max(1, (int) $this->option('reconnect-sleep'));
            $startedAt = time();
            $testnet = (bool) $this->option('testnet');
            $client = new SimpleWebSocketClient;

            // Reconnect tracking for exponential backoff and heartbeat reporting.
            $reconnectCount = 0;
            $msgCount = 0;
            $connectedAt = null;
            $lastHeartbeatWrite = 0;
            $lastPingAt = 0;

            $this->info(sprintf(
                'Starting realtime market feed consumer market_type=%s symbols=%s streams=%s',
                $marketType,
                implode(',', $symbols),
                implode(',', $streams)
            ));

            try {
                while (true) {
                    if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                        $this->info('Max runtime reached, stopping market feed consumer.');
                        break;
                    }

                    try {
                        $wsUrl = $feeds->buildStreamUrl($symbols, $marketType, $streams, $testnet);
                        $this->line(sprintf(
                            '[WS] Connecting: market_type=%s symbols=%s streams=%s',
                            $marketType,
                            implode(',', $symbols),
                            implode(',', $streams)
                        ));
                        $client->connect($wsUrl);
                        $connectedAt = time();
                        $stableConnectedSince = time();
                        $lastPingAt = time();
                        $this->line(sprintf(
                            '[WS] Connected to Binance market websocket. reconnect_count=%d',
                            $reconnectCount
                        ));

                        while (true) {
                            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                                $client->close();
                                break 2;
                            }

                            // Proactive client→server ping every 30 s.
                            // Binance closes connections that go silent; sending a ping
                            // prevents the server from treating the stream as idle/dead.
                            $now = time();
                            if ($now - $lastPingAt >= 30) {
                                try {
                                    $client->sendPing();
                                } catch (\Throwable) {
                                    // If sendPing fails, the connection is dead.
                                    // readFrame() will throw on the next call below.
                                }
                                $lastPingAt = $now;
                            }

                            $frame = $client->readFrame(1);
                            if ($frame === null) {
                                continue;
                            }
                            if ($frame['type'] === 'close') {
                                throw new RuntimeException('Remote websocket closed the market stream.');
                            }
                            if ($frame['type'] !== 'text') {
                                continue;
                            }

                            $payload = json_decode((string) $frame['payload'], true);
                            if (! is_array($payload)) {
                                continue;
                            }

                            $feeds->ingest($payload, $marketType, $dispatchEvaluations);
                            $msgCount++;

                            // Reset backoff counter after 60 s of stable messages.
                            if ($reconnectCount > 0 && (time() - $stableConnectedSince) >= 60) {
                                $reconnectCount = 0;
                            }

                            // Write heartbeat file at most once per second — the watchdog and UI
                            // use this to detect a stale live-feed without relying on log mtime.
                            $now = time();
                            if ($now - $lastHeartbeatWrite >= 1) {
                                $lastHeartbeatWrite = $now;
                                $streamName = (string) ($payload['stream'] ?? '');
                                $symbolName = strtoupper((string) (
                                    $payload['data']['s'] ?? $payload['s'] ?? ''
                                ));
                                @file_put_contents($heartbeatPath, json_encode([
                                    'last_message_at' => date('c', $now),
                                    'last_message_epoch' => $now,
                                    'symbol' => $symbolName,
                                    'stream' => $streamName,
                                    'reconnect_count' => $reconnectCount,
                                    'message_count' => $msgCount,
                                    'connected_at' => $connectedAt ? date('c', $connectedAt) : null,
                                    'market_type' => $marketType,
                                    'symbols' => $symbols,
                                ], JSON_UNESCAPED_SLASHES));
                            }
                        }
                    } catch (\Throwable $e) {
                        // Guard: markDisconnected hits the DB; a DB outage must not kill
                        // the reconnect loop and force a supervisor process restart.
                        try {
                            $feeds->markDisconnected($symbols, $marketType, $e->getMessage());
                        } catch (\Throwable) {
                            // DB unavailable — swallow and keep reconnecting.
                        }
                        $this->warn(sprintf(
                            '[WS] Market websocket error (reconnect #%d): %s',
                            $reconnectCount + 1,
                            $e->getMessage()
                        ));
                    } finally {
                        $client->close();
                        $connectedAt = null;
                    }

                    $reconnectCount++;

                    // Circuit breaker: if the server has rejected 8+ consecutive connections
                    // (Binance IP-rate-limiting after rapid reconnects), back off for 5 minutes
                    // instead of hammering the endpoint every few seconds — which makes the ban
                    // last longer.
                    if ($reconnectCount > 0 && $reconnectCount % 8 === 0) {
                        $this->warn(sprintf(
                            '[WS] Circuit breaker: %d consecutive failures — pausing 300 s to let Binance lift the connection-rate limit.',
                            $reconnectCount
                        ));
                        sleep(300);
                        $this->line('[WS] Circuit breaker pause over, resuming reconnect loop.');
                    }

                    // Exponential backoff with ±25 % jitter:
                    // attempt 1→2s, 2→4s, 3→8s, 4+→30s  (scaled by reconnectSleepBase)
                    $backoff = min(15 * $reconnectSleepBase, $reconnectSleepBase * (int) pow(2, min($reconnectCount, 4)));
                    $jitter = rand(0, (int) ceil($backoff * 0.25));
                    $sleepFor = $backoff + $jitter;
                    $this->line(sprintf('[WS] Reconnecting in %d s (backoff attempt %d)...', $sleepFor, $reconnectCount));
                    sleep($sleepFor);
                }
            } finally {
                $client->close();
            }

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param  string[]  $symbols
     */
    private function pollOnce(
        RealtimeMarketFeedService $feeds,
        array $symbols,
        string $marketType,
        bool $dispatchEvaluations,
    ): int {
        $apiBase = $this->apiBaseUrl($marketType);
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $storeOhlc = (bool) $this->option('store-ohlc');
        $restGuard = new BinanceRestCircuitBreaker;

        do {
            foreach ($symbols as $symbol) {
                try {
                    $restGuard->assertAvailable();
                    $restGuard->throttle();
                    $ticker = Http::timeout(10)->get($apiBase.$this->tickerPath($marketType), ['symbol' => $symbol]);
                    $restGuard->recordResponse($ticker);
                    if ($ticker->failed()) {
                        $this->warn("Ticker fetch failed for {$symbol}: {$ticker->body()}");
                        if ($this->isBinanceRateLimitedResponse($ticker)) {
                            $this->error('Binance REST rate limit/IP ban detected; stopping REST fallback to avoid extending the ban.');

                            return self::FAILURE;
                        }

                        continue;
                    }

                    $price = (float) ($ticker->json('price') ?? $ticker->json('lastPrice') ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    $eventTime = (int) floor(microtime(true) * 1000);
                    $feeds->ingest([
                        'e' => '24hrMiniTicker',
                        's' => $symbol,
                        'c' => (string) $price,
                        'E' => $eventTime,
                    ], $marketType, $dispatchEvaluations);

                    if ($storeOhlc) {
                        $this->persistLatestCandles($feeds, $apiBase, $marketType, $symbol, $dispatchEvaluations);
                    }
                } catch (\Throwable $e) {
                    $this->warn("Feed refresh failed for {$symbol}: {$e->getMessage()}");
                }
            }

            if ((bool) $this->option('once')) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);

        return self::SUCCESS;
    }

    private function persistLatestCandles(
        RealtimeMarketFeedService $feeds,
        string $apiBase,
        string $marketType,
        string $symbol,
        bool $dispatchEvaluations,
    ): void {
        $intervals = array_values(array_filter(array_map(
            static fn (string $interval) => trim(strtolower($interval)),
            explode(',', (string) $this->option('ohlc-intervals'))
        )));

        foreach ($intervals as $interval) {
            $klines = Http::timeout(10)->get($apiBase.$this->klinePath($marketType), [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => 1,
            ]);
            (new BinanceRestCircuitBreaker)->recordResponse($klines);
            if ($klines->failed()) {
                if ($this->isBinanceRateLimitedResponse($klines)) {
                    $this->warn("Skipping candle polling for {$symbol}: Binance REST rate limit/IP ban detected.");

                    return;
                }

                continue;
            }

            $row = $klines->json('0');
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $feeds->ingest([
                'e' => 'kline',
                's' => $symbol,
                'E' => (int) ($row[6] ?? floor(microtime(true) * 1000)),
                'k' => [
                    't' => (int) $row[0],
                    'T' => (int) ($row[6] ?? $row[0]),
                    'i' => $interval,
                    'o' => (string) $row[1],
                    'h' => (string) $row[2],
                    'l' => (string) $row[3],
                    'c' => (string) $row[4],
                    'v' => (string) $row[5],
                    'x' => true,
                ],
            ], $marketType, $dispatchEvaluations);
        }
    }

    /**
     * @return string[]
     */
    private function resolveSymbols(RealtimeMarketFeedService $feeds, string $marketType): array
    {
        $symbolsOption = trim((string) $this->option('symbols'));
        if ($symbolsOption !== '') {
            return array_values(array_filter(array_map(
                static fn (string $symbol) => strtoupper(trim($symbol)),
                explode(',', $symbolsOption)
            )));
        }

        $botId = trim((string) $this->option('bot'));
        $workspaceId = trim((string) $this->option('workspace'));

        $resolved = $feeds->selectedSymbols($marketType, $workspaceId !== '' ? $workspaceId : null, $botId !== '' ? $botId : null);
        if ($resolved !== []) {
            return $resolved;
        }

        return $marketType === 'futures' ? ['BTCUSDT'] : ['BTCUSDT', 'ETHUSDT'];
    }

    private function apiBaseUrl(string $marketType): string
    {
        if ($marketType === 'futures') {
            return rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/');
        }

        return rtrim((string) config('services.binance.api_url', 'https://api.binance.com'), '/');
    }

    private function tickerPath(string $marketType): string
    {
        return $marketType === 'futures'
            ? '/fapi/v1/ticker/price'
            : '/api/v3/ticker/price';
    }

    private function isBinanceRateLimitedResponse(Response $response): bool
    {
        if (in_array($response->status(), [418, 429], true)) {
            return true;
        }

        return (int) ($response->json('code') ?? 0) === -1003;
    }

    private function klinePath(string $marketType): string
    {
        return $marketType === 'futures'
            ? '/fapi/v1/klines'
            : '/api/v3/klines';
    }

    private function heartbeatFresh(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;

        return $epoch > 0 && (time() - $epoch) <= 30;
    }
}
