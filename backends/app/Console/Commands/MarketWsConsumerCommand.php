<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Domain\Market\BinanceFuturesStreamRouter;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\Market\MarketTransportCoalescer;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class MarketWsConsumerCommand extends Command
{
    protected $signature = 'market:ws-consume
        {--exchange=binance}
        {--market-type=spot : spot|futures}
        {--lane= : Futures-only transport lane (public|market). See BinanceFuturesStreamRouter. Ignored for spot.}
        {--workspace= : Limit active-bot symbol discovery to one workspace}
        {--bot= : Limit stream discovery to one ratio bot config id}
        {--symbols= : Comma-separated symbols; defaults to active ratio bot symbols}
        {--streams=depth5@500ms,kline_1m : Binance stream suffixes}
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

        $lane = trim((string) $this->option('lane')) !== '' ? strtolower(trim((string) $this->option('lane'))) : null;
        if ($lane !== null) {
            if ($marketType !== 'futures') {
                $this->error('--lane is only meaningful for --market-type=futures (see BinanceFuturesStreamRouter).');

                return self::FAILURE;
            }
            if (! in_array($lane, BinanceFuturesStreamRouter::lanes(), true)) {
                $this->error(sprintf('--lane must be one of: %s', implode('|', BinanceFuturesStreamRouter::lanes())));

                return self::FAILURE;
            }
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

        if ($lane !== null) {
            // Lane-aware invocation: the caller (normally
            // FutureTradeLiveFeedCommand's orchestrator) passes the FULL
            // configured futures stream superset; this process only ever
            // handles the subset that belongs to its own lane. An unroutable
            // stream family fails the whole process rather than silently
            // dropping or misrouting it — see BinanceFuturesStreamRouter's
            // docblock.
            $streams = (new BinanceFuturesStreamRouter)->streamsForLane($streams, $lane);
            if ($streams === []) {
                $this->error(sprintf('No streams classified for lane=%s from --streams=%s.', $lane, (string) $this->option('streams')));

                return self::FAILURE;
            }
        }

        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        // Lane-aware runs are deliberately single-purpose (public carries its
        // own depth stream, market carries only klines) — the combined-socket
        // "always guarantee a quote stream" fallback below only applies to
        // spot and any legacy non-lane futures invocation.
        if (! (bool) $this->option('once') && $lane === null) {
            $streams = $this->ensureRealtimeHeartbeatStream($streams, $marketType);
        }
        $dispatchEvaluations = ! (bool) $this->option('no-dispatch');
        $provider = strtolower((string) $this->option('exchange') ?: 'binance');
        $workerId = (string) Str::uuid();
        $hostname = gethostname() ?: php_uname('n');
        // Own heartbeat file, separate per market type (and, for futures,
        // per lane) — this must NOT share autofinx-live-feed-heartbeat.json
        // with future-trade:live-feed. That daemon runs 24/7 and keeps its
        // file permanently "fresh", so sharing it here caused
        // market:ws-consume to see a false-positive "another consumer is
        // active" forever and never take over the lock.
        $heartbeatPath = self::heartbeatPathFor($marketType, $lane);
        $startedAt = time();
        $lockKey = self::lockKeyFor($provider, $marketType, $lane);
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
                $this->warn('market_feed_singleton_already_owned');
                Log::warning('[MarketData] Singleton lock rejected', [
                    'event' => 'singleton_lock_rejected',
                    'provider' => $provider,
                    'market_type' => $marketType,
                    'worker_id' => $workerId,
                    'lock_key' => $lockKey,
                ]);

                return self::SUCCESS;
            }
        }

        try {
            if ((bool) $this->option('once')) {
                return $this->pollOnce($feeds, $symbols, $marketType, $dispatchEvaluations);
            }

            $this->writeWorkerMetadata($provider, $marketType, $workerId, null, $hostname, $startedAt, $symbols, $streams, 'STARTING', 0, null, $lane);
            Log::info('[MarketData] Worker started', [
                'event' => 'worker_started',
                'provider' => $provider,
                'market_type' => $marketType,
                'worker_id' => $workerId,
                'pid' => getmypid(),
                'hostname' => $hostname,
                'subscriptions' => $symbols,
                'streams' => $streams,
            ]);

            $reconnectSleepBase = max(1, (int) $this->option('reconnect-sleep'));
            $testnet = (bool) $this->option('testnet');
            $client = new SimpleWebSocketClient;

            // Reconnect tracking for exponential backoff and heartbeat reporting.
            $reconnectCount = 0;
            $msgCount = 0;
            $connectedAt = null;
            $lastHeartbeatWrite = 0;
            $lastPingAt = 0;
            $lastSymbolResolutionAt = time();
            $perSymbolHeartbeat = [];
            // Symbols are only an option override (--symbols=) when explicitly
            // set; otherwise they come from active ratio bot discovery and can
            // change while this daemon keeps running (a new bot on a new
            // symbol). Re-resolving is only meaningful in the discovery case.
            $symbolsPinned = trim((string) $this->option('symbols')) !== '';

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

                    if (! $symbolsPinned) {
                        $freshSymbols = $this->resolveSymbols($feeds, $marketType);
                        if ($freshSymbols !== [] && $this->symbolsDiffer($symbols, $freshSymbols)) {
                            $this->line(sprintf(
                                '[WS] Active bot symbol set changed before reconnect: %s -> %s',
                                implode(',', $symbols),
                                implode(',', $freshSymbols)
                            ));
                            $symbols = $freshSymbols;
                        }
                        $lastSymbolResolutionAt = time();
                    }

                    try {
                        $wsUrl = $feeds->buildStreamUrlForLane($symbols, $marketType, $streams, $lane, $testnet);
                        $this->line(sprintf(
                            '[WS] Connecting: market_type=%s symbols=%s streams=%s',
                            $marketType,
                            implode(',', $symbols),
                            implode(',', $streams)
                        ));
                        $connectionId = (string) Str::uuid();
                        $this->writeWorkerMetadata($provider, $marketType, $workerId, $connectionId, $hostname, $startedAt, $symbols, $streams, 'CONNECTING', $reconnectCount, null, $lane);
                        Log::info('[MarketData] Socket connecting', [
                            'event' => 'socket_connecting',
                            'provider' => $provider,
                            'market_type' => $marketType,
                            'worker_id' => $workerId,
                            'connection_id' => $connectionId,
                            'subscriptions' => $symbols,
                            'streams' => $streams,
                        ]);
                        $client->connect($wsUrl);
                        $transport = new MarketTransportCoalescer(
                            max(10, (int) config('autofinx.runtime.market_transport_flush_interval_ms', 100))
                        );
                        $connectedAt = time();
                        $stableConnectedSince = time();
                        // Transport backlog frames are discarded by ingest().
                        // Do not reconnect on a small run of discarded frames:
                        // reconnecting against the same backlog creates a
                        // reconnect storm and eventually triggers Binance's
                        // connection-rate circuit breaker. Reconnect only if
                        // no valid market frame has arrived for a sustained
                        // interval on this socket.
                        $lastAcceptedFrameAt = time();
                        $staleFrameWarningAt = 0;
                        $lastPingAt = time();
                        $this->writeWorkerMetadata($provider, $marketType, $workerId, $connectionId, $hostname, $startedAt, $symbols, $streams, 'CONNECTED_AWAITING_TICK', $reconnectCount, null, $lane);
                        $this->line(sprintf(
                            '[WS] Connected to Binance market websocket. reconnect_count=%d',
                            $reconnectCount
                        ));

                        while (true) {
                            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                                $client->close();
                                break 2;
                            }

                            // A new ratio bot on a new symbol otherwise gets no live
                            // data until this daemon happens to reconnect (or is
                            // restarted by an operator) — resolveSymbols() only ran
                            // once, before this connection was opened. Re-check the
                            // active-bot symbol set periodically and force a clean
                            // reconnect (picking up the fresh set at the top of the
                            // outer loop) when it has actually changed.
                            $now = time();
                            if (! $symbolsPinned && ($now - $lastSymbolResolutionAt) >= 60) {
                                $lastSymbolResolutionAt = $now;
                                $freshSymbols = $this->resolveSymbols($feeds, $marketType);
                                if ($freshSymbols !== [] && $this->symbolsDiffer($symbols, $freshSymbols)) {
                                    $this->line(sprintf(
                                        '[WS] Active bot symbol set changed mid-connection: %s -> %s; reconnecting to subscribe.',
                                        implode(',', $symbols),
                                        implode(',', $freshSymbols)
                                    ));
                                    Log::info('[MarketData] Resubscribing after active symbol set change', [
                                        'event' => 'symbol_set_changed',
                                        'market_type' => $marketType,
                                        'worker_id' => $workerId,
                                        'previous_symbols' => $symbols,
                                        'new_symbols' => $freshSymbols,
                                    ]);
                                    $symbols = $freshSymbols;
                                    $client->close();
                                    $connectedAt = null;
                                    break;
                                }
                            }

                            // Proactive client→server ping every 30 s.
                            // Binance closes connections that go silent; sending a ping
                            // prevents the server from treating the stream as idle/dead.
                            if ($now - $lastPingAt >= 30) {
                                try {
                                    $client->sendPing();
                                } catch (\Throwable) {
                                    // If sendPing fails, the connection is dead.
                                    // readFrame() will throw on the next call below.
                                }
                                $lastPingAt = $now;
                            }

                            // A short idle poll lets the bounded coalescer flush
                            // on time even for a quiet symbol. The websocket
                            // client still uses its longer payload deadline once
                            // a frame header has arrived.
                            $frame = $client->readFrame(0.05);
                            $payloads = [];
                            if ($frame === null) {
                                $payloads = $transport->drainIfDue();
                            } elseif ($frame['type'] === 'close') {
                                throw new RuntimeException('Remote websocket closed the market stream.');
                            } elseif ($frame['type'] === 'text') {
                                $decoded = json_decode((string) $frame['payload'], true);
                                if (is_array($decoded)) {
                                    $payloads = $transport->offer($decoded);
                                }
                            }
                            if ($payloads === []) {
                                continue;
                            }

                            $accepted = false;
                            $payload = $payloads[array_key_last($payloads)];
                            foreach ($payloads as $candidate) {
                                $accepted = $feeds->ingest($candidate, $marketType, $dispatchEvaluations, [
                                    'worker_id' => $workerId,
                                    'connection_id' => $connectionId,
                                    'pid' => getmypid(),
                                    'hostname' => $hostname,
                                    'subscriptions' => $symbols,
                                ]) || $accepted;
                            }
                            if (! $accepted) {
                                $staleAge = time() - $lastAcceptedFrameAt;
                                if ($staleAge >= 30) {
                                    throw new RuntimeException('No valid market frame accepted for 30 seconds; reconnecting before accepting execution data.');
                                }

                                if ($staleFrameWarningAt === 0 || (time() - $staleFrameWarningAt) >= 10) {
                                    $staleFrameWarningAt = time();
                                    $this->warn(sprintf(
                                        '[WS] Discarding stale/invalid market frame; valid frame age=%ds.',
                                        $staleAge
                                    ));
                                }

                                continue;
                            }
                            $lastAcceptedFrameAt = time();
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
                                $eventTimeMs = (int) ($payload['data']['E'] ?? $payload['E'] ?? $payload['data']['T'] ?? $payload['T'] ?? 0);
                                if ($symbolName !== '') {
                                    $perSymbolHeartbeat[$symbolName] = [
                                        'last_message_at' => date('c', $now),
                                        'last_message_epoch' => $now,
                                        'stream' => $streamName,
                                        'last_exchange_event_time' => $eventTimeMs > 0 ? date('c', (int) floor($eventTimeMs / 1000)) : null,
                                        'exchange_to_backend_latency_ms' => $eventTimeMs > 0 ? max(0, (int) round((microtime(true) * 1000) - $eventTimeMs)) : null,
                                    ];
                                }
                                $heartbeatSnapshot = [
                                    'server_now' => date('c', $now),
                                    'last_message_at' => date('c', $now),
                                    'last_message_epoch' => $now,
                                    'age_seconds' => 0,
                                    'symbol' => $symbolName,
                                    'stream' => $streamName,
                                    'lane' => $lane,
                                    'reconnect_count' => $reconnectCount,
                                    'message_count' => $msgCount,
                                    'connected_at' => $connectedAt ? date('c', $connectedAt) : null,
                                    'market_type' => $marketType,
                                    'worker_id' => $workerId,
                                    'connection_id' => $connectionId,
                                    'pid' => getmypid(),
                                    'hostname' => $hostname,
                                    'symbols' => $symbols,
                                    'streams' => $streams,
                                    'per_symbol' => $perSymbolHeartbeat,
                                    'last_exchange_event_time' => $eventTimeMs > 0 ? date('c', (int) floor($eventTimeMs / 1000)) : null,
                                    'exchange_to_backend_latency_ms' => $eventTimeMs > 0 ? max(0, (int) round((microtime(true) * 1000) - $eventTimeMs)) : null,
                                    'webhook_signal_latency_ms' => null,
                                    'transport' => $transport->stats(),
                                ];
                                @file_put_contents($heartbeatPath, json_encode($heartbeatSnapshot, JSON_UNESCAPED_SLASHES));
                                if ($marketType === 'futures' && $lane !== null) {
                                    // Compatibility shim: RobotSessionService, FutureTradeRuntimeConnectivityService,
                                    // FutureTradeLiveFeedManager, TradingRuntimeHealthService, and
                                    // FutureTradeRuntimeStatusCommand all still read the single combined
                                    // autofinx-market-ws-heartbeat-futures.json file directly. Keep it alive
                                    // and accurate (merged across both lanes) so none of those real
                                    // consumers need to change or regress.
                                    $this->writeFuturesAggregateHeartbeat($lane, $heartbeatSnapshot);
                                }
                                $this->writeWorkerMetadata($provider, $marketType, $workerId, $connectionId, $hostname, $startedAt, $symbols, $streams, 'LIVE', $reconnectCount, $symbolName, $lane);
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
                        $this->writeWorkerMetadata($provider, $marketType, $workerId, $connectionId ?? null, $hostname, $startedAt, $symbols, $streams, 'RECOVERING', $reconnectCount, null, $lane);
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
            $this->writeWorkerMetadata($provider, $marketType, $workerId, null, $hostname, $startedAt, $symbols, $streams, 'STOPPING', 0, null, $lane);
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
            // limit=2: the newest kline is still forming. Only the one before
            // it is closed; storing the forming one marked closed wrote
            // half-built candles.
            $klines = Http::timeout(10)->get($apiBase.$this->klinePath($marketType), [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => 2,
            ]);
            (new BinanceRestCircuitBreaker)->recordResponse($klines);
            if ($klines->failed()) {
                if ($this->isBinanceRateLimitedResponse($klines)) {
                    $this->warn("Skipping candle polling for {$symbol}: Binance REST rate limit/IP ban detected.");

                    return;
                }

                continue;
            }

            $nowMs = (int) floor(microtime(true) * 1000);
            $row = collect((array) $klines->json())
                ->filter(fn ($kline) => is_array($kline) && count($kline) >= 7 && (int) $kline[6] < $nowMs)
                ->last();
            if (! is_array($row)) {
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

    /**
     * @param string[] $current
     * @param string[] $fresh
     */
    private function symbolsDiffer(array $current, array $fresh): bool
    {
        $normalize = static fn (array $symbols): array => collect($symbols)
            ->map(static fn (string $symbol) => strtoupper(trim($symbol)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalize($current) !== $normalize($fresh);
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

    /**
     * Execution needs a symbol-scoped bid/ask source, but it must not silently
     * add a second high-volume quote stream. Operators may choose either
     * bookTicker (real-time) or depth5@500ms (bounded-rate partial depth).
     *
     * @param string[] $streams
     * @return string[]
     */
    private function ensureRealtimeHeartbeatStream(array $streams, string $marketType): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            $streams
        )));

        $lower = array_map(static fn (string $stream) => strtolower($stream), $normalized);
        $hasQuoteStream = false;
        foreach ($lower as $stream) {
            if ($stream === 'bookticker' || str_starts_with($stream, 'depth')) {
                $hasQuoteStream = true;
                break;
            }
        }
        if (! $hasQuoteStream) {
            $normalized[] = 'bookTicker';
        }
        // Keep the shared socket limited to symbol-scoped streams.  The
        // global futures mark-price array and ticker stream add traffic but
        // do not improve the freshness guard; on long-lived combined sockets
        // they also increase reconnect risk.  Futures mark price is already
        // available through the symbol-scoped feed when explicitly requested.

        return $normalized;
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

    private function waitUntilHeartbeatStale(string $path, int $startedAt, int $maxRuntime): void
    {
        while ($this->heartbeatFresh($path)) {
            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                return;
            }

            sleep(5);
        }
    }

    /**
     * @param string[] $symbols
     */
    private function writeWorkerMetadata(
        string $provider,
        string $marketType,
        string $workerId,
        ?string $connectionId,
        string $hostname,
        int $startedAt,
        array $symbols,
        array $streams,
        string $status,
        int $reconnectCount,
        ?string $lastSymbol = null,
        ?string $lane = null,
    ): void {
        try {
            $payload = json_encode([
                'worker_id' => $workerId,
                'connection_id' => $connectionId,
                'pid' => getmypid(),
                'hostname' => $hostname,
                'started_at' => date('c', $startedAt),
                'last_heartbeat_at' => now()->toIso8601String(),
                'last_message_at' => $lastSymbol ? now()->toIso8601String() : null,
                'last_symbol' => $lastSymbol,
                'lane' => $lane,
                'reconnect_count' => $reconnectCount,
                'subscriptions' => array_values($symbols),
                'streams' => array_values($streams),
                'status' => $status,
            ], JSON_UNESCAPED_SLASHES);

            // Legacy combined key, written unconditionally (including by
            // each futures lane) so existing readers (AutoFinxRuntimeHealthCommand,
            // AutoFinxMarketFeedCleanupCommand's wildcard sweep) keep working
            // without modification -- it only ever needs to answer "is some
            // futures worker alive", which both lanes independently satisfy.
            Redis::setex(sprintf('autofinx:market-feed:%s:%s:worker', $provider, $marketType), 90, $payload);

            if ($lane !== null) {
                Redis::setex(sprintf('autofinx:market-feed:%s:%s:%s:worker', $provider, $marketType, $lane), 90, $payload);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Single source of truth for the futures transport-lane singleton lock
     * key. Spot (and any legacy non-lane futures invocation) keeps the
     * original unsuffixed key exactly as before -- only a real --lane value
     * appends a lane suffix, so two lanes can never collide with each other
     * or with a legacy single-connection futures run.
     */
    public static function lockKeyFor(string $provider, string $marketType, ?string $lane = null): string
    {
        $key = sprintf('autofinx:lock:market-feed:%s:%s', strtolower($provider), strtolower($marketType));

        return $lane !== null ? $key.':'.strtolower($lane) : $key;
    }

    /**
     * Single source of truth for the per-lane heartbeat file path. Spot (and
     * any legacy non-lane futures invocation) keeps the original filename
     * exactly as before.
     */
    public static function heartbeatPathFor(string $marketType, ?string $lane = null): string
    {
        $marketType = strtolower($marketType);

        return $lane !== null
            ? storage_path("framework/autofinx-market-ws-heartbeat-{$marketType}-{$lane}.json")
            : storage_path("framework/autofinx-market-ws-heartbeat-{$marketType}.json");
    }

    /**
     * Merge this lane's just-written heartbeat snapshot into the single
     * combined autofinx-market-ws-heartbeat-futures.json file that predates
     * the two-lane split. Several real (non-diagnostic) consumers still read
     * that exact path directly and were never touched by this change:
     * RobotSessionService::freshLiveFeedHeartbeat(), FutureTradeRuntimeConnectivityService::marketHeartbeat(),
     * FutureTradeLiveFeedManager::loadHeartbeat(), and TradingRuntimeHealthService::heartbeatFeed().
     * None of them distinguish quote vs candle freshness today (that was
     * already true before this change -- a depth-only tick already made the
     * old combined file look "fresh" even while klines were silently stuck),
     * so a "whichever lane ticked most recently, per-symbol data merged from
     * both" aggregate is a faithful, non-regressing compatibility shim, not
     * a behavior change for those consumers.
     *
     * @param  array<string, mixed>  $laneSnapshot
     */
    private function writeFuturesAggregateHeartbeat(string $lane, array $laneSnapshot): void
    {
        $aggregatePath = self::heartbeatPathFor('futures', null);

        try {
            $existingRaw = @file_get_contents($aggregatePath);
            $existing = $existingRaw !== false ? json_decode($existingRaw, true) : null;
            $existing = is_array($existing) ? $existing : [];

            $existingPerSymbol = is_array($existing['per_symbol'] ?? null) ? $existing['per_symbol'] : [];
            $lanePerSymbol = is_array($laneSnapshot['per_symbol'] ?? null) ? $laneSnapshot['per_symbol'] : [];
            // This lane's own entries are the freshest data it has for its
            // own symbols; the other lane's most recently known entries for
            // symbols this lane doesn't cover (or hasn't ticked yet this
            // second) are preserved rather than dropped.
            $mergedPerSymbol = array_merge($existingPerSymbol, $lanePerSymbol);

            $mergedSymbols = array_values(array_unique(array_merge(
                (array) ($existing['symbols'] ?? []),
                (array) ($laneSnapshot['symbols'] ?? [])
            )));
            $mergedStreams = array_values(array_unique(array_merge(
                (array) ($existing['streams'] ?? []),
                (array) ($laneSnapshot['streams'] ?? [])
            )));

            $existingEpoch = (int) ($existing['last_message_epoch'] ?? 0);
            $laneEpoch = (int) ($laneSnapshot['last_message_epoch'] ?? 0);
            $newestIsLane = $laneEpoch >= $existingEpoch;

            $aggregate = $newestIsLane ? $laneSnapshot : $existing;
            $aggregate['symbols'] = $mergedSymbols;
            $aggregate['streams'] = $mergedStreams;
            $aggregate['per_symbol'] = $mergedPerSymbol;
            $aggregate['age_seconds'] = 0;
            $aggregate['lanes'] = array_merge(
                is_array($existing['lanes'] ?? null) ? $existing['lanes'] : [],
                [$lane => $laneSnapshot]
            );
            // The combined file no longer represents one single connection --
            // drop the single-connection-only field so nothing mistakes it
            // for the lane's own connection id.
            unset($aggregate['lane']);

            @file_put_contents($aggregatePath, json_encode($aggregate, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // The lane-specific heartbeat file (already written above) is
            // authoritative; a failed compatibility-merge write must never
            // interrupt the live feed itself.
        }
    }
}
