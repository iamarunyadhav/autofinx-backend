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

    public function placeOrder(array $payload): array
    {
        $response = $this->signedRequest('POST', '/fapi/v1/order', $payload);

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
        return rtrim($this->baseUrl, '/') . $path;
    }

    /**
     * Best-effort DNS pre-warm. Resolves the API host once per minute and
     * stores nothing more than a "did we already warm it" marker. The actual
     * cURL stack still does the resolution; this just primes the OS resolver
     * cache so the first signed request doesn't pay the cold-resolver tax.
     */
    private function prewarmDns(): void
    {
        try {
            $host = parse_url($this->baseUrl, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                return;
            }
            $cacheKey = 'binance_dns_warm:' . $host;
            if (Cache::get($cacheKey)) {
                return;
            }
            $resolved = @gethostbyname($host);
            // gethostbyname returns the input on failure — treat that as a miss.
            if ($resolved && $resolved !== $host) {
                Cache::put($cacheKey, $resolved, 60);
            }
        } catch (\Throwable $e) {
            // Pre-warm is best-effort; never propagate failures.
        }
    }

    private function request(string $method, string $path, array $params = [], bool $authenticated = false): Response
    {
        $circuitKey = $this->circuitKey($path, $authenticated);
        if ((bool) Cache::get($circuitKey . ':open', false)) {
            throw new RuntimeException('binance_rest_circuit_open: repeated Binance REST failures; retry after 30-60 seconds.');
        }

        // DNS pre-warm — avoids the "cURL error 6: getaddrinfo() thread failed
        // to start" burst that happens when the OS DNS resolver is cold under
        // load. We cache the resolved hostname for 60s so subsequent calls hit
        // the warm path with no resolver round-trip in the cURL stack.
        $this->prewarmDns();

        $delaysMs = [0, 500, 1500];
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
                    'GET' => $pending->get($this->url($path), $params),
                    'PUT' => $pending->asForm()->put($this->url($path), $params),
                    'DELETE' => $pending->delete($this->url($path), $params),
                    default => $pending->asForm()->post($this->url($path), $params),
                };

                if ($this->isRetryableStatus($response) && $index !== array_key_last($delaysMs)) {
                    $lastException = new RuntimeException('Binance futures REST returned retryable HTTP ' . $response->status());
                    continue;
                }

                if (! $this->isRetryableStatus($response)) {
                    $this->clearCircuit($circuitKey);
                } elseif ($index === array_key_last($delaysMs)) {
                    $this->recordCircuitFailure($circuitKey);
                }

                return $response;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                if (! $this->isRetryableException($exception) || $index === array_key_last($delaysMs)) {
                    if ($this->isRetryableException($exception)) {
                        $this->recordCircuitFailure($circuitKey);
                    }
                    throw $exception;
                }
            }
        }

        throw $lastException instanceof \Throwable
            ? $lastException
            : new RuntimeException('Binance futures request failed without a response.');
    }

    private function isRetryableException(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
            'curl error 6',
            'getaddrinfo',
            'could not resolve host',
            'timed out',
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

    private function isRetryableStatus(Response $response): bool
    {
        return in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true);
    }

    private function circuitKey(string $path, bool $authenticated): string
    {
        return 'binance_futures_rest_circuit:' . md5($this->baseUrl . '|' . $path . '|' . ($authenticated ? 'auth' : 'public'));
    }

    private function recordCircuitFailure(string $key): void
    {
        $count = (int) Cache::increment($key . ':failures');
        Cache::put($key . ':failures', $count, now()->addSeconds(300));
        if ($count >= 3) {
            Cache::put($key . ':open', true, now()->addSeconds(45));
        }
    }

    private function clearCircuit(string $key): void
    {
        Cache::forget($key . ':failures');
        Cache::forget($key . ':open');
    }
}
