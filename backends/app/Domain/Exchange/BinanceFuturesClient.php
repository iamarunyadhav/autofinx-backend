<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            'symbol'   => BinanceSymbolNormalizer::normalize($symbol),
            'leverage' => (int) round($leverage),
        ]);

        return (array) $response->json();
    }

    public function setMarginType(string $symbol, string $marginMode): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/marginType', [
            'symbol'     => BinanceSymbolNormalizer::normalize($symbol),
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

    public function placeOrder(array $payload): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', $payload);

        return (array) $response->json();
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
            'symbol'           => BinanceSymbolNormalizer::normalize($symbol),
            'side'             => $side,
            'type'             => $type,
            'stopPrice'        => number_format(round($stopPrice, 8), 8, '.', ''),
            'closePosition'    => 'true',
            'workingType'      => $workingType,
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
            'limit'  => max(1, min(1000, $limit)),
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function signedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        $signedPayload = $this->sign($params);
        $response = $this->request($method, $path, $signedPayload, true);

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures signed request failed.');
        }

        return $response;
    }

    private function keyedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        $response = $this->request($method, $path, $params, true);

        if ($throw) {
            $this->assertSuccess($response, 'Binance futures keyed request failed.');
        }

        return $response;
    }

    private function apiHeaders(): array
    {
        return ['X-MBX-APIKEY' => $this->apiKey];
    }

    private function sign(array $payload): array
    {
        $payload['timestamp'] = (int) floor(microtime(true) * 1000);
        $payload['recvWindow'] = 5000;
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $payload['signature'] = hash_hmac('sha256', $query, $this->apiSecret);

        return $payload;
    }

    private function assertSuccess(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($message . ' ' . $response->body());
    }

    private function url(string $path): string
    {
        return $this->baseUrl . $path;
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
            $cacheKey = 'binance_dns_warm:' . $host;
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
    // Retry delays: 0 ms → 300 ms → 900 ms → 2000 ms (exponential backoff).
    // Circuit breaker opens after 5 consecutive failures (exceptions OR 5xx/429).
    // Stays open 45 s, then resets so the next request probes liveness.

    private function request(string $method, string $path, array $params = [], bool $authenticated = false): Response
    {
        $host = (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
        $this->prewarmDns($host);
        $this->assertCircuitClosed($host);

        $delaysMs = [0, 300, 900, 2000];
        $lastException = null;

        foreach ($delaysMs as $index => $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $pending = Http::withHeaders($authenticated ? $this->apiHeaders() : [])
                    ->timeout(12)
                    ->connectTimeout(5);

                $response = match (strtoupper($method)) {
                    'GET'    => $pending->get($this->url($path), $params),
                    'PUT'    => $pending->asForm()->put($this->url($path), $params),
                    'DELETE' => $pending->delete($this->url($path), $params),
                    default  => $pending->asForm()->post($this->url($path), $params),
                };

                // Retry on transient HTTP errors (rate-limit, gateway errors, etc.)
                if ($this->isRetryableStatus($response) && $index !== array_key_last($delaysMs)) {
                    $lastException = new RuntimeException(
                        'Binance futures REST returned retryable status ' . $response->status()
                    );
                    continue;
                }

                // Success (or non-retryable response) — reset circuit breaker
                if ($this->isRetryableStatus($response)) {
                    $this->circuitRecordFailure($host, 'HTTP ' . $response->status() . ': ' . $response->body());
                } else {
                    $this->circuitSuccess($host);
                }

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
        $state = (array) (Cache::get('binance_cb:' . $host, []));
        if (($state['open'] ?? false) && time() < (int) ($state['until'] ?? 0)) {
            $remaining = (int) ($state['until'] ?? 0) - time();
            throw new RuntimeException(
                'binance_rest_circuit_open: Binance API circuit breaker is open (' .
                $remaining . 's remaining). Last error: ' .
                (string) ($state['last_error'] ?? 'unknown')
            );
        }
    }

    private function circuitRecordFailure(string $host, ?string $error = null): void
    {
        try {
            $key      = 'binance_cb:' . $host;
            $state    = (array) (Cache::get($key, []));
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $lastError = $error !== null && $error !== ''
                ? mb_substr($error, 0, 500)
                : (string) ($state['last_error'] ?? 'repeated_failures');

            if ($failures >= 5) {
                Cache::put($key, [
                    'open'       => true,
                    'until'      => time() + 45,
                    'failures'   => $failures,
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
            Cache::forget('binance_cb:' . $host);
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
