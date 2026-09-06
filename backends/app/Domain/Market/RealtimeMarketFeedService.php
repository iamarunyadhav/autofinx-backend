<?php

namespace App\Domain\Market;

use App\Domain\Ops\OperationalBreakerService;
use App\Domain\MarketData\Data\NormalizedQuote;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\MarketData\MarketStateStore;
use App\Domain\MarketData\Support\InstrumentMapper;
use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\FutureTradeBotConfig;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RobotSession;
use App\Models\Symbol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RealtimeMarketFeedService
{
    /** Throttle DB writes for MarketFeedStatus: workspace:symbol:exchange:type → epoch second of last write */
    private static array $feedStatusWriteThrottle = [];

    /** Throttle state->update() for RatioBotConfig: config_id → epoch second of last write */
    private static array $ratioBotStateWriteThrottle = [];

    /** In-process symbol ID cache to avoid repeated firstOrCreate DB hits */
    private static array $symbolIdCache = [];

    private const FEED_STATUS_WRITE_INTERVAL_S = 12;
    private const RATIO_STATE_WRITE_INTERVAL_S  = 30;

    /** Block reasons a bot (sandbox or live) may recover from on its own once the underlying condition clears. */
    private const AUTO_RECOVERABLE_BLOCK_CODES = ['feed_stale', 'stale_feed', 'exchange_unhealthy', 'websocket_unhealthy'];

    public function __construct(
        private readonly MarketPriceCacheService $prices,
        private ?RatioBotStateService $ratioBotState = null,
        private ?OperationalBreakerService $breakers = null,
        private ?MarketStateStore $marketState = null,
        private ?MarketFreshnessService $freshness = null,
        private ?MarketRecoveryManager $recovery = null,
        private ?InstrumentMapper $instruments = null,
        private ?MarketSnapshotService $snapshots = null,
    ) {
        $this->ratioBotState ??= app(RatioBotStateService::class);
        $this->breakers ??= app(OperationalBreakerService::class);
        $this->marketState ??= app(MarketStateStore::class);
        $this->freshness ??= app(MarketFreshnessService::class);
        $this->recovery ??= app(MarketRecoveryManager::class);
        $this->instruments ??= app(InstrumentMapper::class);
        $this->snapshots ??= app(MarketSnapshotService::class);
    }

    /**
     * @return string[]
     */
    public function selectedSymbols(string $marketType = 'spot', ?string $workspaceId = null, ?string $botId = null): array
    {
        $ratioSymbols = RatioBotConfig::query()
            ->when($botId, fn ($query) => $query->whereKey($botId))
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('market_type', strtolower($marketType))
            ->where('enabled', true)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn (string $symbol) => strtoupper(trim($symbol)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $futureTradeSymbols = [];
        if ($botId === null || $botId === '') {
            $futureTradeSymbols = FutureTradeBotConfig::query()
                ->with('state')
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('enabled', true)
                ->get()
                ->flatMap(function (FutureTradeBotConfig $config): array {
                    $settings = $config->mergedSettings();
                    $symbols = array_map(
                        static fn ($symbol) => strtoupper(trim((string) $symbol)),
                        (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                    );

                    $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                    if ($currentSymbol !== '') {
                        $symbols[] = $currentSymbol;
                    }

                    return array_values(array_filter($symbols));
                })
                ->unique()
                ->values()
                ->all();
        }

        $robotSessionSymbols = [];
        if ($botId === null || $botId === '') {
            $robotSessionSymbols = RobotSession::query()
                ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
                ->whereIn('status', ['RUNNING', 'OPEN'])
                ->where('provider_key', 'binance')
                ->pluck('symbol')
                ->map(fn (string $symbol) => strtoupper(trim($symbol)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return collect([...$ratioSymbols, ...$futureTradeSymbols, ...$robotSessionSymbols])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $symbols
     * @param  string[]  $streams
     */
    public function buildStreamUrl(array $symbols, string $marketType = 'spot', array $streams = ['trade', 'kline_1m', 'miniTicker'], bool $testnet = false): string
    {
        $symbols = array_values(array_filter(array_map(
            static fn (string $symbol) => strtolower(trim($symbol)),
            $symbols
        )));

        if ($symbols === []) {
            throw new \RuntimeException('At least one symbol is required to build a market stream URL.');
        }

        $normalizedStreams = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            $streams
        )));

        $streamParts = [];
        foreach ($normalizedStreams as $stream) {
            if (str_starts_with($stream, '!')) {
                $streamParts[] = $stream;
                continue;
            }

            foreach ($symbols as $symbol) {
                $streamParts[] = "{$symbol}@{$stream}";
            }
        }

        $baseUrl = $this->marketTypeBaseUrl($marketType, $testnet);

        return rtrim($baseUrl, '/') . '/stream?streams=' . implode('/', $streamParts);
    }

    public function ingest(array $payload, string $marketType = 'spot', bool $dispatchEvaluations = true, array $workerContext = []): void
    {
        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            $allowedSymbols = array_values(array_filter(array_map(
                static fn ($symbol) => strtoupper(trim((string) $symbol)),
                (array) ($workerContext['subscriptions'] ?? [])
            )));
            foreach ($payload['data'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $symbol = strtoupper((string) ($row['s'] ?? ''));
                if ($allowedSymbols !== [] && ! in_array($symbol, $allowedSymbols, true)) {
                    continue;
                }
                $stream = $symbol !== ''
                    ? strtolower($symbol).'@markPrice@1s'
                    : (string) ($payload['stream'] ?? '');
                $this->ingest(['stream' => $stream, 'data' => $row], $marketType, $dispatchEvaluations, $workerContext);
            }

            return;
        }

        $data = is_array($payload['data'] ?? null) ? (array) $payload['data'] : $payload;
        $eventType = strtolower((string) ($data['e'] ?? ''));
        $stream = strtolower((string) ($payload['stream'] ?? ''));

        $symbol = strtoupper((string) ($data['s'] ?? $data['symbol'] ?? ''));
        if ($symbol === '') {
            return;
        }

        $provider = $this->exchangeKey($marketType);
        $instrument = $this->instruments->canonical($provider, $symbol);
        $marketCategory = $this->instruments->marketType($instrument, 'CRYPTO');
        $receivedAtMs = (int) floor(microtime(true) * 1000);
        $exchangeKey = $this->exchangeKey($marketType);
        $eventTimestampMs = (int) ($data['E'] ?? $data['T'] ?? $data['t'] ?? floor(microtime(true) * 1000));
        $eventLagMs = max(0, $receivedAtMs - $eventTimestampMs);
        $price = $this->extractPrice($eventType, $data, $stream);
        $closedKline = false;
        $timeframe = '1m';
        $streamType = $this->streamType($eventType, $stream);

        if ($price !== null && $price > 0) {
            $this->prices->setLatestPrice($exchangeKey, $symbol, $price, $eventTimestampMs, [
                'market_type' => strtolower($marketType),
                'stream' => $stream,
                'event_type' => $eventType,
                'event_lag_ms' => $eventLagMs,
                'worker_id' => $workerContext['worker_id'] ?? null,
                'connection_id' => $workerContext['connection_id'] ?? null,
            ]);
            $quote = NormalizedQuote::fromLastPrice(
                $provider,
                $instrument,
                $symbol,
                $marketCategory,
                $price,
                $eventTimestampMs,
                $receivedAtMs,
                null,
                'websocket',
                [
                    'legacy_exchange' => $exchangeKey,
                    'legacy_market_type' => strtolower($marketType),
                    'stream' => $stream,
                    'event_type' => $eventType,
                    'worker_id' => $workerContext['worker_id'] ?? null,
                    'connection_id' => $workerContext['connection_id'] ?? null,
                    'pid' => $workerContext['pid'] ?? null,
                    'hostname' => $workerContext['hostname'] ?? null,
                ],
            );
            $this->marketState->putQuote($quote, $streamType);
            if ($streamType !== StreamType::QUOTE) {
                $this->marketState->putQuote($quote, StreamType::QUOTE);
            }
        }

        if ($eventType === 'kline' && is_array($data['k'] ?? null)) {
            $kline = (array) $data['k'];
            $closedKline = (bool) ($kline['x'] ?? false);
            $timeframe = strtolower((string) ($kline['i'] ?? '1m'));
            $openTs = Carbon::createFromTimestampMs((int) ($kline['t'] ?? $eventTimestampMs));

            // Only persist to DB when the candle is closed — open-candle updates are already
            // cached in Redis via setLatestPrice(). Writing on every tick caused 30-50+ DB
            // writes/second on a 4-symbol × 6-stream setup and saturated MariaDB.
            if ($closedKline && $this->isPlausibleKline($kline, $timeframe)) {
                MarketOhlc::updateOrCreate(
                    [
                        'exchange' => $exchangeKey,
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'ts' => $openTs,
                    ],
                    [
                        'id' => MarketOhlc::query()
                            ->where('exchange', $exchangeKey)
                            ->where('symbol', $symbol)
                            ->where('timeframe', $timeframe)
                            ->where('ts', $openTs)
                            ->value('id') ?: (string) Str::uuid(),
                        'open' => (float) ($kline['o'] ?? 0),
                        'high' => (float) ($kline['h'] ?? 0),
                        'low' => (float) ($kline['l'] ?? 0),
                        'close' => (float) ($kline['c'] ?? 0),
                        'volume' => (float) ($kline['v'] ?? 0),
                    ]
                );
            }

            if ((float) ($kline['c'] ?? 0) > 0) {
                $price = (float) $kline['c'];
                $this->prices->setLatestPrice($exchangeKey, $symbol, $price, (int) ($kline['T'] ?? $eventTimestampMs), [
                    'market_type' => strtolower($marketType),
                    'stream' => $stream,
                    'event_type' => $eventType,
                    'event_lag_ms' => $eventLagMs,
                    'worker_id' => $workerContext['worker_id'] ?? null,
                    'connection_id' => $workerContext['connection_id'] ?? null,
                ]);
                $quote = NormalizedQuote::fromLastPrice(
                    $provider,
                    $instrument,
                    $symbol,
                    $marketCategory,
                    $price,
                    (int) ($kline['T'] ?? $eventTimestampMs),
                    $receivedAtMs,
                    null,
                    'websocket',
                    [
                        'legacy_exchange' => $exchangeKey,
                        'legacy_market_type' => strtolower($marketType),
                        'stream' => $stream,
                        'event_type' => $eventType,
                        'timeframe' => $timeframe,
                        'closed_kline' => $closedKline,
                        'worker_id' => $workerContext['worker_id'] ?? null,
                        'connection_id' => $workerContext['connection_id'] ?? null,
                        'pid' => $workerContext['pid'] ?? null,
                        'hostname' => $workerContext['hostname'] ?? null,
                    ],
                );
                $this->marketState->putQuote($quote, StreamType::CANDLE);
                $this->marketState->putQuote($quote, StreamType::QUOTE);
            }
        }

        $this->touchWorkspaceFeedStatuses($symbol, $marketType, $price, $eventTimestampMs, $stream, $eventType, $closedKline, $eventLagMs);
        $this->clearRecoveredWorkspaceBreakers($symbol, $marketType);

        $botConfigs = RatioBotConfig::query()
            ->with('state')
            ->where('symbol', $symbol)
            ->where('market_type', strtolower($marketType))
            ->get();

        foreach ($botConfigs as $config) {
            $this->touchFeedStatus(
                $config,
                $price,
                $eventTimestampMs,
                $stream !== '' ? $stream : ($eventType !== '' ? $eventType : 'websocket'),
                'healthy',
                ['event_type' => $eventType, 'stream' => $stream, 'closed_kline' => $closedKline, 'event_lag_ms' => $eventLagMs]
            );

            if ($closedKline) {
                $config->state?->update(['last_candle_close_at' => Carbon::createFromTimestampMs($eventTimestampMs)]);
            }

            if (! $config->enabled
                && in_array(strtolower((string) $config->state?->block_reason_code), self::AUTO_RECOVERABLE_BLOCK_CODES, true)) {
                $this->maybeAutoRecoverBlockedBot($config, $exchangeKey, $symbol);
            }

            if (! $dispatchEvaluations || ! $config->enabled) {
                continue;
            }

            if ($closedKline && ! $config->evaluatesOnCandleClose()) {
                continue;
            }

            if (! $closedKline && ! $config->evaluatesOnTick()) {
                continue;
            }

            $dispatchKey = $this->dispatchDeduplicationKey($config, $closedKline, $eventType, $eventTimestampMs, $timeframe);
            $dispatchTtlSeconds = $this->dispatchDeduplicationTtlSeconds($config, $closedKline, $timeframe);

            if (! Cache::add($dispatchKey, 1, now()->addSeconds($dispatchTtlSeconds))) {
                continue;
            }

            RunRatioBotEvaluationJob::dispatch((string) $config->id, false);
        }
    }

    /**
     * Bots (sandbox or live) that got auto-disabled by
     * RunRatioBotEvaluationJob's health guards (stale feed / exchange or
     * websocket breaker) never come back on their own — the operator has to
     * notice and click Start again. Self-heal them the moment the underlying
     * condition actually clears (breaker no longer active and a fresh price
     * is available) rather than on a blind timer: if the condition hasn't
     * really cleared, the very next evaluation just re-blocks the bot, so
     * this is bounded, not a way to bypass a still-broken connection.
     * Deliberately NOT covered here: ORDER_FAILURE_STREAK — that means real
     * order submissions kept failing, which points at a config/API bug
     * rather than a transient blip, so it always needs a human to look
     * before resuming.
     */
    private function maybeAutoRecoverBlockedBot(RatioBotConfig $config, string $exchangeKey, string $symbol): void
    {
        $code = strtolower((string) $config->state?->block_reason_code);

        // Part A.3 (multi-asset): was hardcoded to 'binance' -- read the
        // bot's own configured provider so an OANDA feed hiccup only ever
        // affects OANDA bots' auto-recovery, never Binance's and vice versa.
        // isProviderOutageActive() already took a $provider param; this was
        // a call-site fix, not an interface change.
        if (in_array($code, ['exchange_unhealthy', 'websocket_unhealthy'], true)
            && $this->breakers->isProviderOutageActive($config->workspace_id, strtolower(trim((string) ($config->exchange ?: 'binance'))))) {
            return;
        }

        if ($code === 'stale_feed' && $this->breakers->isStaleFeedBreakerActive($config->workspace_id)) {
            return;
        }

        $cached = $this->prices->getLatestPrice($exchangeKey, $symbol);
        if (! $cached || empty($cached['timestamp_ms'])) {
            return;
        }

        $ageSeconds = Carbon::createFromTimestampMs((int) $cached['timestamp_ms'])->diffInSeconds(now());
        if ($ageSeconds > $config->staleFeedWindowSeconds()) {
            return;
        }

        $this->ratioBotState->start($config);
        Log::info('[RatioBot] Auto-recovered blocked bot after health guard cleared', [
            'config_id' => $config->id,
            'symbol' => $symbol,
            'execution_mode' => $config->execution_mode,
            'code' => $code,
            'age_seconds' => $ageSeconds,
        ]);
    }

    private function touchWorkspaceFeedStatuses(
        string $symbol,
        string $marketType,
        ?float $price,
        int $eventTimestampMs,
        string $stream,
        string $eventType,
        bool $closedKline,
        int $eventLagMs,
    ): void {
        $workspaceIds = RatioBotConfig::query()
            ->where('symbol', $symbol)
            ->where('market_type', strtolower($marketType))
            ->pluck('workspace_id')
            ->merge(
                FutureTradeBotConfig::query()
                    ->with('state')
                    ->where('enabled', true)
                    ->get()
                    ->filter(function (FutureTradeBotConfig $config) use ($symbol): bool {
                        $settingsSymbols = array_map(
                            static fn ($value) => strtoupper(trim((string) $value)),
                            (array) ($config->mergedSettings()['symbols'] ?? config('future_trade.defaults.symbols', []))
                        );
                        $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));

                        return in_array($symbol, $settingsSymbols, true) || $currentSymbol === $symbol;
                    })
                    ->pluck('workspace_id')
            )
            ->merge(
                RobotSession::query()
                    ->where('symbol', $symbol)
                    ->whereIn('status', ['RUNNING', 'OPEN'])
                    ->where('provider_key', 'binance')
                    ->pluck('workspace_id')
            )
            ->filter()
            ->unique()
            ->values();

        foreach ($workspaceIds as $workspaceId) {
            $this->upsertFeedStatus(
                (string) $workspaceId,
                $symbol,
                $marketType,
                $price,
                $eventTimestampMs,
                $stream !== '' ? $stream : ($eventType !== '' ? $eventType : 'websocket'),
                'healthy',
                [
                    'event_type' => $eventType,
                    'stream' => $stream,
                    'closed_kline' => $closedKline,
                    'event_lag_ms' => $eventLagMs,
                ]
            );
        }
    }

    private function clearRecoveredWorkspaceBreakers(string $symbol, string $marketType): void
    {
        $workspaceIds = RatioBotConfig::query()
            ->where('symbol', strtoupper($symbol))
            ->where('market_type', strtolower($marketType))
            ->pluck('workspace_id')
            ->merge(
                RobotSession::query()
                    ->where('symbol', strtoupper($symbol))
                    ->whereIn('status', ['RUNNING', 'OPEN'])
                    ->where('provider_key', 'binance')
                    ->pluck('workspace_id')
            )
            ->filter()
            ->unique()
            ->values();

        foreach ($workspaceIds as $workspaceId) {
            // Cheap pre-check before the selectedSymbols()+snapshot work
            // below (this runs on every matching tick): skip entirely unless
            // either this symbol's own breaker or the workspace-wide one is
            // actually active for this workspace.
            if (! $this->breakers->isStaleFeedBreakerActive((string) $workspaceId, $symbol)
                && ! $this->breakers->isStaleFeedBreakerActive((string) $workspaceId)) {
                continue;
            }

            $symbols = $this->selectedSymbols($marketType, (string) $workspaceId);
            $allFresh = $symbols !== [];
            $snapshots = [];
            foreach ($symbols as $selectedSymbol) {
                $snapshot = $this->snapshots->getLiveSnapshot('binance', $selectedSymbol, $this->exchangeKey($marketType), 15);
                $isFresh = (bool) ($snapshot['is_fresh'] ?? false);
                $snapshots[$selectedSymbol] = [
                    'is_fresh' => $isFresh,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'reason' => $snapshot['stale_reason'] ?? null,
                ];
                if (! $isFresh) {
                    $allFresh = false;
                    continue;
                }

                // Clear this symbol's own breaker independently of its
                // siblings — a still-stale SOLUSDT bot in this workspace must
                // never keep a healthy BTCUSDT bot's breaker stuck active.
                if ($this->breakers->isStaleFeedBreakerActive((string) $workspaceId, $selectedSymbol)) {
                    $this->breakers->clearStaleFeedBreaker((string) $workspaceId, [
                        'symbol' => $selectedSymbol,
                        'snapshot' => $snapshots[$selectedSymbol],
                        'reason' => 'fresh_websocket_event_recovered_this_symbol',
                    ], 'market_feed_ingest', $selectedSymbol);
                }
            }

            // Workspace-wide breaker: kept for the handful of consumers that
            // intentionally still read it as a whole-workspace advisory
            // signal (e.g. FuturesReadinessService's generic readiness
            // display) — only cleared once every selected symbol in the
            // workspace is fresh, same requirement as before.
            if ($allFresh && $this->breakers->isStaleFeedBreakerActive((string) $workspaceId)) {
                $this->breakers->clearStaleFeedBreaker((string) $workspaceId, [
                    'symbol' => strtoupper($symbol),
                    'symbols' => $symbols,
                    'snapshots' => $snapshots,
                    'reason' => 'fresh_websocket_event_recovered_selected_symbols',
                ], 'market_feed_ingest');
            }
        }
    }

    private function dispatchDeduplicationKey(
        RatioBotConfig $config,
        bool $closedKline,
        string $eventType,
        int $eventTimestampMs,
        string $timeframe,
    ): string {
        if (! $closedKline) {
            $debounceSeconds = max(1, (int) ($config->min_seconds_between_evaluations ?? 1));

            return sprintf(
                'ratio-bot:feed-dispatch:%s:%s:%s',
                $config->id,
                $eventType !== '' ? $eventType : 'tick',
                floor($eventTimestampMs / max($debounceSeconds * 1000, 1))
            );
        }

        return sprintf(
            'ratio-bot:feed-dispatch:%s:candle:%s:%s',
            $config->id,
            strtolower($timeframe ?: '1m'),
            $eventTimestampMs
        );
    }

    private function dispatchDeduplicationTtlSeconds(
        RatioBotConfig $config,
        bool $closedKline,
        string $timeframe,
    ): int {
        if (! $closedKline) {
            return max(1, (int) ($config->min_seconds_between_evaluations ?? 1));
        }

        return max(
            $this->timeframeToSeconds($timeframe),
            max(1, (int) ($config->min_seconds_between_evaluations ?? 1))
        );
    }

    private function timeframeToSeconds(string $timeframe): int
    {
        $normalized = strtolower(trim($timeframe));
        if ($normalized === '') {
            return 60;
        }

        if (! preg_match('/^(\d+)([mhdw])$/', $normalized, $matches)) {
            return 60;
        }

        $value = max(1, (int) Arr::get($matches, 1, 1));
        $unit = Arr::get($matches, 2, 'm');

        return match ($unit) {
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => 60,
        };
    }

    /**
     * @param  string[]  $symbols
     */
    public function markDisconnected(array $symbols, string $marketType, string $reason): void
    {
        $symbolList = array_values(array_filter(array_map(
            static fn (string $symbol) => strtoupper(trim($symbol)),
            $symbols
        )));

        if ($symbolList === []) {
            return;
        }

        $configs = RatioBotConfig::query()
            ->with('state')
            ->whereIn('symbol', $symbolList)
            ->where('market_type', strtolower($marketType))
            ->get();

        foreach ($configs as $config) {
            $this->touchFeedStatus($config, null, null, 'websocket', 'degraded', ['reason' => $reason]);
        }

        foreach ($symbolList as $symbol) {
            $provider = 'binance';
            $instrument = $this->instruments->canonical($provider, $symbol);
            $this->marketState->transition($provider, $instrument, StreamType::QUOTE, FeedState::DEGRADED, 'websocket_disconnected', [
                'reason' => $reason,
                'legacy_market_type' => $marketType,
            ]);
        }

        $futureConfigs = FutureTradeBotConfig::query()
            ->with('state')
            ->where('enabled', true)
            ->get();

        foreach ($futureConfigs as $config) {
            $settingsSymbols = array_map(
                static fn ($value) => strtoupper(trim((string) $value)),
                (array) ($config->mergedSettings()['symbols'] ?? config('future_trade.defaults.symbols', []))
            );
            $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
            $ownedSymbols = array_values(array_unique(array_filter([...$settingsSymbols, $currentSymbol])));

            foreach (array_intersect($symbolList, $ownedSymbols) as $symbol) {
                $this->upsertFeedStatus(
                    (string) $config->workspace_id,
                    (string) $symbol,
                    $marketType,
                    null,
                    null,
                    'websocket',
                    'degraded',
                    ['reason' => $reason]
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function touchFeedStatus(
        RatioBotConfig $config,
        ?float $price,
        ?int $timestampMs,
        string $source,
        string $status,
        array $context = [],
    ): void {
        $this->upsertFeedStatus(
            (string) $config->workspace_id,
            $config->normalizedSymbol(),
            $config->marketType(),
            $price,
            $timestampMs,
            $source,
            $status,
            $context
        );

        $tickAt = $timestampMs ? Carbon::createFromTimestampMs($timestampMs) : now();
        $previousStatus = (string) ($config->state?->feed_status ?? '');

        // Throttle state updates — only write to DB once per RATIO_STATE_WRITE_INTERVAL_S,
        // OR immediately on status change (healthy↔degraded). Prevents 1-2 DB writes/second
        // per ratio bot config from the WebSocket ingest loop.
        $stateThrottleKey = (string) $config->id;
        $now = time();
        $lastStateWrite = self::$ratioBotStateWriteThrottle[$stateThrottleKey] ?? 0;
        $statusChanged = $previousStatus !== $status;

        if ($config->state && ($statusChanged || ($now - $lastStateWrite) >= self::RATIO_STATE_WRITE_INTERVAL_S)) {
            self::$ratioBotStateWriteThrottle[$stateThrottleKey] = $now;

            $config->state->update([
                'last_seen_price' => $price ?? $config->state->last_seen_price,
                'last_price_source' => $source,
                'last_price_timestamp' => $tickAt,
                'feed_status' => $status,
                'feed_status_updated_at' => now(),
                'runtime_meta' => array_merge((array) ($config->state->runtime_meta ?? []), [
                    'last_feed_event' => [
                        'source' => $source,
                        'status' => $status,
                        'context' => $context,
                    ],
                ]),
            ]);
        }

        if ($statusChanged) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_FEED_HEALTH_CHANGED,
                'context' => [
                    'previous_status' => $previousStatus !== '' ? $previousStatus : null,
                    'status' => $status,
                    'source' => $source,
                    'price' => $price,
                ] + $context,
                'occurred_at' => now(),
            ]);
        }
    }

    private function upsertFeedStatus(
        string $workspaceId,
        string $symbol,
        string $marketType,
        ?float $price,
        ?int $timestampMs,
        string $source,
        string $status,
        array $context = [],
    ): void {
        $exchangeKey = $this->exchangeKey($marketType);
        $symbolUpper = strtoupper(trim($symbol));

        // Throttle DB writes — the price is already in Redis; writing to MarketFeedStatus
        // on every WebSocket frame (trade stream = multiple per second) caused 30-50 DB
        // writes/second. Writes are capped to once per FEED_STATUS_WRITE_INTERVAL_S seconds
        // per workspace/symbol. A status downgrade (healthy→degraded) always writes immediately.
        $throttleKey = "{$workspaceId}:{$symbolUpper}:{$exchangeKey}:" . strtolower($marketType);
        $now = time();
        $lastWrite = self::$feedStatusWriteThrottle[$throttleKey] ?? 0;
        $isDowngrade = $status !== 'healthy' && $lastWrite > 0;

        if (! $isDowngrade && ($now - $lastWrite) < self::FEED_STATUS_WRITE_INTERVAL_S) {
            return;
        }

        self::$feedStatusWriteThrottle[$throttleKey] = $now;

        // Cache symbol IDs in-process to avoid firstOrCreate DB hits on every message.
        $symbolCacheKey = $symbolUpper;
        if (! isset(self::$symbolIdCache[$symbolCacheKey])) {
            $symbolModel = Symbol::firstOrCreate(
                ['symbol' => $symbolUpper],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'crypto',
                    'exchange' => $exchangeKey,
                    'metadata' => ['auto_created' => true],
                ]
            );
            self::$symbolIdCache[$symbolCacheKey] = $symbolModel->id;
        }
        $symbolId = self::$symbolIdCache[$symbolCacheKey];

        $existingFeed = MarketFeedStatus::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol_id', $symbolId)
            ->where('exchange', $exchangeKey)
            ->where('market_type', strtolower($marketType))
            ->first();

        $tickAt = $timestampMs
            ? Carbon::createFromTimestampMs($timestampMs)
            : ($status === 'healthy' ? now() : $existingFeed?->last_tick_at);

        if ($existingFeed?->last_tick_at && $tickAt->lt($existingFeed->last_tick_at->copy()->subSecond())) {
            return;
        }

        if ($tickAt->gt(now()->addSeconds(30))) {
            return;
        }

        MarketFeedStatus::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'symbol_id' => $symbolId,
                'exchange' => $exchangeKey,
                'market_type' => strtolower($marketType),
            ],
            [
                'id' => $existingFeed?->id ?: (string) Str::uuid(),
                'symbol' => $symbolUpper,
                'last_tick_at' => $tickAt,
                'last_price' => $price ?? $existingFeed?->last_price,
                'source' => $source,
                'status' => $status,
                'meta_json' => $context,
            ]
        );
    }

    private function extractPrice(string $eventType, array $data, string $stream = ''): ?float
    {
        if ($eventType === 'bookticker' || str_contains(strtolower($stream), 'bookticker')) {
            $bid = isset($data['b']) ? (float) $data['b'] : 0.0;
            $ask = isset($data['a']) ? (float) $data['a'] : 0.0;
            if ($bid > 0 && $ask > 0) {
                return ($bid + $ask) / 2;
            }
        }

        return match ($eventType) {
            'trade', 'aggtrade' => isset($data['p']) ? (float) $data['p'] : null,
            'markpriceupdate' => isset($data['p']) ? (float) $data['p'] : null,
            '24hrticker' => isset($data['c']) ? (float) $data['c'] : null,
            '24hrminiticker' => isset($data['c']) ? (float) $data['c'] : null,
            'kline' => isset($data['k']['c']) ? (float) $data['k']['c'] : null,
            default => isset($data['price']) ? (float) $data['price'] : null,
        };
    }

    private function streamType(string $eventType, string $stream): StreamType
    {
        $stream = strtolower($stream);

        if ($eventType === 'kline' || str_contains($stream, 'kline')) {
            return StreamType::CANDLE;
        }

        if ($eventType === 'bookticker' || str_contains($stream, 'bookticker') || in_array($eventType, ['24hrminiticker', '24hrticker', 'markpriceupdate'], true) || str_contains($stream, 'miniticker') || str_contains($stream, 'ticker') || str_contains($stream, 'markprice')) {
            return StreamType::QUOTE;
        }

        if (in_array($eventType, ['trade', 'aggtrade'], true) || str_contains($stream, 'trade')) {
            return StreamType::TRADE;
        }

        return StreamType::QUOTE;
    }

    private function isPlausibleKline(array $kline, string $timeframe): bool
    {
        $open = (float) ($kline['o'] ?? 0);
        $high = (float) ($kline['h'] ?? 0);
        $low = (float) ($kline['l'] ?? 0);
        $close = (float) ($kline['c'] ?? 0);

        if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
            return false;
        }

        if ($high < max($open, $close) || $low > min($open, $close) || $high < $low) {
            return false;
        }

        $rangePercent = (($high - $low) / max($close, 0.00000001)) * 100;
        $limit = match (strtolower(trim($timeframe))) {
            '1m' => 3.0,
            '3m', '5m' => 5.0,
            '15m', '30m' => 8.0,
            '1h', '2h' => 15.0,
            default => 25.0,
        };

        return $rangePercent <= $limit;
    }

    private function exchangeKey(string $marketType): string
    {
        return strtolower($marketType) === 'futures' ? 'binance_futures' : 'binance';
    }

    private function marketTypeBaseUrl(string $marketType, bool $testnet): string
    {
        if (strtolower($marketType) === 'futures') {
            return $testnet
                ? (string) config('services.binance.futures_testnet_ws_url', 'wss://stream.binancefuture.com')
                : (string) config('services.binance.futures_ws_url', 'wss://fstream.binance.com');
        }

        return $testnet
            ? (string) config('services.binance.testnet_ws_url', 'wss://testnet.binance.vision')
            : (string) config('services.binance.ws_url', 'wss://stream.binance.com:9443');
    }
}
