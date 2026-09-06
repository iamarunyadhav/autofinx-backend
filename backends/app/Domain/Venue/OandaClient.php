<?php

namespace App\Domain\Venue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Forex/metals broker client for OANDA's real, documented v20 REST API
 * (https://developer.oanda.com/rest-live-v20/introduction/) -- built without
 * a live account (none was available when this was written), so the live
 * (non-sandbox) request paths follow the real documented request/response
 * shapes but have NEVER been exercised against a real OANDA account. Treat
 * the live path as unverified until it has been run once against a real
 * practice account; the sandbox path (default, and what every test in this
 * repo exercises) needs no credentials and is fully self-contained.
 *
 * Key real-API facts this class relies on (verified against OANDA's own
 * public docs, not guessed):
 *   - Auth: a single bearer token, `Authorization: Bearer {token}` -- no
 *     separate HMAC secret the way Binance/MetalBrokerClient use.
 *   - Instruments use an underscore-separated BASE_QUOTE format (`EUR_USD`,
 *     `XAU_USD`), never the bare `EURUSD` this repo's own config/assets.php
 *     and RatioBotConfig.symbol use -- translated at this client's boundary
 *     only, so every other layer keeps dealing in the existing bare format.
 *   - Orders express direction via a SIGNED `units` field (positive=buy,
 *     negative=sell), not a separate `side` field -- translated here too.
 *   - Live price streaming is a long-lived chunked HTTP response on a
 *     DIFFERENT host (`stream-fxpractice.oanda.com`), not a WebSocket --
 *     deliberately NOT built in this pass (would need its own persistent-
 *     connection consumer command + supervisor entry, a production-ops
 *     component out of scope without a real account to run it against).
 *     `getLatestPrice()` here uses the ordinary REST pricing endpoint
 *     instead, meant to be polled periodically (matches this codebase's own
 *     existing reconcile-on-a-schedule pattern elsewhere).
 */
class OandaClient implements TradingVenueClientInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $accountId,
        private readonly string $mode = 'sandbox',
    ) {
    }

    public function provider(): string
    {
        return 'oanda';
    }

    public function getLatestPrice(string $assetCode): array
    {
        $assetCode = strtoupper($assetCode);
        $instrument = $this->toInstrument($assetCode);

        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return $this->sandboxQuote($assetCode, $instrument);
        }

        $response = $this->request('GET', "/v3/accounts/{$this->accountId}/pricing", [
            'instruments' => $instrument,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('OANDA pricing request failed: ' . $response->body());
        }

        $payload = $response->json();
        $price = (array) (($payload['prices'] ?? [])[0] ?? []);
        $bid = (float) (($price['bids'][0]['price'] ?? null) ?? 0);
        $ask = (float) (($price['asks'][0]['price'] ?? null) ?? 0);
        $mid = $bid > 0 && $ask > 0 ? ($bid + $ask) / 2 : 0.0;

        return [
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'spread_pct' => $mid > 0 ? (($ask - $bid) / $mid) * 100 : 0.0,
            'raw' => $payload,
        ];
    }

    public function placeOrder(array $orderRequest): array
    {
        $assetCode = strtoupper((string) ($orderRequest['asset_code'] ?? ''));
        $side = strtoupper((string) ($orderRequest['side'] ?? 'BUY'));
        $qty = (float) ($orderRequest['qty'] ?? 0);
        if ($assetCode === '' || $qty <= 0) {
            throw new RuntimeException('Invalid OANDA order request');
        }
        $instrument = $this->toInstrument($assetCode);

        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $quote = $this->sandboxQuote($assetCode, $instrument);
            $mid = (float) $quote['mid'];
            $type = strtoupper((string) ($orderRequest['type'] ?? 'MARKET'));
            $price = (float) ($orderRequest['price'] ?? ($side === 'BUY' ? $quote['ask'] : $quote['bid']));
            $orderId = (string) Str::uuid();
            $payload = [
                'order_id' => $orderId,
                'status' => 'FILLED',
                'asset_code' => $assetCode,
                'provider' => $this->provider(),
                'side' => $side,
                'type' => $type,
                'executed_qty' => $qty,
                'orig_qty' => $qty,
                'avg_price' => $price > 0 ? $price : $mid,
                'created_at_ms' => now()->getTimestampMs(),
                'fills' => $this->sandboxFills($orderId, $price > 0 ? $price : $mid, $qty),
            ];
            Cache::put($this->sandboxOrderKey($orderId), $payload, now()->addHours(8));

            return array_merge($payload, ['raw' => $payload]);
        }

        // Real OANDA v20 order-creation shape: signed `units` (positive=buy,
        // negative=sell), instrument in underscore format, order nested
        // under an `order` key.
        $units = $side === 'BUY' ? $qty : -$qty;
        $response = $this->request('POST', "/v3/accounts/{$this->accountId}/orders", [
            'order' => [
                'type' => strtoupper((string) ($orderRequest['type'] ?? 'MARKET')),
                'instrument' => $instrument,
                'units' => number_format($units, 0, '.', ''),
                'timeInForce' => strtoupper((string) ($orderRequest['tif'] ?? 'FOK')),
                'positionFill' => 'DEFAULT',
            ],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('OANDA order placement failed: ' . $response->body());
        }

        $payload = $response->json();
        $fillTxn = (array) ($payload['orderFillTransaction'] ?? []);
        $filled = $fillTxn !== [];

        return [
            'order_id' => (string) ($fillTxn['id'] ?? $payload['orderCreateTransaction']['id'] ?? ''),
            'status' => $filled ? 'FILLED' : 'NEW',
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'executed_qty' => $filled ? abs((float) ($fillTxn['units'] ?? 0)) : 0.0,
            'orig_qty' => $qty,
            'avg_price' => (float) ($fillTxn['price'] ?? 0),
            'fills' => $filled ? [$fillTxn] : [],
            'raw' => $payload,
        ];
    }

    public function cancelOrder(string $orderId, ?string $assetCode = null): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $order = Cache::get($this->sandboxOrderKey($orderId), []);
            $order['status'] = 'CANCELLED';
            Cache::put($this->sandboxOrderKey($orderId), $order, now()->addHours(8));

            return $order;
        }

        // Real OANDA API: cancelling a pending order is a PUT, not a DELETE.
        $response = $this->request('PUT', "/v3/accounts/{$this->accountId}/orders/{$orderId}/cancel");
        if (! $response->successful()) {
            throw new RuntimeException('OANDA cancel failed: ' . $response->body());
        }

        return $response->json();
    }

    public function fetchOrder(string $orderId, ?string $assetCode = null): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $order = Cache::get($this->sandboxOrderKey($orderId));
            if (! $order) {
                throw new RuntimeException('Sandbox OANDA order not found: ' . $orderId);
            }

            return array_merge($order, ['raw' => $order]);
        }

        $response = $this->request('GET', "/v3/accounts/{$this->accountId}/orders/{$orderId}");
        if (! $response->successful()) {
            throw new RuntimeException('OANDA fetch order failed: ' . $response->body());
        }
        $payload = $response->json();
        $order = (array) ($payload['order'] ?? []);

        return [
            'order_id' => (string) ($order['id'] ?? $orderId),
            'status' => strtoupper((string) ($order['state'] ?? 'PENDING')),
            'asset_code' => strtoupper((string) ($assetCode ?? '')),
            'provider' => $this->provider(),
            'executed_qty' => 0.0,
            'orig_qty' => abs((float) ($order['units'] ?? 0)),
            'avg_price' => (float) ($order['price'] ?? 0),
            'fills' => [],
            'raw' => $payload,
        ];
    }

    public function fetchBalances(): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return [
                ['asset' => 'USD', 'free' => 100000, 'locked' => 0],
            ];
        }

        $response = $this->request('GET', "/v3/accounts/{$this->accountId}/summary");
        if (! $response->successful()) {
            throw new RuntimeException('OANDA account summary failed: ' . $response->body());
        }

        $account = (array) ($response->json('account') ?? []);
        $balance = (float) ($account['balance'] ?? 0);
        $marginUsed = (float) ($account['marginUsed'] ?? 0);

        // OANDA is a single-currency margin account (its home currency,
        // typically USD) -- no per-asset free/locked split the way a spot
        // exchange has; marginUsed is the closest analogue to "locked".
        return [
            ['asset' => (string) ($account['currency'] ?? 'USD'), 'free' => max(0.0, $balance - $marginUsed), 'locked' => $marginUsed],
        ];
    }

    public function supportsOrderType(string $type): bool
    {
        return in_array(strtoupper($type), ['MARKET', 'LIMIT'], true);
    }

    public function supportsTimeInForce(string $tif): bool
    {
        return in_array(strtoupper($tif), ['GTC', 'FOK', 'IOC'], true);
    }

    /**
     * `EURUSD`/`XAUUSD` (this repo's bare format, matching config/assets.php)
     * -> `EUR_USD`/`XAU_USD` (OANDA's real instrument format). Only ever a
     * plain 3+3 split -- every symbol config/assets.php defines for forex/
     * metals is exactly 6 characters.
     */
    public function toInstrument(string $assetCode): string
    {
        $assetCode = strtoupper(trim($assetCode));
        if (str_contains($assetCode, '_')) {
            return $assetCode;
        }

        if (strlen($assetCode) === 6) {
            return substr($assetCode, 0, 3) . '_' . substr($assetCode, 3);
        }

        return $assetCode;
    }

    private function request(string $method, string $path, array $query = [])
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($path, '/');
        $headers = array_filter([
            'Authorization' => $this->apiKey !== '' ? 'Bearer ' . $this->apiKey : null,
            'Accept-Datetime-Format' => 'RFC3339',
            'Content-Type' => 'application/json',
        ]);

        return match (strtoupper($method)) {
            'GET' => Http::withHeaders($headers)->get($url, $query),
            'PUT' => Http::withHeaders($headers)->put($url, $query),
            'DELETE' => Http::withHeaders($headers)->delete($url, $query),
            default => Http::withHeaders($headers)->post($url, $query),
        };
    }

    private function sandboxQuote(string $assetCode, string $instrument): array
    {
        $baseMap = [
            'EUR_USD' => 1.08,
            'GBP_USD' => 1.27,
            'USD_JPY' => 149.5,
            'XAU_USD' => 2150.0,
            'XAG_USD' => 24.5,
        ];
        $base = $baseMap[$instrument] ?? 1.0;
        $jitter = mt_rand(-20, 20) / 10000;
        $mid = $base * (1 + $jitter);
        $spreadPct = 0.02;
        $halfSpread = ($mid * $spreadPct / 100) / 2;

        return [
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'bid' => $mid - $halfSpread,
            'ask' => $mid + $halfSpread,
            'mid' => $mid,
            'spread_pct' => $spreadPct,
            'raw' => ['sandbox' => true, 'instrument' => $instrument, 'base_price' => $base],
        ];
    }

    private function sandboxOrderKey(string $orderId): string
    {
        return "oanda:sandbox:order:{$orderId}";
    }

    private function sandboxFills(string $orderId, float $price, float $qty): array
    {
        return [[
            'id' => 'sandbox-' . substr($orderId, 0, 8),
            'price' => (string) $price,
            'units' => (string) $qty,
            'time' => now()->toIso8601String(),
        ]];
    }
}
