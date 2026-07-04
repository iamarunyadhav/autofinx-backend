<?php

namespace App\Domain\Trading\Providers;

use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\Integration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceTimedFuturesProvider implements LiveTradeProviderInterface
{
    public function providerKey(): string
    {
        return 'binance';
    }

    public function capabilities(): array
    {
        return [
            'key' => 'binance',
            'label' => 'Binance',
            'live_supported' => true,
            'default_market_type' => 'futures',
            'supports_short' => true,
            'requires_integration' => true,
            'symbols' => [
                ['symbol' => 'BTCUSDT', 'display' => 'BTC / USDT', 'asset_class' => 'CRYPTO', 'payout_rate' => 0],
                ['symbol' => 'ETHUSDT', 'display' => 'ETH / USDT', 'asset_class' => 'CRYPTO', 'payout_rate' => 0],
                ['symbol' => 'BNBUSDT', 'display' => 'BNB / USDT', 'asset_class' => 'CRYPTO', 'payout_rate' => 0],
                ['symbol' => 'SOLUSDT', 'display' => 'SOL / USDT', 'asset_class' => 'CRYPTO', 'payout_rate' => 0],
            ],
            'timeframes' => ['1m', '5m', '15m'],
            'durations' => [60, 300, 900],
        ];
    }

    public function latestQuote(?Integration $integration, string $symbol): array
    {
        $response = Http::get($this->baseUrl($integration) . '/fapi/v1/ticker/price', [
            'symbol' => strtoupper($symbol),
        ]);

        $this->assertSuccess($response, 'Failed to fetch Binance futures quote.');

        $price = (float) ($response->json('price') ?? 0);

        return [
            'symbol' => strtoupper($symbol),
            'price' => $price,
            'bid' => $price,
            'ask' => $price,
            'volume' => 0,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function candles(?Integration $integration, string $symbol, string $timeframe, int $limit = 120): array
    {
        $normalizedSymbol = strtoupper($symbol);
        $interval = $this->normalizeInterval($timeframe);
        $response = Http::get($this->baseUrl($integration) . '/fapi/v1/klines', [
            'symbol' => $normalizedSymbol,
            'interval' => $interval,
            'limit' => min(max($limit, 10), 500),
        ]);

        $this->assertSuccess($response, 'Failed to fetch Binance futures candles.');

        return collect((array) $response->json())
            ->map(fn (array $row) => [
                'symbol' => $normalizedSymbol,
                'timeframe' => $interval,
                'timestamp' => (int) ($row[0] ?? 0),
                'open' => (float) ($row[1] ?? 0),
                'high' => (float) ($row[2] ?? 0),
                'low' => (float) ($row[3] ?? 0),
                'close' => (float) ($row[4] ?? 0),
                'volume' => (float) ($row[5] ?? 0),
                'opens_at' => now()->setTimestamp((int) floor(((int) ($row[0] ?? 0)) / 1000))->toIso8601String(),
            ])
            ->all();
    }

    public function availableBalance(Integration $integration, string $asset = 'USDT'): array
    {
        $response = $this->signedRequest($integration, 'GET', '/fapi/v2/balance', []);
        $rows = collect((array) $response->json());
        $balance = $rows->first(fn (array $row) => strtoupper((string) ($row['asset'] ?? '')) === strtoupper($asset));

        if (! $balance) {
            return [
                'asset' => strtoupper($asset),
                'balance' => 0.0,
                'available' => 0.0,
            ];
        }

        return [
            'asset' => strtoupper((string) ($balance['asset'] ?? $asset)),
            'balance' => (float) ($balance['balance'] ?? 0),
            'available' => (float) ($balance['availableBalance'] ?? $balance['withdrawAvailable'] ?? 0),
        ];
    }

    public function openTimedTrade(
        Integration $integration,
        string $symbol,
        string $side,
        float $stakeAmount,
        string $idempotencyKey
    ): array {
        $quote = $this->latestQuote($integration, $symbol);
        $quantity = $this->normalizeQuantity($integration, $symbol, $stakeAmount / max($quote['price'], 0.00000001), $quote['price']);
        $referencePrice = (float) ($quote['price'] ?? 0);

        $response = $this->signedRequest($integration, 'POST', '/fapi/v1/order', [
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side === 'sell' ? 'SELL' : 'BUY'),
            'type' => 'MARKET',
            'quantity' => $this->formatDecimal($quantity),
            'newOrderRespType' => 'RESULT',
            'newClientOrderId' => $this->clientOrderId($idempotencyKey, 'open'),
        ]);

        $payload = $response->json();
        $price = $this->fillPrice($payload);
        $executedQty = (float) ($payload['executedQty'] ?? $quantity);

        // Market RESULT responses on Binance futures (notably testnet) often omit
        // avgPrice and return price="0.00". Re-querying by orderId can transiently
        // -2013 immediately after the fill, so treat the fetch as best-effort and
        // fall back to the live quote rather than failing a confirmed fill.
        if ($price <= 0 || $executedQty <= 0) {
            $fetched = $this->safeFetchOrder($integration, strtoupper($symbol), (string) ($payload['orderId'] ?? ''));
            $payload = array_merge($payload, $fetched);
            $price = $this->fillPrice($payload);
            $executedQty = (float) ($payload['executedQty'] ?? $quantity);
        }
        if ($price <= 0) {
            $price = (float) ($quote['price'] ?? 0);
        }

        return [
            'provider' => 'binance',
            'market_type' => 'futures',
            'quote_currency' => 'USDT',
            'price' => $price,
            'quantity' => $executedQty,
            'reference_price' => $referencePrice,
            'notional' => round($price * $executedQty, 8),
            'estimated_fee_rate' => $this->takerFeeRate($integration),
            'estimated_fee' => round($price * $executedQty * $this->takerFeeRate($integration), 8),
            'slippage_pct' => $this->slippagePct(strtolower($side) === 'sell' ? 'sell' : 'buy', $price, $referencePrice),
            'response' => $payload,
        ];
    }

    /**
     * Current exchange position for a symbol. positionAmt == 0 means flat (e.g.
     * the user closed it manually on Binance). Used to reconcile our state.
     *
     * @return array{positionAmt: float, entryPrice: float, markPrice: float, unRealizedProfit: float}
     */
    public function positionFor(Integration $integration, string $symbol): array
    {
        $response = $this->signedRequest($integration, 'GET', '/fapi/v2/positionRisk', [
            'symbol' => strtoupper($symbol),
        ]);
        $row = collect((array) $response->json())->first() ?? [];

        return [
            'positionAmt' => (float) ($row['positionAmt'] ?? 0),
            'entryPrice' => (float) ($row['entryPrice'] ?? 0),
            'markPrice' => (float) ($row['markPrice'] ?? 0),
            'unRealizedProfit' => (float) ($row['unRealizedProfit'] ?? 0),
        ];
    }

    public function closeTimedTrade(
        Integration $integration,
        string $symbol,
        string $entrySide,
        float $quantity,
        string $idempotencyKey
    ): array {
        $closeSide = strtolower($entrySide) === 'sell' ? 'BUY' : 'SELL';
        $normalizedQuantity = $this->normalizeQuantity($integration, $symbol, $quantity);

        if ($normalizedQuantity <= 0) {
            throw new RuntimeException('Close quantity rounded to zero for this Binance symbol.');
        }

        $response = $this->signedRequest($integration, 'POST', '/fapi/v1/order', [
            'symbol' => strtoupper($symbol),
            'side' => $closeSide,
            'type' => 'MARKET',
            'quantity' => $this->formatDecimal($normalizedQuantity),
            'reduceOnly' => 'true',
            'newOrderRespType' => 'RESULT',
            'newClientOrderId' => $this->clientOrderId($idempotencyKey, 'close'),
        ]);

        $payload = $response->json();
        $price = $this->fillPrice($payload);
        $executedQty = (float) ($payload['executedQty'] ?? $normalizedQuantity);
        $referencePrice = (float) ($this->latestQuote($integration, $symbol)['price'] ?? 0);

        if ($price <= 0 || $executedQty <= 0) {
            $fetched = $this->safeFetchOrder($integration, strtoupper($symbol), (string) ($payload['orderId'] ?? ''));
            $payload = array_merge($payload, $fetched);
            $price = $this->fillPrice($payload);
            $executedQty = (float) ($payload['executedQty'] ?? $normalizedQuantity);
        }
        if ($price <= 0) {
            $price = $referencePrice;
        }

        return [
            'provider' => 'binance',
            'market_type' => 'futures',
            'quote_currency' => 'USDT',
            'price' => $price,
            'quantity' => $executedQty,
            'reference_price' => $referencePrice,
            'notional' => round($price * $executedQty, 8),
            'estimated_fee_rate' => $this->takerFeeRate($integration),
            'estimated_fee' => round($price * $executedQty * $this->takerFeeRate($integration), 8),
            'slippage_pct' => $this->slippagePct($closeSide === 'SELL' ? 'sell' : 'buy', $price, $referencePrice),
            'response' => $payload,
        ];
    }

    private function takerFeeRate(Integration $integration): float
    {
        $config = (array) ($integration->config_json ?? []);

        return max(0.0, (float) ($config['futures_taker_fee_rate'] ?? config('trading.binance_futures_taker_fee_rate', 0.0005)));
    }

    private function slippagePct(string $side, float $fillPrice, float $referencePrice): ?float
    {
        if ($fillPrice <= 0 || $referencePrice <= 0) {
            return null;
        }

        $raw = strtolower($side) === 'sell'
            ? (($referencePrice - $fillPrice) / $referencePrice * 100)
            : (($fillPrice - $referencePrice) / $referencePrice * 100);

        return round($raw, 6);
    }

    private function fetchOrder(Integration $integration, string $symbol, string $orderId): array
    {
        if ($orderId === '') {
            return [];
        }

        return $this->signedRequest($integration, 'GET', '/fapi/v1/order', [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
        ])->json();
    }

    /**
     * Best-effort order lookup that never throws — Binance (testnet especially)
     * can return -2013 "Order does not exist" for a few hundred ms right after a
     * market fill, which must not fail an already-confirmed order.
     */
    private function safeFetchOrder(Integration $integration, string $symbol, string $orderId): array
    {
        try {
            return $this->fetchOrder($integration, $symbol, $orderId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve a usable fill price from an order payload, treating "0"/"0.00"
     * (common for MARKET RESULT responses) as absent rather than a real price.
     */
    private function fillPrice(array $payload): float
    {
        foreach (['avgPrice', 'price'] as $key) {
            $value = (float) ($payload[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0.0;
    }

    private function normalizeQuantity(Integration $integration, string $symbol, float $quantity, ?float $priceForError = null): float
    {
        $exchangeInfo = Http::get($this->baseUrl($integration) . '/fapi/v1/exchangeInfo', [
            'symbol' => strtoupper($symbol),
        ]);
        $this->assertSuccess($exchangeInfo, 'Failed to fetch Binance futures exchange info.');

        $symbolRow = collect((array) $exchangeInfo->json('symbols'))->first();
        $filters = collect((array) ($symbolRow['filters'] ?? []))
            ->mapWithKeys(fn (array $filter) => [strtoupper((string) ($filter['filterType'] ?? '')) => $filter]);

        $marketLot = $filters->get('MARKET_LOT_SIZE') ?: $filters->get('LOT_SIZE') ?: [];
        $stepSize = (float) ($marketLot['stepSize'] ?? 0.001);
        $minQty = (float) ($marketLot['minQty'] ?? $stepSize);
        $minNotionalFilter = $filters->get('MIN_NOTIONAL') ?: $filters->get('NOTIONAL') ?: [];
        $minNotional = max(
            FuturesOrderSizingService::BINANCE_MIN_OPEN_NOTIONAL,
            (float) ($minNotionalFilter['notional'] ?? $minNotionalFilter['minNotional'] ?? 0)
        );

        if ($quantity < $minQty) {
            if ($priceForError !== null) {
                $minStake = ceil($minQty * $priceForError * 100) / 100;
                throw new RuntimeException(
                    "Stake too small for {$symbol}. Minimum required: \${$minStake} at current price."
                );
            }

            return 0.0;
        }

        $steps = floor($quantity / $stepSize);
        $normalized = $steps * $stepSize;
        $normalized = round($normalized, 8);

        if ($priceForError !== null && $minNotional > 0 && ($normalized * $priceForError) < $minNotional) {
            $requiredQty = $this->ceilToStep($minNotional / max($priceForError, 0.00000001), $stepSize);
            $minStake = ceil($requiredQty * $priceForError * 100) / 100;

            throw new RuntimeException(
                "Stake too small for {$symbol}. Minimum required: \${$minStake} at current price."
            );
        }

        return $normalized;
    }

    private function ceilToStep(float $quantity, float $stepSize): float
    {
        $stepSize = $stepSize > 0 ? $stepSize : 0.000001;
        if ($quantity <= 0) {
            return 0.0;
        }

        return round(ceil($quantity / $stepSize) * $stepSize, 8);
    }

    private function normalizeInterval(string $timeframe): string
    {
        return match (strtolower(trim($timeframe))) {
            '5m' => '5m',
            '15m' => '15m',
            default => '1m',
        };
    }

    private function clientOrderId(string $idempotencyKey, string $suffix): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_-]/', '', $idempotencyKey) ?: uniqid('qt', true);

        return substr($normalized . '_' . $suffix, 0, 36);
    }

    private function signedRequest(Integration $integration, string $method, string $path, array $params): Response
    {
        $credentials = $integration->secrets_encrypted
            ? (json_decode(Crypt::decryptString($integration->secrets_encrypted), true) ?? [])
            : [];
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $apiSecret = (string) ($credentials['api_secret'] ?? '');

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Binance API credentials are missing.');
        }

        $payload = array_merge($params, [
            'timestamp' => (int) floor(microtime(true) * 1000),
            'recvWindow' => max(5000, min(60000, (int) config('future_trade.binance_rest.recv_window_ms', 15000))),
        ]);
        ksort($payload);

        $query = http_build_query($payload, '', '&');
        $payload['signature'] = hash_hmac('sha256', $query, $apiSecret);

        $response = match (strtoupper($method)) {
            'GET' => Http::withHeaders(['X-MBX-APIKEY' => $apiKey])->get($this->baseUrl($integration) . $path, $payload),
            default => Http::withHeaders(['X-MBX-APIKEY' => $apiKey])->asForm()->post($this->baseUrl($integration) . $path, $payload),
        };

        $this->assertSuccess($response, 'Binance futures request failed.');

        return $response;
    }

    private function baseUrl(?Integration $integration): string
    {
        $config = (array) ($integration?->config_json ?? []);
        $environment = strtolower((string) ($config['environment'] ?? 'live'));

        return $environment === 'testnet'
            ? (string) config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
            : (string) config('services.binance.futures_api_url', 'https://fapi.binance.com');
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
}
