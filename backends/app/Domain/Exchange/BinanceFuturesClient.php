<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BinanceFuturesClient
{
    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly string $baseUrl;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $baseUrl,
        private readonly string $throttleScope = 'default',
    ) {
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->baseUrl = rtrim(trim($baseUrl), '/');

        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Binance futures API credentials are required.');
        }
    }

    public function ping(): array
    {
        $response = $this->request('GET', '/fapi/v1/ping');
        $this->assertSuccess($response, 'Failed to ping Binance futures.');

        return (array) $response->json();
    }

    public function fetchExchangeInfo(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->request('GET', '/fapi/v1/exchangeInfo', $params);
        $this->assertSuccess($response, 'Failed to fetch Binance futures exchange info.');

        return (array) $response->json();
    }

    public function fetchMarkPrice(string $symbol): array
    {
        $response = $this->request('GET', '/fapi/v1/premiumIndex', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
        ]);
        $this->assertSuccess($response, 'Failed to fetch Binance futures mark price.');

        return (array) $response->json();
    }

    public function fetchAccountInfo(): array
    {
        $response = $this->signedRequest('GET', '/fapi/v2/account');

        return (array) $response->json();
    }

    public function fetchPositionMode(): array
    {
        $response = $this->signedRequest('GET', '/fapi/v1/positionSide/dual');

        return (array) $response->json();
    }

    public function fetchPositionRisk(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->signedRequest('GET', '/fapi/v2/positionRisk', $params);

        return (array) $response->json();
    }

    public function fetchOpenOrders(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->signedRequest('GET', '/fapi/v1/openOrders', $params);

        return (array) $response->json();
    }

    public function createListenKey(): string
    {
        $response = $this->keyedRequest('POST', '/fapi/v1/listenKey');
        $listenKey = (string) ($response->json('listenKey') ?? '');
        if ($listenKey === '') {
            throw new RuntimeException('Failed to create Binance futures listenKey.');
        }

        return $listenKey;
    }

    public function keepAliveListenKey(string $listenKey): void
    {
        $this->keyedRequest('PUT', '/fapi/v1/listenKey', ['listenKey' => $listenKey]);
    }

    public function closeListenKey(string $listenKey): void
    {
        $this->keyedRequest('DELETE', '/fapi/v1/listenKey', ['listenKey' => $listenKey], false);
    }

    public function setLeverage(string $symbol, int|float $leverage): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/leverage', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'leverage' => (int) round($leverage),
        ]);

        return (array) $response->json();
    }

    public function setMarginType(string $symbol, string $marginMode): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/marginType', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'marginType' => strtoupper($marginMode),
        ], false);

        if ($response->successful()) {
            return (array) $response->json();
        }

        $body = (string) $response->body();
        if (str_contains($body, '-4046') || str_contains(strtolower($body), 'no need to change')) {
            return ['message' => 'margin_type_already_set'];
        }

        $this->assertSuccess($response, 'Failed to set Binance futures margin type.');

        return (array) $response->json();
    }

    /**
     * GET /fapi/v1/leverageBracket — the REAL, account/symbol-specific tiered
     * leverage limits Binance actually enforces (leverage allowed varies by the
     * notional value of the position, not a single flat "max leverage" per
     * symbol). Returns null (never an empty/best-effort array) if the response
     * can't be parsed into the expected `{symbol, brackets: [{bracket,
     * initialLeverage, notionalCap, notionalFloor, ...}, ...]}` shape, or if the
     * request itself fails for any reason — this method has no live-docs
     * access to verify Binance's exact schema this session, so it is built to
     * fail closed: callers (see `LeverageValidationService`) must treat a null
     * return as "cannot verify the real exchange max," never fall back to a
     * locally-configured number as if it were exchange-confirmed.
     *
     * `maintMarginRatio` (Binance's real per-tier maintenance-margin rate,
     * used for `LeverageValidationService::estimateLiquidationDistance()`)
     * is captured additively when present, but — unlike `initialLeverage`/
     * `notionalCap`/`notionalFloor` — it is NOT required for a bracket to be
     * considered valid here, so this does not change the existing leverage
     * check's fail-closed behavior for callers/tests that don't supply it.
     * A bracket missing it simply yields a null `maintMarginRatio`, and it is
     * the liquidation-distance caller's own responsibility to fail closed on
     * that null (see `estimateLiquidationDistance()`'s caller in
     * `BinanceTimedFuturesProvider`), not this method's.
     *
     * @return array<int, array{bracket: int, initialLeverage: int|float, notionalCap: int|float, notionalFloor: int|float, maintMarginRatio: int|float|null}>|null
     */
    public function leverageBrackets(string $symbol): ?array
    {
        try {
            $response = $this->signedRequest('GET', '/fapi/v1/leverageBracket', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            ], false);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        // Binance returns either a single object ({symbol, brackets:[...]})
        // or a list of such objects (one per symbol) depending on whether
        // the `symbol` param was echoed back as a single-item response.
        $row = array_key_exists('brackets', $body)
            ? $body
            : collect($body)->first(
                fn ($candidate) => is_array($candidate)
                    && strtoupper((string) ($candidate['symbol'] ?? '')) === BinanceSymbolNormalizer::normalize($symbol)
            );

        $brackets = is_array($row) ? ($row['brackets'] ?? null) : null;
        if (! is_array($brackets) || $brackets === []) {
            return null;
        }

        foreach ($brackets as $bracket) {
            if (
                ! is_array($bracket)
                || ! array_key_exists('initialLeverage', $bracket)
                || ! array_key_exists('notionalCap', $bracket)
                || ! array_key_exists('notionalFloor', $bracket)
            ) {
                // One malformed tier makes the whole tier table untrustworthy —
                // fail the entire lookup rather than validate against a
                // partially-understood bracket set.
                return null;
            }
        }

        return $brackets;
    }

    public function placeOrder(array $payload): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', $payload);

        return (array) $response->json();
    }

    public function placeOrderViaWebSocket(array $payload): array
    {
        return (new BinanceFuturesWebSocketApiClient(
            apiKey: $this->apiKey,
            apiSecret: $this->apiSecret,
            wsApiUrl: $this->wsApiUrl(),
            timeoutSeconds: (int) config('future_trade.order_router.websocket_timeout_seconds', 8),
        ))->placeOrder($payload);
    }

    /**
     * Place a reduce-only STOP_MARKET or TAKE_PROFIT_MARKET order on Binance Futures.
     * Uses closePosition=true so the full position is closed regardless of quantity.
     * workingType=MARK_PRICE avoids wick-triggered liquidations.
     */
    public function placeReduceOnlyStopOrder(
        string $symbol,
        string $side,
        string $type,
        float $stopPrice,
        string $clientOrderId,
        string $workingType = 'MARK_PRICE',
    ): array {
        $response = $this->signedRequest('POST', '/fapi/v1/order', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'side' => $side,
            'type' => $type,
            'stopPrice' => number_format(round($stopPrice, 8), 8, '.', ''),
            'closePosition' => 'true',
            'workingType' => $workingType,
            'newClientOrderId' => $clientOrderId,
        ]);

        return (array) $response->json();
    }

    public function fetchOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null): array
    {
        $params = ['symbol' => BinanceSymbolNormalizer::normalize($symbol)];
        if ($orderId) {
            $params['orderId'] = $orderId;
        }
        if ($clientOrderId) {
            $params['origClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedRequest('GET', '/fapi/v1/order', $params);

        return (array) $response->json();
    }

    public function cancelOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null): array
    {
        $params = ['symbol' => BinanceSymbolNormalizer::normalize($symbol)];
        if ($orderId) {
            $params['orderId'] = $orderId;
        }
        if ($clientOrderId) {
            $params['origClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedRequest('DELETE', '/fapi/v1/order', $params);

        return (array) $response->json();
    }

    public function cancelOpenOrders(string $symbol): array
    {
        $response = $this->signedRequest('DELETE', '/fapi/v1/allOpenOrders', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
        ]);

        return (array) $response->json();
    }

    /**
     * GET /fapi/v1/userTrades — recent fills for a symbol. Used by reconciliation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchUserTrades(string $symbol, ?int $startTimeMs = null, int $limit = 100): array
    {
        $params = [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'limit' => max(1, min(1000, $limit)),
        ];
        if ($startTimeMs !== null) {
            $params['startTime'] = $startTimeMs;
        }

        $response = $this->signedRequest('GET', '/fapi/v1/userTrades', $params, false);

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * The REAL commission Binance actually charged for an order, summed across
     * any partial fills, as reported by the exchange itself — mirrors
     * `BinanceTimedFuturesProvider::realFeeForOrder()` (same contract, same
     * validation rules) but lives on this client because Future Trade talks
     * to Binance directly through `BinanceFuturesClient` rather than through
     * the Integration-based `LiveTradeProviderInterface` Quick Trade uses.
     * Kept as a separate method (not extracted into a shared trait) because
     * the two callers build this client from different credential sources
     * (raw key/secret here vs. an `Integration` model there) and this avoids
     * introducing a new abstraction for two call sites.
     *
     * NEVER throws: any network/circuit-breaker error, non-2xx response,
     * empty/unexpected payload, or mixed commission asset returns null so a
     * live settlement can always fall back to the existing fee estimate.
     *
     * @return array{commission: float, commission_asset: string, realized_pnl: float, fills: int}|null
     */
    public function realFeeForOrder(string $symbol, string $orderId, string $expectedQuoteAsset = 'USDT'): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || $orderId === '0') {
            return null;
        }

        try {
            $response = $this->signedRequest('GET', '/fapi/v1/userTrades', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'orderId' => $orderId,
            ], false);
            if (! $response->successful()) {
                return null;
            }
            $rows = collect((array) $response->json());
        } catch (\Throwable $e) {
            Log::warning('binance_futures_client_real_fee_lookup_failed', [
                'symbol' => $symbol,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($rows->isEmpty()) {
            return null;
        }

        $expectedQuoteAsset = strtoupper($expectedQuoteAsset);
        $commission = 0.0;
        $realizedPnl = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)
                || ! isset($row['commission'], $row['commissionAsset'])
                || ! is_numeric($row['commission'])
                || ! is_finite((float) $row['commission'])) {
                return null;
            }

            $asset = strtoupper((string) ($row['commissionAsset'] ?? ''));
            if ($asset !== $expectedQuoteAsset) {
                Log::warning('binance_futures_client_real_fee_unexpected_asset', [
                    'symbol' => $symbol,
                    'order_id' => $orderId,
                    'commission_asset' => $asset,
                    'expected' => $expectedQuoteAsset,
                ]);

                return null;
            }

            $commission += (float) ($row['commission'] ?? 0);
            $realizedPnl += (float) ($row['realizedPnl'] ?? 0);
        }

        return [
            'commission' => round($commission, 8),
            'commission_asset' => $expectedQuoteAsset,
            'realized_pnl' => round($realizedPnl, 8),
            'fills' => $rows->count(),
        ];
    }

    /**
     * REAL futures funding fee(s) actually credited/debited for a symbol over
     * a position's open-to-close window — mirrors
     * `BinanceTimedFuturesProvider::realFundingFee()`. See that method's
     * docblock for the sign convention and the `null` (unresolved) vs `0.0`
     * (genuinely no funding fee) distinction. NEVER throws.
     */
    public function realFundingFee(string $symbol, \DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        try {
            $response = $this->signedRequest('GET', '/fapi/v1/income', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'incomeType' => 'FUNDING_FEE',
                'startTime' => (int) floor($from->getTimestamp() * 1000),
                'endTime' => (int) floor($to->getTimestamp() * 1000),
                'limit' => 1000,
            ], false);
            if (! $response->successful()) {
                return null;
            }
            $rows = collect((array) $response->json());
        } catch (\Throwable $e) {
            Log::warning('binance_futures_client_real_funding_fee_lookup_failed', [
                'symbol' => $symbol,
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Unknown/malformed data must never masquerade as verified zero cost.
        // A full page may be truncated; leave the result unresolved in that case.
        if ($rows->count() >= 1000) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['income'], $row['asset'], $row['incomeType'], $row['symbol'])
                || ! is_numeric($row['income']) || ! is_finite((float) $row['income'])
                || strtoupper((string) $row['asset']) !== 'USDT'
                || $row['incomeType'] !== 'FUNDING_FEE'
                || strtoupper((string) $row['symbol']) !== strtoupper(BinanceSymbolNormalizer::normalize($symbol))) {
                return null;
            }
        }

        return round($rows->sum(fn ($row) => (float) $row['income']), 8);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Strict accounting reads: an error or a full, potentially truncated page is never zero cost. */
    public function fetchAccountingTrades(string $symbol, int $start, int $end): array
    {
        $budget = 64;
        return $this->readAccountingTradeWindow($symbol, $start, $end, $budget);
    }

    private function readAccountingTradeWindow(string $symbol, int $start, int $end, int &$budget): array
    {
        if (--$budget < 0) {
            throw new RuntimeException('Accounting trade request budget reached; history incomplete.');
        }
        if ($end < $start || $end - $start > 604800000) {
            throw new RuntimeException('Accounting trade window must be at most seven days.');
        }
        $rows = $this->signedRequest('GET', '/fapi/v1/userTrades', [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'startTime' => $start, 'endTime' => $end, 'limit' => 1000,
        ])->json();
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Invalid accounting trades response.');
        }
        // Bisect saturated time windows instead of mixing fromId with time filters.
        if (count($rows) >= 1000) {
            if ($end <= $start) {
                throw new RuntimeException('Accounting trade page saturated at one millisecond.');
            }
            $mid = intdiv($start + $end, 2);
            return array_merge($this->readAccountingTradeWindow($symbol, $start, $mid, $budget),
                $this->readAccountingTradeWindow($symbol, $mid + 1, $end, $budget));
        }
        return $rows;
    }

    public function fetchAccountingFunding(string $symbol, int $start, int $end): array
    {
        $all = [];
        for ($page = 1; $page <= 100; $page++) {
            $rows = $this->signedRequest('GET', '/fapi/v1/income', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol), 'incomeType' => 'FUNDING_FEE',
                'startTime' => $start, 'endTime' => $end, 'limit' => 1000, 'page' => $page,
            ])->json();
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('Invalid funding history response.');
            }
            $all = array_merge($all, $rows);
            if (count($rows) < 1000) {
                return $all;
            }
        }
        throw new RuntimeException('Funding history pagination limit reached.');
    }

    private function signedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        // Skip HTTP entirely if this API key is already known-dead (-2015 / -2014).
        $this->assertKeyHealthy();

        $signedPayload = $this->sign($params);
        $response = $this->request($method, $path, $signedPayload, true);
        if ($this->isTimestampOutsideRecvWindow($response)) {
            $this->syncServerTimeOffset();
            $signedPayload = $this->sign($params);
            $response = $this->request($method, $path, $signedPayload, true);
        }

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures signed request failed.');
        }

        return $response;
    }

    private function wsApiUrl(): string
    {
        $configured = trim((string) config('services.binance.futures_ws_api_url', ''));
        $configuredTestnet = trim((string) config('services.binance.futures_testnet_ws_api_url', ''));
        if (str_contains($this->baseUrl, 'testnet')) {
            return $configuredTestnet !== '' ? $configuredTestnet : 'wss://testnet.binancefuture.com/ws-fapi/v1';
        }

        return $configured !== '' ? $configured : 'wss://ws-fapi.binance.com/ws-fapi/v1';
    }

    private function keyedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        // Skip HTTP entirely if this API key is already known-dead (-2015 / -2014).
        $this->assertKeyHealthy();

        $response = $this->request($method, $path, $params, true);

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures keyed request failed.');
        }

        return $response;
    }

    // ── Per-API-key health cache ──────────────────────────────────────────────
    // After a -2015 / -2014 response the key is cached as "dead" for 30 min.
    // Every subsequent call throws in <1 ms (Redis lookup) without touching Binance.
    // On a successful signed/keyed response the key is marked "ok" for 10 min.
    // "ok" entries auto-expire; "dead" entries require a manual clear OR wait 30 min.

    private const KEY_HEALTH_OK   = 'ok';
    private const KEY_HEALTH_DEAD = 'dead_auth';
    private const KEY_HEALTH_TTL_OK   = 600;   // 10 min
    private const KEY_HEALTH_TTL_DEAD = 1800;  // 30 min

    private function keyHealthKey(): string
    {
        return 'autofinx:binance:key_health:' . substr(hash('sha256', $this->apiKey), 0, 16);
    }

    private function assertKeyHealthy(): void
    {
        if (Cache::get($this->keyHealthKey()) === self::KEY_HEALTH_DEAD) {
            throw new RuntimeException(
                'binance_key_dead: API key is known-invalid (-2015/-2014). '.
                'Fix the Binance key/IP whitelist then disable+re-enable the bot to clear this block.'
            );
        }
    }

    public function markKeyHealthy(): void
    {
        Cache::put($this->keyHealthKey(), self::KEY_HEALTH_OK, self::KEY_HEALTH_TTL_OK);
    }

    public function markKeyDead(): void
    {
        Cache::put($this->keyHealthKey(), self::KEY_HEALTH_DEAD, self::KEY_HEALTH_TTL_DEAD);
    }

    public function clearKeyHealth(): void
    {
        Cache::forget($this->keyHealthKey());
    }

    private function apiHeaders(): array
    {
        return ['X-MBX-APIKEY' => $this->apiKey];
    }

    private function sign(array $payload): array
    {
        $payload['timestamp'] = (int) floor(microtime(true) * 1000) + $this->serverTimeOffsetMs();
        $payload['recvWindow'] = max(5000, min(60000, (int) config('future_trade.binance_rest.recv_window_ms', 15000)));
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $payload['signature'] = hash_hmac('sha256', $query, $this->apiSecret);

        return $payload;
    }

    private function isTimestampOutsideRecvWindow(Response $response): bool
    {
        return (int) ($response->json('code') ?? 0) === -1021
            || str_contains(strtolower($response->body()), 'outside of the recvwindow');
    }

    private function serverTimeOffsetKey(): string
    {
        return 'autofinx:binance:futures_time_offset:'.md5($this->baseUrl);
    }

    private function serverTimeOffsetMs(): int
    {
        return (int) Cache::get($this->serverTimeOffsetKey(), 0);
    }

    private function syncServerTimeOffset(): void
    {
        try {
            $started = (int) floor(microtime(true) * 1000);
            $response = $this->request('GET', '/fapi/v1/time');
            $finished = (int) floor(microtime(true) * 1000);
            $serverTime = (int) ($response->json('serverTime') ?? 0);
            if ($response->successful() && $serverTime > 0) {
                $localMidpoint = (int) floor(($started + $finished) / 2);
                Cache::put($this->serverTimeOffsetKey(), $serverTime - $localMidpoint, 600);
            }
        } catch (\Throwable) {
            Cache::forget($this->serverTimeOffsetKey());
        }
    }

    private function assertSuccess(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($message.' '.$response->body());
    }

    private function url(string $path): string
    {
        return $this->baseUrl.$path;
    }

    // ── DNS pre-warm ─────────────────────────────────────────────────────────
    // Primes the OS resolver cache once per minute so the first request per
    // deploy/restart does not pay the cold-DNS latency penalty.

    private function prewarmDns(string $host): void
    {
        if ($host === '') {
            return;
        }
        try {
            $cacheKey = 'binance_dns_warm:'.$host;
            if (! Cache::has($cacheKey)) {
                $resolved = @gethostbyname($host);
                if ($resolved && $resolved !== $host) {
                    Cache::put($cacheKey, $resolved, 60);
                }
            }
        } catch (\Throwable) {
            // Best-effort; never block a request
        }
    }

    // ── Core HTTP request with retry + circuit breaker ────────────────────────
    // Retry delays: 0 ms → 400 ms (2 attempts, 6 s timeout each → max ~12.5 s).
    // -1003 / HTTP 418: fast-fail, open global ban circuit immediately.
    // -2015 / -2014: fast-fail, no retry (fatal auth error, not transient).
    // Circuit breaker opens after 5 consecutive failures (exceptions OR 5xx/429).
    // Stays open 45 s, then resets so the next request probes liveness.

    private function request(string $method, string $path, array $params = [], bool $authenticated = false): Response
    {
        $host = (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
        $restGuard = new BinanceRestCircuitBreaker;
        $this->prewarmDns($host);
        $restGuard->assertAvailable();
        $this->assertCircuitClosed($host);

        // Two attempts only: 6s timeout each → worst-case 6s + 0.4s + 6s = ~12.5s.
        // (Was 4 attempts × 12s = up to 25s when IP is soft-blocked.)
        $delaysMs = [0, 400];
        $lastException = null;

        foreach ($delaysMs as $index => $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $restGuard->throttle($this->throttleScope);
                $pending = Http::withHeaders($authenticated ? $this->apiHeaders() : [])
                    ->timeout(6)
                    ->connectTimeout(4);

                $response = match (strtoupper($method)) {
                    'GET' => $pending->get($this->url($path), $params),
                    'PUT' => $pending->asForm()->put($this->url($path), $params),
                    'DELETE' => $pending->delete($this->url($path), $params),
                    default => $pending->asForm()->post($this->url($path), $params),
                };

                // Fast-fail on IP ban (-1003 / HTTP 418): open global circuit immediately, no retry.
                $binanceCode = (int) ($response->json('code') ?? 0);
                if ($binanceCode === -1003 || $response->status() === 418) {
                    $restGuard->recordResponse($response); // writes BAN_UNTIL_KEY from "banned until" ms
                    $this->circuitRecordFailure($host, $response->body());
                    return $response; // assertSuccess() will throw upstream
                }

                // Fast-fail on fatal auth errors: invalid API-key / IP restriction.
                // Mark the key dead in Redis so every subsequent call short-circuits
                // in <1 ms without ever touching Binance again.
                if (in_array($binanceCode, [-2015, -2014], true)) {
                    $this->markKeyDead();
                    $this->circuitRecordFailure($host, $response->body());
                    return $response;
                }

                // Retry on transient HTTP errors (rate-limit, gateway errors, etc.)
                if ($this->isRetryableStatus($response) && $index !== array_key_last($delaysMs)) {
                    $lastException = new RuntimeException(
                        'Binance futures REST returned retryable status '.$response->status()
                    );

                    continue;
                }

                // Success (or non-retryable response) — reset circuit breaker
                if ($this->isRetryableStatus($response)) {
                    $this->circuitRecordFailure($host, 'HTTP '.$response->status().': '.$response->body());
                } else {
                    $this->circuitSuccess($host);
                }
                $restGuard->recordResponse($response);

                return $response;

            } catch (\Throwable $exception) {
                $lastException = $exception;
                $this->circuitRecordFailure($host, $exception->getMessage());

                if (! $this->isRetryableException($exception) || $index === array_key_last($delaysMs)) {
                    throw $exception;
                }
            }
        }

        throw $lastException instanceof \Throwable
            ? $lastException
            : new RuntimeException('Binance futures request failed without a response.');
    }

    // ── Circuit breaker ───────────────────────────────────────────────────────

    private function assertCircuitClosed(string $host): void
    {
        $state = (array) (Cache::get('binance_cb:'.$host, []));
        if (($state['open'] ?? false) && time() < (int) ($state['until'] ?? 0)) {
            $remaining = (int) ($state['until'] ?? 0) - time();
            throw new RuntimeException(
                'binance_rest_circuit_open: Binance API circuit breaker is open ('.
                $remaining.'s remaining). Last error: '.
                (string) ($state['last_error'] ?? 'unknown')
            );
        }
    }

    private function circuitRecordFailure(string $host, ?string $error = null): void
    {
        try {
            $key = 'binance_cb:'.$host;
            $state = (array) (Cache::get($key, []));
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $lastError = $error !== null && $error !== ''
                ? mb_substr($error, 0, 500)
                : (string) ($state['last_error'] ?? 'repeated_failures');

            if ($failures >= 5) {
                Cache::put($key, [
                    'open' => true,
                    'until' => time() + 45,
                    'failures' => $failures,
                    'last_error' => $lastError,
                ], 120);
            } else {
                Cache::put($key, array_merge($state, [
                    'failures' => $failures,
                    'last_error' => $lastError,
                ]), 120);
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    private function circuitSuccess(string $host): void
    {
        try {
            Cache::forget('binance_cb:'.$host);
            $this->markKeyHealthy();
        } catch (\Throwable) {
        }
    }

    // ── Retry helpers ─────────────────────────────────────────────────────────

    private function isRetryableStatus(Response $response): bool
    {
        return in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function isRetryableException(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
            'curl error 6',
            'curl error 28',
            'curl error 35',
            'getaddrinfo',
            'could not resolve host',
            'timed out',
            'operation timed out',
            'connection refused',
            'connection reset',
            'failed to connect',
            'temporary failure',
            'name or service not known',
            'network is unreachable',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
