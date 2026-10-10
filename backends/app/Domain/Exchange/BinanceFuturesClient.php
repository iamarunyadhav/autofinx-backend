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
     *
     * Conditional types moved to Binance's Algo Order service (POST /fapi/v1/algoOrder); the
     * endpoint actually used is chosen by config('binance.conditional_order_mode') (see
     * placeConditionalOrder()). Return shape: the exchange answer plus `endpoint` ('legacy'|'algo')
     * and `algo` (bool). For an algo order `orderId` carries the algoId and `clientOrderId` the
     * clientAlgoId, so existing callers keep reading the same keys.
     */
    public function placeReduceOnlyStopOrder(
        string $symbol,
        string $side,
        string $type,
        float $stopPrice,
        string $clientOrderId,
        string $workingType = 'MARK_PRICE',
        ?string $positionSide = null,
    ): array {
        $payload = [
            'symbol' => BinanceSymbolNormalizer::normalize($symbol),
            'side' => $side,
            'type' => $type,
            'stopPrice' => number_format(round($stopPrice, 8), 8, '.', ''),
            'closePosition' => 'true',
            'workingType' => $workingType,
            'newClientOrderId' => $clientOrderId,
        ];
        if ($positionSide !== null && $positionSide !== '') {
            $payload['positionSide'] = $positionSide;
        }

        return $this->placeConditionalOrder($payload);
    }

    // ── Conditional (algo) orders ─────────────────────────────────────────────

    /** auto | algo | legacy (config binance.conditional_order_mode; anything else = auto). */
    public function conditionalOrderMode(): string
    {
        $mode = strtolower(trim((string) config('binance.conditional_order_mode', 'auto')));

        return in_array($mode, ['auto', 'algo', 'legacy'], true) ? $mode : 'auto';
    }

    private function conditionalModeKey(): string
    {
        return 'autofinx:binance:conditional_mode:'.md5($this->baseUrl);
    }

    /** 'algo' | 'legacy' | null (nothing learned yet). Only meaningful in auto mode. */
    private function learnedConditionalMode(): ?string
    {
        try {
            $value = Cache::get($this->conditionalModeKey());
        } catch (\Throwable) {
            return null;
        }

        return in_array($value, ['algo', 'legacy'], true) ? $value : null;
    }

    private function rememberConditionalMode(string $endpoint): void
    {
        try {
            $ttl = max(30, (int) config('binance.conditional_mode_cache_ttl', 600));
            Cache::put($this->conditionalModeKey(), $endpoint, $ttl);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Place a conditional order (payload in the LEGACY /fapi/v1/order shape: stopPrice,
     * newClientOrderId, closePosition, workingType, optional positionSide).
     *
     *  legacy: POST /fapi/v1/order exactly as before.
     *  algo  : POST /fapi/v1/algoOrder only.
     *  auto  : algo first. Falls back to legacy ONLY on a definite "unsupported" rejection of the
     *          algo call (never on timeout/5xx/unknown outcome: those are resolved by querying the
     *          clientAlgoId, never by placing again). A legacy -4120 switches to algo. The learned
     *          mode is cached briefly so the wrong endpoint is not hammered.
     * Never silent: every failure logs [BINANCE_CONDITIONAL_ORDER_FAILED] at critical level with
     * the exact Binance code/message, then rethrows.
     */
    public function placeConditionalOrder(array $payload): array
    {
        $type = strtoupper((string) ($payload['type'] ?? ''));
        if (! BinanceAlgoOrderApi::isConditionalType($type)) {
            return $this->placeOrder($payload);
        }

        $mode = $this->conditionalOrderMode();
        $sequence = match ($mode) {
            'legacy' => ['legacy'],
            'algo' => ['algo'],
            default => $this->learnedConditionalMode() === 'legacy' ? ['legacy', 'algo'] : ['algo', 'legacy'],
        };

        $last = null;
        foreach ($sequence as $endpoint) {
            try {
                $result = $endpoint === 'algo'
                    ? $this->placeConditionalViaAlgo($payload)
                    : $this->placeConditionalViaLegacy($payload);
                if ($mode === 'auto') {
                    $this->rememberConditionalMode($endpoint);
                }

                return $result;
            } catch (BinanceConditionalOrderException $e) {
                $last = $e;
                if ($mode === 'auto' && $endpoint === 'algo' && $e->isAlgoUnsupported()) {
                    $this->rememberConditionalMode('legacy');
                    Log::warning('[BINANCE_CONDITIONAL_MODE_SWITCH] algo endpoint definitively unsupported, trying legacy', $e->context());

                    continue;
                }
                if ($mode === 'auto' && $endpoint === 'legacy' && $e->isAlgoRequired()) {
                    $this->rememberConditionalMode('algo');
                    Log::warning('[BINANCE_CONDITIONAL_MODE_SWITCH] legacy endpoint answered -4120, switching to algo', $e->context());

                    continue;
                }
                break;
            } catch (\Throwable $e) {
                // Legacy-path transport failure (same as before this change): surface, never swallow.
                $this->logConditionalFailure($payload, $mode, ['endpoint' => $endpoint, 'outcome' => 'unknown'], $e->getMessage());
                throw $e;
            }
        }

        $this->logConditionalFailure($payload, $mode, $last?->context() ?? [], $last?->getMessage());

        throw $last;
    }

    private function logConditionalFailure(array $payload, string $mode, array $context, ?string $message = null): void
    {
        try {
            Log::critical('[BINANCE_CONDITIONAL_ORDER_FAILED] exchange-side protective order NOT confirmed', [
                'symbol' => $payload['symbol'] ?? null,
                'side' => $payload['side'] ?? null,
                'type' => $payload['type'] ?? null,
                'client_order_id' => $payload['newClientOrderId'] ?? null,
                'mode' => $mode,
                'message' => $message !== null ? $this->redact($message) : null,
            ] + $context);
        } catch (\Throwable) {
            // logging must never mask the real failure
        }
    }

    /** @return array{0: ?int, 1: string} [binance code, binance msg] */
    private function binanceError(Response $response): array
    {
        $code = $response->json('code');
        $msg = $response->json('msg');

        return [
            is_numeric($code) ? (int) $code : null,
            mb_substr(is_scalar($msg) ? (string) $msg : '', 0, 300),
        ];
    }

    private function placeConditionalViaLegacy(array $payload): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', $payload, false);
        if ($response->successful()) {
            return array_merge((array) $response->json(), ['endpoint' => 'legacy', 'algo' => false]);
        }

        [$code, $msg] = $this->binanceError($response);

        throw new BinanceConditionalOrderException(
            'Binance futures signed request failed. '.$response->body(),
            'legacy',
            $response->status() >= 500 ? 'unknown' : 'rejected',
            $response->status(),
            $code,
            $msg,
        );
    }

    private function placeConditionalViaAlgo(array $payload): array
    {
        try {
            $params = BinanceAlgoOrderApi::placeParams($payload);
        } catch (\InvalidArgumentException $e) {
            throw new BinanceConditionalOrderException($e->getMessage(), 'algo', 'rejected');
        }

        try {
            $response = $this->signedApiCall('POST', BinanceAlgoOrderApi::PLACE_PATH, $params);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            // Raised BEFORE any request left this process: definitely nothing was placed.
            if (str_contains($message, 'binance_key_dead') || str_contains($message, 'binance_rest_circuit_open') || str_contains($message, 'credentials are required')) {
                throw new BinanceConditionalOrderException($this->redact($message), 'algo', 'rejected');
            }

            return $this->resolveUnknownAlgoPlacement($params, 'transport error: '.$this->redact($message));
        }

        if ($response->successful()) {
            $json = (array) $response->json();
            if (isset($json['algoId']) && (string) $json['algoId'] !== '' && (string) $json['algoId'] !== '0') {
                return BinanceAlgoOrderApi::normalize($json);
            }

            return $this->resolveUnknownAlgoPlacement($params, 'HTTP '.$response->status().' without algoId');
        }

        [$code, $msg] = $this->binanceError($response);
        if ($response->status() >= 500 || in_array($code, [-1001, -1006, -1007], true)) {
            return $this->resolveUnknownAlgoPlacement($params, 'HTTP '.$response->status().' code '.($code ?? 'n/a').': '.$msg);
        }

        // The transport layer retries a POST once on 429/5xx with the SAME clientAlgoId, so a "duplicate
        // id" style rejection can be the echo of our own first attempt. Before reporting failure, check
        // whether this exact order already exists (never for an "unsupported" answer).
        if (! BinanceAlgoOrderApi::isUnsupportedRejection($response->status(), $code)) {
            [$found] = $this->findPlacedAlgoOrder($params);
            if ($found !== null) {
                return $this->recoveredAlgoOrder($found, $params, 'rejected HTTP '.$response->status().' code '.($code ?? 'n/a').': '.$msg);
            }
        }

        throw new BinanceConditionalOrderException(
            sprintf('Binance algo order rejected (HTTP %d, code %s): %s', $response->status(), $code ?? 'n/a', $msg),
            'algo',
            'rejected',
            $response->status(),
            $code,
            $msg,
        );
    }

    /**
     * Ask the exchange whether OUR order (same clientAlgoId, symbol, type, side, trigger price)
     * already exists. Never throws. [found|null, lookupError|null].
     *
     * @return array{0: ?array, 1: ?string}
     */
    private function findPlacedAlgoOrder(array $params): array
    {
        try {
            $found = $this->lookupAlgoOrderByClientId((string) $params['symbol'], (string) $params['clientAlgoId']);
        } catch (\Throwable $e) {
            return [null, $this->redact($e->getMessage())];
        }

        if ($found === null) {
            return [null, null];
        }

        $sameTrigger = ! isset($params['triggerPrice'])
            || abs((float) ($found['stopPrice'] ?? 0) - (float) $params['triggerPrice']) <= 1e-9 * max(1.0, abs((float) $params['triggerPrice']));
        $matches = strtoupper((string) ($found['symbol'] ?? '')) === strtoupper((string) $params['symbol'])
            && strtoupper((string) ($found['type'] ?? '')) === strtoupper((string) $params['type'])
            && (empty($found['side']) || strtoupper((string) $found['side']) === strtoupper((string) $params['side']))
            && $sameTrigger;

        return [$matches ? $found : null, null];
    }

    /**
     * The algo placement may or may not have reached Binance. NEVER place again here: ask the
     * exchange for our clientAlgoId. Found (same symbol/type/side/trigger) => that is our order.
     * Otherwise throw an UNKNOWN-outcome error (the caller's existing failure handling applies; the
     * order is not re-sent, so no duplicate protective order can come from this call).
     */
    private function resolveUnknownAlgoPlacement(array $params, string $reason): array
    {
        [$found, $lookupError] = $this->findPlacedAlgoOrder($params);
        if ($found !== null) {
            return $this->recoveredAlgoOrder($found, $params, $reason);
        }

        throw new BinanceConditionalOrderException(
            'Binance algo order outcome UNKNOWN ('.$reason.'); lookup of clientAlgoId '.$params['clientAlgoId'].' '
                .($lookupError !== null ? 'failed: '.$lookupError : 'found no matching order')
                .'; not re-sent to avoid a duplicate protective order.',
            'algo',
            'unknown',
        );
    }

    private function recoveredAlgoOrder(array $found, array $params, string $reason): array
    {
        Log::warning('[BINANCE_ALGO_ORDER_RECOVERED] placement outcome settled by clientAlgoId lookup', [
            'client_algo_id' => $params['clientAlgoId'],
            'algo_id' => $found['algoId'] ?? null,
            'reason' => $reason,
        ]);

        return $found + ['recovered' => true];
    }

    /**
     * Find an algo order by clientAlgoId: the open-algo list first (verified route), then
     * GET /fapi/v1/algoOrder (body not confirmed). Returns null when the exchange answered but
     * has no such order; throws only when neither lookup produced an answer.
     */
    private function lookupAlgoOrderByClientId(string $symbol, string $clientId): ?array
    {
        $errors = [];
        $answered = false;

        try {
            $rows = $this->fetchOpenAlgoOrders($symbol, true);
            $answered = true;
            foreach ($rows as $row) {
                if ((string) ($row['clientOrderId'] ?? '') === $clientId) {
                    return $row;
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $response = $this->signedApiCall('GET', BinanceAlgoOrderApi::QUERY_PATH, ['clientAlgoId' => $clientId]);
            if ($response->successful()) {
                $json = (array) $response->json();
                if (isset($json['algoId']) && (string) $json['algoId'] !== '') {
                    return BinanceAlgoOrderApi::normalize($json);
                }
            } elseif ($response->status() < 500) {
                $answered = true;    // a definite "no such order" style answer
            } else {
                $errors[] = 'HTTP '.$response->status();
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (! $answered) {
            throw new RuntimeException(implode(' | ', $errors) ?: 'algo order lookup unavailable');
        }

        return null;
    }

    /**
     * GET /fapi/v1/openAlgoOrders (pending conditional orders), normalised to the legacy
     * open-order field names (orderId=algoId, clientOrderId, type, stopPrice, reduceOnly) with
     * `algo=true`. Legacy /fapi/v1/openOrders does NOT list these. Returns [] without any HTTP
     * call in legacy mode (or auto mode after the algo endpoint was learned as unsupported).
     * Throws on any other failure/unexpected shape so callers can flag the snapshot unavailable
     * instead of believing "no stop exists".
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchOpenAlgoOrders(?string $symbol = null, bool $force = false): array
    {
        $mode = $this->conditionalOrderMode();
        if (! $force && ($mode === 'legacy' || ($mode === 'auto' && $this->learnedConditionalMode() === 'legacy'))) {
            return [];
        }

        $params = [];
        if ($symbol) {
            $params['symbol'] = BinanceSymbolNormalizer::normalize($symbol);
        }

        $response = $this->signedApiCall('GET', BinanceAlgoOrderApi::OPEN_PATH, $params);
        if (! $response->successful()) {
            [$code] = $this->binanceError($response);
            if ($mode === 'auto' && BinanceAlgoOrderApi::isUnsupportedRejection($response->status(), $code)) {
                $this->rememberConditionalMode('legacy');

                return [];
            }

            throw new RuntimeException('Binance open algo orders failed. '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json) || ($json !== [] && ! array_is_list($json))) {
            throw new RuntimeException('Binance open algo orders: unexpected response shape.');
        }

        return array_values(array_map(
            fn (array $row) => BinanceAlgoOrderApi::normalize($row),
            array_filter($json, 'is_array')
        ));
    }

    /** GET /fapi/v1/algoOrder by algoId or clientAlgoId (response body not confirmed). */
    public function fetchAlgoOrder(?string $algoId = null, ?string $clientAlgoId = null): array
    {
        $params = $algoId ? ['algoId' => $algoId] : ['clientAlgoId' => (string) $clientAlgoId];
        $response = $this->signedApiCall('GET', BinanceAlgoOrderApi::QUERY_PATH, $params);
        if (! $response->successful()) {
            throw new RuntimeException('Binance algo order query failed. '.$response->body());
        }

        return BinanceAlgoOrderApi::normalize((array) $response->json());
    }

    /**
     * Generic SIGNED call that never throws on a non-2xx answer and always returns the
     * raw Response, so the caller can classify "definitely rejected" (a 4xx with a Binance
     * code) from "outcome unknown" (transport exception/5xx). Added for Quick Trade Manual
     * SL/TP (App\Domain\QuickTradeManual) which needs the Algo Order endpoints; it reuses
     * this client's key-health cache, server-time resync, REST circuit breaker and throttle.
     * Transport failures still throw (after the client's own retry), exactly like request().
     *
     * DELETE parameters are sent in the QUERY STRING (Binance reads form/query parameters;
     * Laravel's delete($url, $data) would send a JSON body). No existing caller is affected.
     */
    public function signedApiCall(string $method, string $path, array $params = []): Response
    {
        $this->assertKeyHealthy();

        $send = function () use ($method, $path, $params): Response {
            $signed = $this->sign($params);
            if (strtoupper($method) === 'DELETE') {
                return $this->request('DELETE', $path.'?'.http_build_query($signed, '', '&'), [], true);
            }

            return $this->request($method, $path, $signed, true);
        };

        $response = $send();
        if ($this->isTimestampOutsideRecvWindow($response)) {
            $this->syncServerTimeOffset();
            $response = $send();
        }

        return $response;
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

    /**
     * Cancel one order. `$algo` says which API owns it: true = Algo Order service (DELETE
     * /fapi/v1/algoOrder, orderId is then the algoId), false = legacy /fapi/v1/order, null =
     * unknown (callers that only stored a client id): legacy mode -> legacy; algo mode -> algo;
     * auto -> try the learned endpoint then the other (cancelling is idempotent, so trying both
     * is safe; a failure is raised only if both fail). Rows from fetchOpenOrders()/
     * fetchOpenAlgoOrders() carry the marker: pass `(bool) ($row['algo'] ?? false)`.
     */
    public function cancelOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null, ?bool $algo = null): array
    {
        $mode = $this->conditionalOrderMode();

        if ($algo === false || $mode === 'legacy') {
            return $this->cancelLegacyOrder($symbol, $orderId, $clientOrderId);
        }
        if ($algo === true || $mode === 'algo') {
            return $this->cancelAlgoOrder($orderId, $clientOrderId);
        }
        if (! $clientOrderId) {
            // auto + unmarked + no client id: a bare numeric id is ambiguous between id spaces.
            return $this->cancelLegacyOrder($symbol, $orderId, $clientOrderId);
        }

        $sequence = $this->learnedConditionalMode() === 'legacy' ? ['legacy', 'algo'] : ['algo', 'legacy'];
        $errors = [];
        foreach ($sequence as $endpoint) {
            try {
                return $endpoint === 'algo'
                    ? $this->cancelAlgoOrder(null, $clientOrderId)
                    : $this->cancelLegacyOrder($symbol, $orderId, $clientOrderId);
            } catch (\Throwable $e) {
                $errors[] = $endpoint.': '.$this->redact($e->getMessage());
            }
        }

        throw new RuntimeException('Binance cancel failed on both endpoints. '.implode(' | ', $errors));
    }

    /**
     * Legacy DELETE /fapi/v1/order. DELETE parameters go in the QUERY STRING (Binance does not read
     * a JSON body; Laravel's delete($url, $data) sends JSON, which made this call unable to carry
     * symbol/timestamp/signature). Narrow fix, only for the cancel calls.
     */
    private function cancelLegacyOrder(string $symbol, ?string $orderId, ?string $clientOrderId): array
    {
        $params = ['symbol' => BinanceSymbolNormalizer::normalize($symbol)];
        if ($orderId) {
            $params['orderId'] = $orderId;
        }
        if ($clientOrderId) {
            $params['origClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedApiCall('DELETE', '/fapi/v1/order', $params);
        $this->assertSuccess($response, 'Binance futures signed request failed.');

        return array_merge((array) $response->json(), ['endpoint' => 'legacy', 'algo' => false]);
    }

    private function cancelAlgoOrder(?string $algoId, ?string $clientAlgoId): array
    {
        if ($algoId) {
            $params = ['algoId' => $algoId];
        } elseif ($clientAlgoId) {
            $params = ['clientAlgoId' => $clientAlgoId];
        } else {
            throw new RuntimeException('Binance algo cancel needs an algoId or clientAlgoId.');
        }

        $response = $this->signedApiCall('DELETE', BinanceAlgoOrderApi::CANCEL_PATH, $params);
        if ($response->successful()) {
            $json = (array) $response->json();
            $code = $json['code'] ?? null;
            if ($code === null || in_array((string) $code, ['200', '0'], true)) {
                return array_merge($json, ['endpoint' => 'algo', 'algo' => true]);
            }
        }

        throw new RuntimeException('Binance algo cancel failed. '.$response->body());
    }

    /**
     * Cancel all open orders for a symbol: legacy /fapi/v1/allOpenOrders (does NOT cover algo
     * orders) plus, unless mode=legacy, DELETE /fapi/v1/algoOpenOrders. Returns the legacy answer
     * (shape unchanged) with the algo answer under `algo_cancel`.
     */
    public function cancelOpenOrders(string $symbol): array
    {
        $normalized = BinanceSymbolNormalizer::normalize($symbol);
        $mode = $this->conditionalOrderMode();

        $response = $this->signedApiCall('DELETE', '/fapi/v1/allOpenOrders', ['symbol' => $normalized]);
        if ($mode === 'legacy') {
            $this->assertSuccess($response, 'Binance futures signed request failed.');

            return (array) $response->json();
        }
        $legacy = $response->successful() ? (array) $response->json() : null;
        $legacyError = $response->successful() ? null : $response->body();

        $algo = null;
        $algoResponse = $this->signedApiCall('DELETE', BinanceAlgoOrderApi::CANCEL_ALL_PATH, ['symbol' => $normalized]);
        if ($algoResponse->successful()) {
            $algo = (array) $algoResponse->json();
        } else {
            [$code] = $this->binanceError($algoResponse);
            $unsupported = BinanceAlgoOrderApi::isUnsupportedRejection($algoResponse->status(), $code);
            if (! ($mode === 'auto' && $unsupported)) {
                throw new RuntimeException('Binance algo cancel-all failed. '.$algoResponse->body());
            }
        }
        if ($legacyError !== null) {
            throw new RuntimeException('Binance futures signed request failed. '.$legacyError);
        }

        return (array) $legacy + ['algo_cancel' => $algo];
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
                'error' => $this->redact($e->getMessage()),
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
                'error' => $this->redact($e->getMessage()),
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

    /**
     * Strips signed-URL parameters so a key/signature can never reach a log
     * line -- same redaction contract as
     * AccountFeeRateService::redact()/BinanceTimedFuturesProvider::redact(),
     * needed here because realFeeForOrder()/realFundingFee() call the same
     * signed Binance endpoints and a transport-level failure's exception
     * message can otherwise include the full request URL (query string,
     * `signature=` included).
     */
    private function redact(string $message): string
    {
        $message = (string) preg_replace('/(signature|X-MBX-APIKEY|api_key|api_secret|apiKey|secret)([=:]\s*)[^&\s"\']+/i', '$1$2[redacted]', $message);

        return mb_substr($message, 0, 300);
    }
}
