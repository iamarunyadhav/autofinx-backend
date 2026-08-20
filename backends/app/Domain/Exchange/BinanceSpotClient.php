<?php

namespace App\Domain\Exchange;

use App\Domain\Exchange\DTO\ExchangeOrder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BinanceSpotClient implements ExchangeClientInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $baseUrl,
        private readonly string $wsUrl,
    ) {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Binance API credentials are required for live execution.');
        }
    }

    public function getLatestPrice(string $symbol): float
    {
        $response = Http::get($this->url('/api/v3/ticker/price'), [
            'symbol' => $this->normalizeSymbol($symbol),
        ]);

        $this->assertSuccess($response, 'Failed to fetch latest Binance price.');

        return (float) ($response->json('price') ?? 0);
    }

    public function placeLimitIOCOrder(
        string $symbol,
        string $side,
        float $qty,
        float $limitPrice,
        ?string $clientOrderId = null
    ): ExchangeOrder
    {
        $payload = [
            'symbol' => $this->normalizeSymbol($symbol),
            'side' => strtoupper($side),
            'type' => 'LIMIT',
            'timeInForce' => 'IOC',
            'quantity' => $this->formatDecimal($qty),
            'price' => $this->formatDecimal($limitPrice),
            'newOrderRespType' => 'FULL',
        ];
        if ($clientOrderId) {
            $payload['newClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedRequest('POST', '/api/v3/order', $payload);

        return ExchangeOrder::fromBinance($response->json());
    }

    /**
     * Place a MARKET order.
     *
     * Idempotency: pass a stable $clientOrderId derived from your internal
     * operation ID. If the same clientOrderId reaches Binance twice, the second
     * request is rejected with error -2010 (duplicate order), which is safely
     * caught by callers checking for idempotency.
     *
     * NOTE: Market orders execute immediately at the best available price.
     * They carry inherent slippage risk and should only be used when speed
     * outweighs price precision (e.g. emergency exits, time-sensitive AI cycles).
     */
    public function placeMarketOrder(
        string $symbol,
        string $side,
        float $qty,
        ?string $clientOrderId = null
    ): ExchangeOrder {
        $normalizedSymbol = $this->normalizeSymbol($symbol);
        $payload = [
            'symbol' => $normalizedSymbol,
            'side' => strtoupper($side),
            'type' => 'MARKET',
            'quantity' => $this->formatDecimal($qty),
            'newOrderRespType' => 'FULL',
        ];
        if ($clientOrderId) {
            $payload['newClientOrderId'] = $clientOrderId;
        }

        $response = $this->signedRequestWithRetry('POST', '/api/v3/order', $payload);
        $json = (array) $response->json();

        $price = $this->resolveFillPrice($json);
        $executedQty = (float) ($json['executedQty'] ?? 0);

        // Binance MARKET RESULT responses (notably on testnet) can return
        // price="0.00" with an empty/absent `fills` array. Re-querying the
        // order by orderId is best-effort — testnet can transiently
        // 400/-2013 ("order does not exist") for a few hundred ms right
        // after the fill — and must never fail an already-confirmed order.
        if ($price <= 0 || $executedQty <= 0) {
            $fetched = $this->safeFetchOrderRaw($normalizedSymbol, (string) ($json['orderId'] ?? ''));
            if ($fetched !== []) {
                $json = array_merge($json, $fetched);
                $price = $this->resolveFillPrice($json);
                $executedQty = (float) ($json['executedQty'] ?? $executedQty);
            }
        }

        if ($price <= 0) {
            // Final fallback: live reference quote. Callers separately guard
            // on executedQty<=0 (an invalid/unfilled order), so backfilling
            // price here never masks a genuinely-unfilled order.
            $price = $this->getLatestPrice($symbol);
            Log::warning('binance_spot.market_order_price_fallback_to_quote', [
                'symbol' => $normalizedSymbol,
                'order_id' => $json['orderId'] ?? null,
                'client_order_id' => $clientOrderId,
                'executed_qty' => $executedQty,
            ]);
        }

        return ExchangeOrder::fromBinance($json, $price > 0 ? $price : null);
    }

    /**
     * Resolve a usable average fill price for a Spot order payload, in order
     * of reliability. Spot has no `avgPrice` field (unlike Futures) — MARKET
     * orders must be priced from `fills` (POST response only) or
     * `cummulativeQuoteQty / executedQty` (present on both POST and GET
     * /api/v3/order responses). Treats 0/absent as missing rather than a
     * real price, since Binance returns "0.00" (not null) on responses that
     * omit a real fill price.
     */
    private function resolveFillPrice(array $payload): float
    {
        $fills = (array) ($payload['fills'] ?? []);
        if ($fills !== []) {
            $qty = 0.0;
            $quote = 0.0;
            foreach ($fills as $fill) {
                $fillQty = (float) ($fill['qty'] ?? 0);
                $fillPrice = (float) ($fill['price'] ?? 0);
                $qty += $fillQty;
                $quote += $fillQty * $fillPrice;
            }
            if ($qty > 0 && $quote > 0) {
                return round($quote / $qty, 8);
            }
        }

        $executedQty = (float) ($payload['executedQty'] ?? 0);
        $cumQuote = (float) ($payload['cummulativeQuoteQty'] ?? 0);
        if ($executedQty > 0 && $cumQuote > 0) {
            return round($cumQuote / $executedQty, 8);
        }

        foreach (['avgPrice', 'price'] as $key) {
            $value = (float) ($payload[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0.0;
    }

    /**
     * Best-effort order lookup that never throws — mirrors
     * BinanceTimedFuturesProvider::safeFetchOrder(). Binance (testnet
     * especially) can -2013 "Order does not exist" for a few hundred ms
     * right after a market fill, which must not fail an already-confirmed
     * order.
     */
    private function safeFetchOrderRaw(string $symbol, string $orderId): array
    {
        if ($orderId === '') {
            return [];
        }

        try {
            return $this->fetchOrder($symbol, $orderId)->raw;
        } catch (Throwable) {
            return [];
        }
    }

    public function placeOCOOrder(
        string $symbol,
        string $side,
        float $qty,
        float $limitPrice,
        float $stopPrice,
        float $stopLimitPrice,
        ?string $clientOrderId = null
    ): ExchangeOrder {
        $payload = [
            'symbol' => $this->normalizeSymbol($symbol),
            'side' => strtoupper($side),
            'quantity' => $this->formatDecimal($qty),
            'price' => $this->formatDecimal($limitPrice),
            'stopPrice' => $this->formatDecimal($stopPrice),
            'stopLimitPrice' => $this->formatDecimal($stopLimitPrice),
            'stopLimitTimeInForce' => 'GTC',
        ];
        if ($clientOrderId) {
            $payload['listClientOrderId'] = $clientOrderId;
        }

        // New endpoint in Binance Spot API.
        $response = $this->signedRequest('POST', '/api/v3/orderList/oco', $payload, false);
        if ($response->failed()) {
            // Backward-compatible endpoint.
            $response = $this->signedRequest('POST', '/api/v3/order/oco', $payload);
        }

        return ExchangeOrder::fromBinance($response->json());
    }

    public function cancelOrder(string $symbol, string $orderId): array
    {
        $response = $this->signedRequest('DELETE', '/api/v3/order', [
            'symbol' => $this->normalizeSymbol($symbol),
            'orderId' => $orderId,
        ]);

        return $response->json();
    }

    public function fetchOrder(string $symbol, string $orderId): ExchangeOrder
    {
        $response = $this->signedRequest('GET', '/api/v3/order', [
            'symbol' => $this->normalizeSymbol($symbol),
            'orderId' => $orderId,
        ]);

        return ExchangeOrder::fromBinance($response->json());
    }

    public function fetchOrderByClientOrderId(string $symbol, string $clientOrderId): ExchangeOrder
    {
        $response = $this->signedRequest('GET', '/api/v3/order', [
            'symbol' => $this->normalizeSymbol($symbol),
            'origClientOrderId' => $clientOrderId,
        ]);

        return ExchangeOrder::fromBinance($response->json());
    }

    public function fetchBalances(): array
    {
        $response = $this->signedRequest('GET', '/api/v3/account', []);
        $balances = (array) ($response->json('balances') ?? []);

        return array_values(array_filter($balances, static function (array $balance): bool {
            return ((float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0)) > 0;
        }));
    }

    public function fetchMyTrades(string $symbol, ?int $fromId = null): array
    {
        $params = ['symbol' => $this->normalizeSymbol($symbol)];
        if ($fromId !== null) {
            $params['fromId'] = $fromId;
        }

        $response = $this->signedRequest('GET', '/api/v3/myTrades', $params);

        return (array) $response->json();
    }

    public function fetchExchangeInfo(?string $symbol = null): array
    {
        $params = [];
        if ($symbol) {
            $params['symbol'] = $this->normalizeSymbol($symbol);
        }

        $response = Http::get($this->url('/api/v3/exchangeInfo'), $params);
        $this->assertSuccess($response, 'Failed to fetch Binance exchange metadata.');

        return (array) $response->json();
    }

    public function createListenKey(): string
    {
        $response = Http::withHeaders($this->apiHeaders())->post($this->url('/api/v3/userDataStream'));
        $this->assertSuccess($response, 'Failed to create Binance user-data listenKey.');

        return (string) ($response->json('listenKey') ?? '');
    }

    public function keepAliveListenKey(string $listenKey): void
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->put($this->url('/api/v3/userDataStream'), ['listenKey' => $listenKey]);

        $this->assertSuccess($response, 'Failed to keep Binance user-data listenKey alive.');
    }

    public function closeListenKey(string $listenKey): void
    {
        $response = Http::withHeaders($this->apiHeaders())
            ->delete($this->url('/api/v3/userDataStream'), ['listenKey' => $listenKey]);

        $this->assertSuccess($response, 'Failed to close Binance user-data listenKey.');
    }

    public function wsUrlForListenKey(string $listenKey): string
    {
        $base = rtrim($this->wsUrl, '/');

        return $base . '/ws/' . $listenKey;
    }

    /**
     * Binance error codes that are safe to retry (transient network / rate-limit).
     * Codes that indicate a business logic rejection (e.g. insufficient balance,
     * duplicate order) must NOT be retried to avoid duplicate order risk.
     *
     * -1003 = Too many requests (rate limit)
     * -1021 = Timestamp for this request was outside the recvWindow
     * -1007 = Timeout waiting for response from backend
     */
    private const RETRYABLE_ERROR_CODES = [-1003, -1021, -1007];

    /**
     * Signed request with idempotency-safe retry on transient Binance errors.
     *
     * IMPORTANT: Only use this for read-only or idempotent operations with a
     * stable clientOrderId. Do NOT use for order placement unless the caller
     * supplies a stable clientOrderId that prevents duplicate orders.
     */
    private function signedRequestWithRetry(string $method, string $path, array $params = [], int $maxAttempts = 2): Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < max(1, $maxAttempts)) {
            $attempt++;
            try {
                $response = $this->signedRequest($method, $path, $params, false);

                if ($response->successful()) {
                    return $response;
                }

                $errorCode = (int) ($response->json('code') ?? 0);
                if (! in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
                    $this->assertSuccess($response, 'Binance request failed (non-retryable).');
                }

                // Transient error — wait briefly before retry.
                if ($attempt < $maxAttempts) {
                    Log::warning('binance.transient_error_retry', [
                        'attempt' => $attempt,
                        'path' => $path,
                        'error_code' => $errorCode,
                        'body' => $response->body(),
                    ]);
                    usleep(300_000); // 300 ms
                }

                $lastException = new RuntimeException('Binance transient error: ' . $response->body());
            } catch (RuntimeException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $lastException = $e;
                usleep(300_000);
            }
        }

        throw $lastException ?? new RuntimeException('Binance signed request failed after retries.');
    }

    private function signedRequest(string $method, string $path, array $params = [], bool $throw = true): Response
    {
        $signedPayload = $this->sign($params);
        $response = $this->dispatch($method, $path, $signedPayload);

        if ($this->isTimestampOutsideRecvWindow($response)) {
            $this->syncServerTimeOffset();
            $signedPayload = $this->sign($params);
            $response = $this->dispatch($method, $path, $signedPayload);
        }

        if ($throw) {
            $this->assertSuccess($response, 'Binance signed request failed.');
        }

        return $response;
    }

    private function dispatch(string $method, string $path, array $signedPayload): Response
    {
        return match (strtoupper($method)) {
            'GET' => Http::withHeaders($this->apiHeaders())->get($this->url($path), $signedPayload),
            'DELETE' => Http::withHeaders($this->apiHeaders())->delete($this->url($path), $signedPayload),
            default => Http::withHeaders($this->apiHeaders())->asForm()->post($this->url($path), $signedPayload),
        };
    }

    private function apiHeaders(): array
    {
        return ['X-MBX-APIKEY' => $this->apiKey];
    }

    private function sign(array $payload): array
    {
        $payload['timestamp'] = (int) floor(microtime(true) * 1000) + $this->serverTimeOffsetMs();
        $payload['recvWindow'] = max(5000, min(60000, (int) config('services.binance.spot_recv_window_ms', 5000)));
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $payload['signature'] = $signature;

        return $payload;
    }

    /**
     * Detect Binance's -1021 "Timestamp for this request was outside of the
     * recvWindow" error, which indicates local/server clock skew.
     */
    private function isTimestampOutsideRecvWindow(Response $response): bool
    {
        return (int) ($response->json('code') ?? 0) === -1021
            || str_contains(strtolower($response->body()), 'outside of the recvwindow');
    }

    private function serverTimeOffsetKey(): string
    {
        return 'autofinx:binance:spot_time_offset:' . md5($this->baseUrl);
    }

    private function serverTimeOffsetMs(): int
    {
        return (int) Cache::get($this->serverTimeOffsetKey(), 0);
    }

    /**
     * Reactively correct for local/server clock skew: fetch Binance's server
     * time, compute the offset against the local request midpoint, and cache
     * it briefly so subsequent signed requests self-correct without needing
     * a persistent NTP-style sync process.
     */
    private function syncServerTimeOffset(): void
    {
        try {
            $started = (int) floor(microtime(true) * 1000);
            $response = Http::get($this->url('/api/v3/time'));
            $finished = (int) floor(microtime(true) * 1000);
            $serverTime = (int) ($response->json('serverTime') ?? 0);
            if ($response->successful() && $serverTime > 0) {
                $localMidpoint = (int) floor(($started + $finished) / 2);
                Cache::put($this->serverTimeOffsetKey(), $serverTime - $localMidpoint, 600);
            }
        } catch (Throwable) {
            Cache::forget($this->serverTimeOffsetKey());
        }
    }

    /**
     * Check whether a Binance error response indicates a duplicate order
     * (client order ID already used). Callers should treat this as a signal
     * to fetch the existing order rather than retry placement.
     *
     * Binance error -2010 = "Account has insufficient balance for requested action"
     * is sometimes overloaded; the canonical duplicate detection is via
     * -2013 / message containing "Order does not exist" on cancels, and
     * -1013 / LOT_SIZE on format issues. For duplicate newClientOrderId,
     * Binance returns -2010 with message "Duplicate order sent."
     */
    public function isDuplicateOrderError(array $responseJson): bool
    {
        $code = (int) ($responseJson['code'] ?? 0);
        $msg = strtolower((string) ($responseJson['msg'] ?? ''));

        return $code === -2010 && str_contains($msg, 'duplicate');
    }

    private function assertSuccess(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException($message . ' ' . $response->body());
    }

    private function formatDecimal(float $value): string
    {
        $raw = number_format($value, 8, '.', '');
        $trimmed = rtrim(rtrim($raw, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function normalizeSymbol(string $symbol): string
    {
        return BinanceSymbolNormalizer::normalize($symbol);
    }
}
