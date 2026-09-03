<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\CanonicalQuoteService;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\RatioBot\RatioBotAlertService;
use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\RatioBotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled or manually triggered evaluation of one ratio bot config.
 */
class RunRatioBotEvaluationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;
    public int $uniqueFor = 5;

    public function __construct(
        public readonly string $ratioBotConfigId,
        public readonly bool $manualTrigger = false,
    ) {
        $this->onQueue('ratio_bot');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ratio-bot-eval:' . $this->ratioBotConfigId))->expireAfter(10),
        ];
    }

    public function uniqueId(): string
    {
        return 'ratio-bot-eval:' . $this->ratioBotConfigId;
    }

    public function handle(
        RatioBotDecisionService $decisionService,
        RatioBotRealtimeEngine $engine,
        RatioBotStateService $stateService,
        MarketPriceCacheService $priceCache,
        CanonicalQuoteService $quotes,
        MarketRecoveryManager $recovery,
        ExchangeClientFactory $clients,
        OperationalBreakerService $breakers,
        BinanceSpotHealthService $health,
        RatioBotAlertService $alerts,
        RatioBotRuntimeQueryService $wallet,
    ): void {
        $config = RatioBotConfig::with('state')->find($this->ratioBotConfigId);

        if (! $config) {
            Log::warning('[RatioBotJob] Config not found', ['id' => $this->ratioBotConfigId]);
            return;
        }

        if (! $config->enabled && ! $this->manualTrigger) {
            Log::debug('[RatioBotJob] Skipping disabled bot', ['id' => $config->id]);
            return;
        }

        $lockKey = 'ratio-bot-eval-bot:' . $config->id;
        $lock = Cache::lock($lockKey, 10);
        if (! $lock->get()) {
            Log::debug('[RatioBotJob] Duplicate evaluation blocked by per-bot lock', [
                'config_id' => $config->id,
                'symbol' => $config->normalizedSymbol(),
                'lock_key' => $lockKey,
            ]);
            return;
        }

        try {
            $stateService->exitCooldownIfExpired($config);
            $config = $config->fresh('state');

            $pricePayload = $this->fetchPricePayload($config, $priceCache, $quotes, $recovery, $clients, $stateService, $health);
            if ($pricePayload === null) {
                Log::warning('[RatioBotJob] Could not fetch usable price', ['config_id' => $config->id, 'symbol' => $config->symbol]);
                return;
            }

            Log::info('[RatioBotJob] Running evaluation', [
                'config_id' => $config->id,
                'symbol' => $config->symbol,
                'current_price' => $pricePayload['price'],
                'price_source' => $pricePayload['source'],
                'manual' => $this->manualTrigger,
            ]);

            $stateService->clearTransientFeedBlock($config, [
                'price_source' => $pricePayload['source'],
                'price_age_seconds' => $pricePayload['price_age_seconds'] ?? null,
            ]);

            $healthBlock = $this->healthBlockReason($config, $breakers, $health, $pricePayload);
            if ($healthBlock !== null) {
                $decisionService->block(
                    $config,
                    (float) $pricePayload['price'],
                    $healthBlock['detail'],
                    $healthBlock['metadata'],
                    $pricePayload['source'],
                    $pricePayload['timestamp'],
                );

                if (strtoupper((string) $healthBlock['code']) === 'STALE_FEED') {
                    $stateService->markTransientFeedBlock($config, 'feed_stale', $healthBlock['detail'], $healthBlock['metadata']);
                } elseif ((bool) $healthBlock['pause']) {
                    $stateService->block($config, strtolower($healthBlock['code']), $healthBlock['detail']);
                }

                Log::warning('[RatioBotJob] Trade blocked by exchange/data health guard', [
                    'config_id' => $config->id,
                    'code' => $healthBlock['code'],
                    'detail' => $healthBlock['detail'],
                ]);
                return;
            }

            if ($config->state?->isIdle()) {
                $this->maybeAutoAnchor($config, (float) $pricePayload['price'], $wallet, $stateService);
                $config = $config->fresh('state');
            }

            $result = $engine->evaluate($config, (float) $pricePayload['price'], [
                'price_source' => $pricePayload['source'],
                'price_timestamp' => $pricePayload['timestamp'],
                'price_age_seconds' => $pricePayload['price_age_seconds'] ?? null,
                'latency_ms' => $pricePayload['latency_ms'] ?? null,
            ]);

            $alerts->checkAndNotify($config);
        } finally {
            $lock->release();
        }
    }

    /**
     * A bot that has never been anchored sits idle forever waiting for an
     * operator to call /anchor-buy or /anchor-sell — Ratio Bot is meant to be
     * fully automatic. When idle, look at what the account actually holds and
     * anchor automatically:
     *   - base asset funded, quote asset not  -> anchor as if just bought
     *     (HOLDING, watches for a SELL trigger).
     *   - quote asset funded, base asset not  -> anchor as if just sold
     *     (FLAT, watches for a BUY trigger).
     *   - both funded                         -> initialize from whichever
     *     side is worth more at the current price (converted to the same
     *     quote-currency unit so they're comparable): the dominant side sets
     *     the anchor, exactly as if the smaller side were rounding/dust. This
     *     is a deterministic balance-based rule, not a guess — it never
     *     invents a position, it only picks which of the two REAL balances
     *     the bot starts watching from.
     *   - neither funded                      -> stays idle; nothing to
     *     anchor from yet (WAITING FOR FUNDS). Still requires a manual
     *     /anchor-* call once funded, same as before.
     * Live mode reads the real exchange balance; sandbox mode has no real
     * wallet, so it treats the bot's configured starting capital
     * (base_quote_amount) as the quote-asset side, unless starting_entry is
     * explicitly SELL — that path declares a fictional starting coin
     * position the operator must state themselves via
     * /anchor-starting-inventory, since there is nothing to auto-detect it
     * from.
     */
    private const MIN_AUTO_ANCHOR_NOTIONAL_USDT = 10.0;

    private function maybeAutoAnchor(
        RatioBotConfig $config,
        float $currentPrice,
        RatioBotRuntimeQueryService $wallet,
        RatioBotStateService $stateService,
    ): void {
        if ($currentPrice <= 0) {
            return;
        }

        if ($config->isSandbox()) {
            $this->maybeAutoAnchorSandbox($config, $currentPrice, $stateService);
            return;
        }

        if (! $config->isLiveMode()) {
            return;
        }

        $balances = $wallet->walletBalances($config);
        $baseAvailable = (float) data_get($balances, 'base.available', data_get($balances, 'base.balance', 0));
        $quoteAvailable = (float) data_get($balances, 'quote.available', data_get($balances, 'quote.balance', 0));

        // Multiple live bots on the same symbol share one real exchange
        // balance. Order execution already re-checks the live balance and
        // caps quantity at fill time (capBuyQuoteToLiveBalance/
        // capSellQtyToLiveBalance), so this can never double-spend — but
        // without this, every sibling bot would independently auto-anchor
        // against the FULL shared balance, each behaving as if it alone owns
        // it, which is confusing and generates avoidable
        // resolved_order_quantity_is_zero noise when they all try to act on
        // the same trigger. Give each sibling an equal, non-overlapping
        // share of the balance to anchor from instead.
        $siblingCount = $this->liveSiblingBotCount($config);
        if ($siblingCount > 1) {
            $baseAvailable /= $siblingCount;
            $quoteAvailable /= $siblingCount;
        }

        $baseNotional = $baseAvailable * $currentPrice;
        $hasBase = $baseNotional >= self::MIN_AUTO_ANCHOR_NOTIONAL_USDT;
        $hasQuote = $quoteAvailable >= self::MIN_AUTO_ANCHOR_NOTIONAL_USDT;

        if (! $hasBase && ! $hasQuote) {
            return;
        }

        // Both funded: the larger side (compared in the same quote-currency
        // unit) wins. A tie is resolved toward the quote side (BUY WATCH),
        // the lower-immediate-risk starting posture since it doesn't commit
        // an existing coin balance to a directional bet.
        if ($hasBase && (! $hasQuote || $baseNotional > $quoteAvailable)) {
            $stateService->anchorManualBuy(config: $config, buyPrice: $currentPrice, quantity: $baseAvailable, orderId: 'auto-anchor');
            Log::info('[RatioBotJob] Auto-anchored from live base-asset balance (now watching for SELL)', [
                'config_id' => $config->id,
                'base_available' => $baseAvailable,
                'base_notional' => $baseNotional,
                'quote_available' => $quoteAvailable,
                'price' => $currentPrice,
            ]);
            return;
        }

        $impliedQty = max(round($quoteAvailable / $currentPrice, 8), 0.00000001);
        $stateService->anchorManualSell(config: $config, sellPrice: $currentPrice, quantity: $impliedQty, orderId: 'auto-anchor');
        Log::info('[RatioBotJob] Auto-anchored from live quote-asset balance (now watching for BUY)', [
            'config_id' => $config->id,
            'quote_available' => $quoteAvailable,
            'base_available' => $baseAvailable,
            'base_notional' => $baseNotional,
            'price' => $currentPrice,
        ]);
    }

    /**
     * How many enabled LIVE bots (this one included) target the same
     * workspace + symbol, and therefore share the same real exchange
     * balance. Sandbox bots never call this — each has its own independent
     * configured base_quote_amount, no shared wallet, no conflict.
     */
    private function liveSiblingBotCount(RatioBotConfig $config): int
    {
        $symbol = $config->normalizedSymbol();

        return max(1, RatioBotConfig::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('execution_mode', 'live')
            ->where('enabled', true)
            ->get(['id', 'symbol'])
            ->filter(fn (RatioBotConfig $sibling) => $sibling->normalizedSymbol() === $symbol)
            ->count());
    }

    private function maybeAutoAnchorSandbox(
        RatioBotConfig $config,
        float $currentPrice,
        RatioBotStateService $stateService,
    ): void {
        if ($config->startingEntry() === 'SELL') {
            return;
        }

        $quoteAmount = (float) ($config->base_quote_amount ?? 0);
        if ($quoteAmount < self::MIN_AUTO_ANCHOR_NOTIONAL_USDT) {
            return;
        }

        $impliedQty = max(round($quoteAmount / $currentPrice, 8), 0.00000001);
        $stateService->anchorManualSell(config: $config, sellPrice: $currentPrice, quantity: $impliedQty, orderId: 'auto-anchor');
        Log::info('[RatioBotJob] Auto-anchored sandbox bot from configured starting capital (now watching for BUY)', [
            'config_id' => $config->id,
            'base_quote_amount' => $quoteAmount,
            'price' => $currentPrice,
        ]);
    }

    private function fetchPricePayload(
        RatioBotConfig $config,
        MarketPriceCacheService $priceCache,
        CanonicalQuoteService $quotes,
        MarketRecoveryManager $recovery,
        ExchangeClientFactory $clients,
        RatioBotStateService $stateService,
        BinanceSpotHealthService $health,
    ): ?array {
        $exchange = $config->exchangeKey();
        $symbol = $config->normalizedSymbol();
        $maxAgeSeconds = $config->staleFeedWindowSeconds();

        $canonical = $quotes->forRatioBot($config);
        if ($canonical !== null) {
            return $canonical;
        }

        $cached = $priceCache->getLatestPrice($exchange, $symbol);
        if ($cached && (float) $cached['price'] > 0) {
            $timestamp = $this->timestampFromMs($cached['timestamp_ms'] ?? null);
            $ageSeconds = $timestamp ? $timestamp->diffInSeconds(now()) : PHP_INT_MAX;

            if ($ageSeconds <= $maxAgeSeconds) {
                return [
                    'price' => (float) $cached['price'],
                    'source' => (string) ($cached['source'] ?? 'cache'),
                    'timestamp' => $timestamp ?? now(),
                    'price_age_seconds' => $ageSeconds,
                    'latency_ms' => $timestamp ? abs($timestamp->diffInMilliseconds(now())) : null,
                ];
            }

            if (in_array($config->evaluationModeKey(), ['websocket', 'tick', 'candle_close'], true)) {
                $this->handleStaleFeed($config, $stateService, $recovery, $ageSeconds, $maxAgeSeconds);
                return null;
            }
        } elseif (in_array($config->evaluationModeKey(), ['websocket', 'tick', 'candle_close'], true)) {
            $this->handleStaleFeed($config, $stateService, $recovery, null, $maxAgeSeconds);
            return null;
        }

        try {
            $startedAt = microtime(true);
            $price = $this->fetchRemotePrice($config, $clients);
            if ($price <= 0) {
                throw new \RuntimeException('remote price is zero');
            }
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $health->recordSuccess((string) $config->workspace_id, [
                'source' => 'ratio_bot_price_fetch',
                'symbol' => $symbol,
                'latency_ms' => $latencyMs,
            ]);

            $tsMs = (int) floor(microtime(true) * 1000);
            $priceCache->setLatestPrice($exchange, $symbol, $price, $tsMs);

            return [
                'price' => $price,
                'source' => $cached ? 'polling-fallback' : 'polling',
                'timestamp' => Carbon::createFromTimestampMs($tsMs),
                'price_age_seconds' => 0,
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable $e) {
            if ((bool) $config->pause_on_exchange_unhealthy) {
                $stateService->block($config, 'exchange_unhealthy', 'Price fetch failed: ' . $e->getMessage());
            }
            $health->recordFailure((string) $config->workspace_id, $e->getMessage(), [
                'source' => 'ratio_bot_price_fetch',
                'symbol' => $symbol,
            ]);
            Log::warning('[RatioBotJob] Remote price fetch failed', [
                'config_id' => $config->id,
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchRemotePrice(RatioBotConfig $config, ExchangeClientFactory $clients): float
    {
        $symbol = $config->normalizedSymbol();
        if ($config->marketType() === 'futures') {
            $response = Http::timeout(5)->get(
                rtrim((string) config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com'), '/') . '/fapi/v1/premiumIndex',
                ['symbol' => $symbol]
            );
            if (! $response->successful()) {
                throw new \RuntimeException('public Binance futures price endpoint returned HTTP ' . $response->status());
            }

            return (float) ($response->json('markPrice') ?? $response->json('price') ?? 0);
        }

        if ($config->isSandbox()) {
            $response = Http::timeout(5)->get(config('services.binance.api_url', 'https://api.binance.com') . '/api/v3/ticker/price', [
                'symbol' => $symbol,
            ]);
            if (! $response->successful()) {
                throw new \RuntimeException('public Binance price endpoint returned HTTP ' . $response->status());
            }

            return (float) $response->json('price');
        }

        if (! $config->connection_id) {
            throw new \RuntimeException('live price fetch requires connection_id');
        }

        $connection = ExchangeConnection::query()
            ->where('workspace_id', $config->workspace_id)
            ->whereKey($config->connection_id)
            ->first();

        if (! $connection || ! $connection->is_active) {
            throw new \RuntimeException('exchange connection is missing or inactive');
        }

        return $clients->makeFromConnection($connection)->getLatestPrice($symbol);
    }

    private function healthBlockReason(
        RatioBotConfig $config,
        OperationalBreakerService $breakers,
        BinanceSpotHealthService $health,
        array $pricePayload,
    ): ?array {
        $workspaceId = (string) $config->workspace_id;
        $symbol = $config->normalizedSymbol();
        $exchange = strtolower((string) ($config->exchange ?: 'binance'));
        $state = $config->state;

        $failureThreshold = max(1, (int) ($config->pause_on_order_failure_streak ?? 3));
        if ($state && (int) ($state->consecutive_order_failures ?? 0) >= $failureThreshold) {
            return $this->healthBlock(
                'ORDER_FAILURE_STREAK',
                "Order failure streak reached {$state->consecutive_order_failures}/{$failureThreshold}; manual review required before trading.",
                ['failure_count' => (int) $state->consecutive_order_failures, 'limit' => $failureThreshold],
                true,
            );
        }

        // Symbol-scoped, not workspace-scoped: a stale SOLUSDT bot in this
        // workspace must never gate a healthy BTCUSDT bot (or a sibling
        // BTCUSDT bot) in the same workspace. Every bot on the same symbol
        // correctly shares this same breaker, since they all consume the
        // same underlying price feed for that symbol.
        $staleFeedState = $breakers->staleFeedState($workspaceId, $symbol);
        if ((bool) ($staleFeedState['active'] ?? false)) {
            $snapshot = (array) data_get($pricePayload, 'quality.snapshot', []);
            if ((bool) ($snapshot['is_fresh'] ?? false)) {
                $breakers->clearStaleFeedBreaker($workspaceId, [
                    'symbol' => $symbol,
                    'received_at' => $snapshot['received_at'] ?? null,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'reason' => 'selected_symbol_snapshot_fresh_before_evaluation',
                ], 'ratio_bot_evaluation', $symbol);
            } else {
                return $this->healthBlock(
                    'STALE_FEED',
                    'Market-data stale-feed breaker is active.',
                    ['breaker' => $staleFeedState, 'snapshot' => $snapshot],
                    (bool) $config->pause_on_stale_feed,
                );
            }
        }

        $snapshot = (array) data_get($pricePayload, 'quality.snapshot', []);
        if ($snapshot !== [] && ! (bool) ($snapshot['is_fresh'] ?? false)) {
            return $this->healthBlock(
                'STALE_FEED',
                'Selected-symbol market snapshot is stale.',
                ['snapshot' => $snapshot],
                (bool) $config->pause_on_stale_feed,
            );
        }

        if ($exchange === 'binance') {
            $providerState = $breakers->providerState($workspaceId, 'binance');
            if ((bool) ($providerState['active'] ?? false)) {
                return $this->healthBlock(
                    'EXCHANGE_UNHEALTHY',
                    'Binance provider breaker is active: ' . (string) ($providerState['reason'] ?? 'provider_outage'),
                    ['provider_breaker' => $providerState],
                    (bool) $config->pause_on_exchange_unhealthy,
                );
            }

            $restSnapshot = $health->snapshot($workspaceId);
            if ((int) ($restSnapshot['consecutive_rest_failures'] ?? 0) >= 3) {
                return $this->healthBlock(
                    'EXCHANGE_UNHEALTHY',
                    'Binance REST health is unhealthy after repeated failures.',
                    ['rest_health' => $restSnapshot],
                    (bool) $config->pause_on_exchange_unhealthy,
                );
            }
        }

        if ($config->isLiveMode() && $config->connection_id) {
            $connection = ExchangeConnection::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($config->connection_id)
                ->first();

            if (! $connection || ! $connection->is_active) {
                return $this->healthBlock(
                    'EXCHANGE_UNHEALTHY',
                    'Live exchange connection is missing or inactive.',
                    ['connection_id' => $config->connection_id],
                    (bool) $config->pause_on_exchange_unhealthy,
                );
            }

            if (in_array($config->evaluationModeKey(), ['websocket', 'hybrid', 'tick', 'candle_close'], true)) {
                $streamState = $breakers->streamState($connection);
                $streamStatus = (string) ($streamState['status'] ?? 'idle');
                $priceSource = (string) ($pricePayload['source'] ?? '');

                if (in_array($config->evaluationModeKey(), ['websocket', 'tick', 'candle_close'], true) && in_array($streamStatus, ['error', 'stopped'], true)) {
                    return $this->healthBlock(
                        'WEBSOCKET_UNHEALTHY',
                        "Websocket stream is {$streamStatus}; live websocket-only trading is blocked.",
                        ['stream_state' => $streamState],
                        (bool) $config->pause_on_exchange_unhealthy,
                    );
                }

                $lastEventAt = $streamState['last_event_at'] ?? $streamState['last_keepalive_at'] ?? null;
                $maxAge = $config->staleFeedWindowSeconds();
                $eventAge = $lastEventAt ? Carbon::parse($lastEventAt)->diffInSeconds(now()) : PHP_INT_MAX;
                if (in_array($config->evaluationModeKey(), ['websocket', 'tick', 'candle_close'], true) && $eventAge > $maxAge) {
                    return $this->healthBlock(
                        'STALE_FEED',
                        "Websocket feed is stale for {$eventAge}s; max allowed age is {$maxAge}s.",
                        ['stream_state' => $streamState, 'event_age_seconds' => $eventAge, 'max_age_seconds' => $maxAge],
                        (bool) $config->pause_on_stale_feed,
                    );
                }

                if ($config->evaluation_mode === 'hybrid' && in_array($streamStatus, ['error', 'stopped'], true) && ! in_array($priceSource, ['polling', 'polling-fallback'], true)) {
                    return $this->healthBlock(
                        'WEBSOCKET_UNHEALTHY',
                        "Hybrid mode has unhealthy websocket state {$streamStatus} and no polling fallback price.",
                        ['stream_state' => $streamState, 'price_source' => $priceSource],
                        (bool) $config->pause_on_exchange_unhealthy,
                    );
                }
            }
        }

        $uncertainOrder = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where('asset_code', $symbol)
            ->where(function ($query): void {
                $query->where('status', Order::STATUS_UNCERTAIN)
                    ->orWhereIn('reconciliation_state', [
                        Order::RECONCILIATION_MISMATCH,
                        Order::RECONCILIATION_UNCERTAIN,
                    ]);
            })
            ->where('created_at', '>=', now()->subDay())
            ->first();

        if ($uncertainOrder) {
            return $this->healthBlock(
                'RECONCILIATION_MISMATCH',
                'Open or recent order has uncertain/mismatched reconciliation state; re-submit is blocked.',
                [
                    'order_id' => $uncertainOrder->id,
                    'exchange_order_id' => $uncertainOrder->exchange_order_id,
                    'client_order_id' => $uncertainOrder->client_order_id,
                    'status' => $uncertainOrder->status,
                    'reconciliation_state' => $uncertainOrder->reconciliation_state,
                ],
                true,
            );
        }

        return null;
    }

    private function healthBlock(string $code, string $detail, array $metadata = [], bool $pause = true): array
    {
        return [
            'code' => $code,
            'detail' => $detail,
            'pause' => $pause,
            'metadata' => array_merge([
                'guard' => 'exchange_health',
                'reason_code' => $code,
            ], $metadata),
        ];
    }

    private function handleStaleFeed(
        RatioBotConfig $config,
        RatioBotStateService $stateService,
        MarketRecoveryManager $recovery,
        ?int $ageSeconds,
        int $maxAgeSeconds,
    ): void {
        $detail = $ageSeconds === null
            ? "No cached websocket price is available for {$config->normalizedSymbol()}."
            : "Cached websocket price is {$ageSeconds}s old; max allowed age is {$maxAgeSeconds}s.";

        $stateService->markTransientFeedBlock($config, 'feed_stale', $detail, [
            'age_seconds' => $ageSeconds,
            'max_age_seconds' => $maxAgeSeconds,
        ]);
        $recovery->requestRecovery(
            strtolower((string) ($config->exchange ?: 'binance')),
            $config->normalizedSymbol(),
            StreamType::QUOTE,
            'ratio_bot_stale_feed',
            [
                'config_id' => (string) $config->id,
                'age_seconds' => $ageSeconds,
                'max_age_seconds' => $maxAgeSeconds,
            ],
        );

        Log::warning('[RatioBotJob] Stale feed blocked evaluation', [
            'config_id' => $config->id,
            'detail' => $detail,
        ]);
    }

    private function timestampFromMs(mixed $timestampMs): ?Carbon
    {
        if ($timestampMs === null || $timestampMs === '') {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $timestampMs);
    }
}
