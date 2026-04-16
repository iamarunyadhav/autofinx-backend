<?php

namespace App\Domain\Venue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MetalBrokerClient implements TradingVenueClientInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $mode = 'sandbox',
        private readonly bool $supportsIoc = false,
        private readonly string $orderPath = '/v1/orders',
        private readonly string $statusPath = '/v1/orders/{order_id}',
        private readonly string $balancesPath = '/v1/account/balances',
        private readonly string $pricePath = '/v1/quotes/{asset_code}',
        private readonly float $defaultSpreadPct = 0.4,
    ) {
    }

    public function provider(): string
    {
        return 'metal_broker';
    }

    public function getLatestPrice(string $assetCode): array
    {
        $assetCode = strtoupper($assetCode);
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return $this->sandboxQuote($assetCode);
        }

        $path = str_replace('{asset_code}', urlencode($assetCode), $this->pricePath);
        $response = $this->request('GET', $path);
        if (! $response->successful()) {
            throw new RuntimeException('Metal broker quote request failed: ' . $response->body());
        }

        $payload = $response->json();
        $bid = (float) ($payload['bid'] ?? $payload['data']['bid'] ?? 0);
        $ask = (float) ($payload['ask'] ?? $payload['data']['ask'] ?? 0);
        $mid = (float) ($payload['mid'] ?? (($bid > 0 && $ask > 0) ? (($bid + $ask) / 2) : 0));
        if ($mid <= 0) {
            $mid = (float) ($payload['price'] ?? $payload['data']['price'] ?? 0);
            $bid = $bid > 0 ? $bid : $mid;
            $ask = $ask > 0 ? $ask : $mid;
        }

        return [
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'spread_pct' => $mid > 0 ? (($ask - $bid) / $mid) * 100 : $this->defaultSpreadPct,
            'raw' => $payload,
        ];
    }

    public function placeOrder(array $orderRequest): array
    {
        $assetCode = strtoupper((string) ($orderRequest['asset_code'] ?? ''));
        $side = strtoupper((string) ($orderRequest['side'] ?? 'BUY'));
        $qty = (float) ($orderRequest['qty'] ?? 0);
        if ($assetCode === '' || $qty <= 0) {
            throw new RuntimeException('Invalid metal broker order request');
        }

        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $quote = $this->sandboxQuote($assetCode);
            $mid = (float) $quote['mid'];
            $type = strtoupper((string) ($orderRequest['type'] ?? 'LIMIT'));
            $tif = strtoupper((string) ($orderRequest['tif'] ?? 'GTC'));
            $fillTimeoutMs = max(250, (int) ($orderRequest['fill_timeout_ms'] ?? 1500));
            $price = (float) ($orderRequest['price'] ?? ($side === 'BUY' ? $quote['ask'] : $quote['bid']));
            $status = ($type === 'LIMIT' && $tif === 'IOC') ? 'FILLED' : 'NEW';
            $orderId = (string) Str::uuid();
            $payload = [
                'order_id' => $orderId,
                'status' => $status,
                'asset_code' => $assetCode,
                'provider' => $this->provider(),
                'side' => $side,
                'type' => $type,
                'tif' => $tif,
                'executed_qty' => $status === 'FILLED' ? $qty : 0,
                'orig_qty' => $qty,
                'avg_price' => $price > 0 ? $price : $mid,
                'fill_timeout_ms' => $fillTimeoutMs,
                'created_at_ms' => now()->getTimestampMs(),
                'fills' => $status === 'FILLED'
                    ? $this->sandboxFills($orderId, $price > 0 ? $price : $mid, $qty)
                    : [],
            ];
            Cache::put($this->sandboxOrderKey($orderId), $payload, now()->addHours(8));

            return array_merge($payload, ['raw' => $payload]);
        }

        $response = $this->request('POST', $this->orderPath, $orderRequest);
        if (! $response->successful()) {
            throw new RuntimeException('Metal broker order placement failed: ' . $response->body());
        }

        $payload = $response->json();

        return [
            'order_id' => (string) ($payload['order_id'] ?? $payload['id'] ?? ''),
            'status' => strtoupper((string) ($payload['status'] ?? 'NEW')),
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'executed_qty' => (float) ($payload['executed_qty'] ?? 0),
            'orig_qty' => (float) ($payload['orig_qty'] ?? $qty),
            'avg_price' => (float) ($payload['avg_price'] ?? 0),
            'fills' => (array) ($payload['fills'] ?? []),
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

        $response = $this->request('DELETE', $this->orderPath . '/' . urlencode($orderId), [
            'asset_code' => $assetCode,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Metal broker cancel failed: ' . $response->body());
        }

        return $response->json();
    }

    public function fetchOrder(string $orderId, ?string $assetCode = null): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $order = Cache::get($this->sandboxOrderKey($orderId));
            if (! $order) {
                throw new RuntimeException('Sandbox metal order not found: ' . $orderId);
            }

            $status = strtoupper((string) ($order['status'] ?? 'NEW'));
            $type = strtoupper((string) ($order['type'] ?? 'LIMIT'));
            $tif = strtoupper((string) ($order['tif'] ?? 'GTC'));
            if ($status === 'NEW' && $type === 'LIMIT' && $tif !== 'IOC') {
                $symbol = strtoupper((string) ($order['asset_code'] ?? $assetCode ?? ''));
                $quote = $this->sandboxQuote($symbol);
                $side = strtoupper((string) ($order['side'] ?? 'BUY'));
                $price = (float) ($order['avg_price'] ?? 0);
                $qty = (float) ($order['orig_qty'] ?? 0);
                $elapsedMs = now()->getTimestampMs() - (int) ($order['created_at_ms'] ?? now()->getTimestampMs());
                $timeoutMs = max(250, (int) ($order['fill_timeout_ms'] ?? 1500));

                $shouldFill = $side === 'BUY'
                    ? $price >= (float) ($quote['ask'] ?? $price)
                    : $price <= (float) ($quote['bid'] ?? $price);

                if ($shouldFill) {
                    $order['status'] = 'FILLED';
                    $order['executed_qty'] = $qty;
                    $order['fills'] = $this->sandboxFills($orderId, $price, $qty);
                } elseif ($elapsedMs >= max(0, $timeoutMs - 250)) {
                    // Sandbox synthetic IOC fallback: assume requote execution at limit on timeout.
                    $fillPrice = $price > 0 ? $price : (float) ($quote['mid'] ?? 0);
                    $order['status'] = 'FILLED';
                    $order['executed_qty'] = $qty;
                    $order['avg_price'] = $fillPrice;
                    $order['fills'] = $this->sandboxFills($orderId, $fillPrice, $qty);
                }

                Cache::put($this->sandboxOrderKey($orderId), $order, now()->addHours(8));
            }

            return array_merge($order, ['raw' => $order]);
        }

        $path = str_replace('{order_id}', urlencode($orderId), $this->statusPath);
        $response = $this->request('GET', $path, array_filter([
            'asset_code' => $assetCode,
        ]));
        if (! $response->successful()) {
            throw new RuntimeException('Metal broker fetch order failed: ' . $response->body());
        }
        $payload = $response->json();

        return [
            'order_id' => (string) ($payload['order_id'] ?? $payload['id'] ?? $orderId),
            'status' => strtoupper((string) ($payload['status'] ?? 'NEW')),
            'asset_code' => strtoupper((string) ($payload['asset_code'] ?? $assetCode ?? '')),
            'provider' => $this->provider(),
            'executed_qty' => (float) ($payload['executed_qty'] ?? 0),
            'orig_qty' => (float) ($payload['orig_qty'] ?? 0),
            'avg_price' => (float) ($payload['avg_price'] ?? 0),
            'fills' => (array) ($payload['fills'] ?? []),
            'raw' => $payload,
        ];
    }

    public function fetchBalances(): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return [
                ['asset' => 'USD', 'free' => 50000, 'locked' => 0],
                ['asset' => 'XAU', 'free' => 0, 'locked' => 0],
                ['asset' => 'XAG', 'free' => 0, 'locked' => 0],
            ];
        }

        $response = $this->request('GET', $this->balancesPath);
        if (! $response->successful()) {
            throw new RuntimeException('Metal broker balances failed: ' . $response->body());
        }

        return (array) ($response->json('balances') ?? $response->json());
    }

    public function supportsOrderType(string $type): bool
    {
        return in_array(strtoupper($type), ['MARKET', 'LIMIT'], true);
    }

    public function supportsTimeInForce(string $tif): bool
    {
        if (strtoupper($tif) === 'IOC') {
            return $this->supportsIoc;
        }

        return in_array(strtoupper($tif), ['GTC', 'FOK', 'IOC'], true);
    }

    private function request(string $method, string $path, array $payload = [])
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($path, '/');
        $headers = array_filter([
            'Authorization' => $this->apiKey !== '' ? 'Bearer ' . $this->apiKey : null,
            'X-API-SECRET' => $this->apiSecret !== '' ? $this->apiSecret : null,
            'Accept' => 'application/json',
        ]);

        return match (strtoupper($method)) {
            'GET' => Http::withHeaders($headers)->get($url, $payload),
            'DELETE' => Http::withHeaders($headers)->delete($url, $payload),
            default => Http::withHeaders($headers)->post($url, $payload),
        };
    }

    private function sandboxQuote(string $assetCode): array
    {
        $baseMap = [
            'XAUUSD' => 2150.0,
            'XAGUSD' => 24.5,
            'XCUUSD' => 4.2,
        ];
        $base = $baseMap[$assetCode] ?? 100.0;
        $jitter = mt_rand(-50, 50) / 1000;
        $mid = $base * (1 + $jitter / 100);
        $spreadPct = max(0.05, $this->defaultSpreadPct);
        $halfSpread = ($mid * $spreadPct / 100) / 2;

        return [
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'bid' => $mid - $halfSpread,
            'ask' => $mid + $halfSpread,
            'mid' => $mid,
            'spread_pct' => $spreadPct,
            'raw' => [
                'sandbox' => true,
                'base_price' => $base,
            ],
        ];
    }

    private function sandboxOrderKey(string $orderId): string
    {
        return "metal-broker:sandbox:order:{$orderId}";
    }

    private function sandboxFills(string $orderId, float $price, float $qty): array
    {
        return [[
            'tradeId' => 'sandbox-' . substr($orderId, 0, 8),
            'price' => $price,
            'qty' => $qty,
            'commission' => $price * $qty * 0.002,
            'commissionAsset' => 'USD',
            'time' => now()->getTimestampMs(),
        ]];
    }
}
