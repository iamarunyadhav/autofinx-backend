<?php

namespace App\Domain\Trading\Providers;

use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\Integration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'timeframes' => ['1m', '5m', '15m', '1h', '4h', '1d'],
            'durations' => [60, 300, 900, 3600, 14400, 86400],
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

    /**
     * The REAL commission Binance actually charged for an order, summed across
     * any partial fills, as reported by the exchange itself — as opposed to
     * `estimated_fee`/`takerFeeRate()` above, which are a static assumption
     * baked in at order time and never reconciled against what Binance
     * actually took.
     *
     * Verified against Binance's public USDS-M Futures API docs
     * (`GET /fapi/v1/userTrades`, signed, `symbol` + `orderId`): each row
     * returned for a filled order includes `commission` (the fee actually
     * charged for that fill) and `commissionAsset` (the asset it was charged
     * in). On USDS-M futures, commission is charged in the position's margin
     * asset (USDT for every symbol this platform trades) by default — unlike
     * Binance Spot, futures does not offer a BNB fee-discount mechanism that
     * would put a different asset in `commissionAsset` for a normal account.
     * If a fill nonetheless reports a `commissionAsset` other than
     * `$expectedQuoteAsset`, this codebase has no price-conversion service to
     * reuse (confirmed via grep before writing this), so rather than invent
     * one or guess a conversion rate, this method treats that as an
     * unreliable read and returns null — the caller then falls back to the
     * existing fee estimate exactly as it did before this method existed.
     *
     * NEVER throws: any network error, empty/unexpected response, or mixed
     * commission asset returns null so a live settlement can always fall
     * back to the estimate rather than fail or stall.
     *
     * @return array{commission: float, commission_asset: string, realized_pnl: float, fills: int}|null
     */
    public function realFeeForOrder(
        Integration $integration,
        string $symbol,
        string $orderId,
        string $expectedQuoteAsset = 'USDT'
    ): ?array {
        $orderId = trim($orderId);
        if ($orderId === '' || $orderId === '0') {
            return null;
        }

        try {
            $response = $this->signedRequest($integration, 'GET', '/fapi/v1/userTrades', [
                'symbol' => strtoupper($symbol),
                'orderId' => $orderId,
            ]);
            $rows = collect((array) $response->json());
        } catch (\Throwable $e) {
            Log::warning('binance_futures_real_fee_lookup_failed', [
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
            if (! is_array($row)) {
                return null;
            }

            $asset = strtoupper((string) ($row['commissionAsset'] ?? ''));
            if ($asset !== '' && $asset !== $expectedQuoteAsset) {
                Log::warning('binance_futures_real_fee_unexpected_asset', [
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
     * a position's open-to-close window — as opposed to the existing
     * settlement path, which has no funding-fee term at all today. Funding
     * can be positive (credited to the account) or negative (charged), and
     * settles every 8 hours (00:00/08:00/16:00 UTC on Binance); any of this
     * platform's 1h/4h/24h Quick Trade durations can span one of those
     * boundaries.
     *
     * Verified against Binance's public USDS-M Futures API docs
     * (`GET /fapi/v1/income`, signed, `symbol` + `incomeType=FUNDING_FEE` +
     * `startTime`/`endTime`): each row has an `income` field (signed, in the
     * symbol's margin asset) for one funding settlement event.
     *
     * Returns null — not 0.0 — on any failure (network error, unexpected
     * response shape) specifically so the caller can distinguish "looked it
     * up, there was genuinely no funding fee in this window" (0.0) from
     * "couldn't look it up" (null), which this platform's `fee_source`
     * auditability column depends on. A caller that only wants a safe
     * numeric contribution to a P&L sum should treat null as 0.0. Never
     * throws; a failure is logged as a warning so a missed funding fee is at
     * least visible, not silently swallowed without a trace.
     */
    public function realFundingFee(
        Integration $integration,
        string $symbol,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): ?float {
        try {
            $response = $this->signedRequest($integration, 'GET', '/fapi/v1/income', [
                'symbol' => strtoupper($symbol),
                'incomeType' => 'FUNDING_FEE',
                'startTime' => (int) floor($from->getTimestamp() * 1000),
                'endTime' => (int) floor($to->getTimestamp() * 1000),
                'limit' => 1000,
            ]);
            $rows = collect((array) $response->json());
        } catch (\Throwable $e) {
            Log::warning('binance_futures_real_funding_fee_lookup_failed', [
                'symbol' => $symbol,
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return round($rows->sum(fn ($row) => is_array($row) ? (float) ($row['income'] ?? 0) : 0.0), 8);
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
            '1h' => '1h',
            '4h' => '4h',
            '1d' => '1d',
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
