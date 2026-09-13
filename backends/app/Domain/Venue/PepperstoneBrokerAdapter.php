<?php

namespace App\Domain\Venue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pepperstone Metals & Energy (Gold/Silver/Platinum/Crude Oil CFDs) broker
 * adapter -- SANDBOX MODE ONLY for now.
 *
 * Pepperstone's real connection method (cTrader OpenAPI vs an MT5 bridge)
 * is genuinely unknown until a real Pepperstone demo/live account exists
 * and its actual offered connection method is confirmed (Phase 0). This
 * class deliberately does NOT guess or implement either real protocol: the
 * non-sandbox branch of every method below throws a clear, explicit
 * RuntimeException instead of a silent no-op or a fabricated live call, so
 * nothing downstream can mistake an unimplemented path for a working one.
 *
 * The sandbox path mirrors App\Domain\Venue\OandaClient's sandbox pattern
 * exactly (fabricated quotes with jitter around a per-instrument base
 * price, cache-backed fake order lifecycle) -- this is the proven template
 * this task was told to copy, not a new invention.
 */
class PepperstoneBrokerAdapter implements TradingVenueClientInterface, SupportsHistoricalCandles
{
    /** Minutes-per-candle for sandbox synthetic candle synthesis. */
    private const TIMEFRAME_MINUTES = [
        '1m' => 1, '5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '4h' => 240, '1d' => 1440,
    ];

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $accountId,
        private readonly string $mode = 'sandbox',
    ) {
    }

    public function provider(): string
    {
        return 'pepperstone';
    }

    public function getLatestPrice(string $assetCode): array
    {
        $assetCode = strtoupper(trim($assetCode));

        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return $this->sandboxQuote($assetCode);
        }

        throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
    }

    public function placeOrder(array $orderRequest): array
    {
        $assetCode = strtoupper((string) ($orderRequest['asset_code'] ?? ''));
        $side = strtoupper((string) ($orderRequest['side'] ?? 'BUY'));
        $qty = (float) ($orderRequest['qty'] ?? 0);
        if ($assetCode === '' || $qty <= 0) {
            throw new RuntimeException('Invalid Pepperstone order request');
        }

        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $quote = $this->sandboxQuote($assetCode);
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

        throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
    }

    public function cancelOrder(string $orderId, ?string $assetCode = null): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $order = Cache::get($this->sandboxOrderKey($orderId), []);
            $order['status'] = 'CANCELLED';
            Cache::put($this->sandboxOrderKey($orderId), $order, now()->addHours(8));

            return $order;
        }

        throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
    }

    public function fetchOrder(string $orderId, ?string $assetCode = null): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            $order = Cache::get($this->sandboxOrderKey($orderId));
            if (! $order) {
                throw new RuntimeException('Sandbox Pepperstone order not found: ' . $orderId);
            }

            return array_merge($order, ['raw' => $order]);
        }

        throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
    }

    public function fetchBalances(): array
    {
        if ($this->mode === 'sandbox' || trim($this->apiUrl) === '') {
            return [
                ['asset' => 'USD', 'free' => 100000, 'locked' => 0],
            ];
        }

        throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
    }

    /**
     * Sandbox: synthetic OHLC derived deterministically from the same
     * base-price map getLatestPrice() jitters around -- NOT mt_rand-based
     * (which is not reproducible run-to-run), so unit tests can assert
     * exact values. Live: throws the same established
     * "not yet implemented" exception as every other method here.
     */
    public function fetchCandles(string $assetCode, string $timeframe, int $limit): array
    {
        $assetCode = strtoupper(trim($assetCode));

        if ($this->mode !== 'sandbox' && trim($this->apiUrl) !== '') {
            throw new RuntimeException('Pepperstone live connection not yet implemented — Phase 0 pending a real account');
        }

        $baseMap = [
            'XAUUSD' => 2150.0,
            'XAGUSD' => 24.5,
            'XPTUSD' => 950.0,
            'WTICOUSD' => 78.0,
        ];
        $base = $baseMap[$assetCode] ?? 100.0;
        $minutesPerCandle = self::TIMEFRAME_MINUTES[strtolower(trim($timeframe))] ?? 60;
        $limit = max(1, min(5000, $limit));

        $candles = [];
        $previousClose = $base;
        $now = now();

        for ($i = $limit - 1; $i >= 0; $i--) {
            // Deterministic pseudo-oscillation + deterministic "noise" from
            // a stable hash of (assetCode, timeframe, index) -- reproducible
            // across runs, unlike mt_rand(), so this is unit-testable.
            $index = $limit - 1 - $i;
            $oscillation = sin($index / 10) * 0.004; // +/-0.4% smooth wave
            $noiseSeed = crc32("{$assetCode}:{$timeframe}:{$index}");
            $noise = ((($noiseSeed % 2001) - 1000) / 1000) * 0.002; // +/-0.2%

            $open = $previousClose;
            $close = $base * (1 + $oscillation + $noise);
            $high = max($open, $close) * 1.0006;
            $low = min($open, $close) * 0.9994;

            $candles[] = [
                'ts' => $now->clone()->subMinutes($minutesPerCandle * ($i + 1))->toIso8601String(),
                'open' => round($open, 6),
                'high' => round($high, 6),
                'low' => round($low, 6),
                'close' => round($close, 6),
                'volume' => 0.0,
            ];

            $previousClose = $close;
        }

        return $candles;
    }

    public function supportsOrderType(string $type): bool
    {
        return in_array(strtoupper($type), ['MARKET', 'LIMIT'], true);
    }

    public function supportsTimeInForce(string $tif): bool
    {
        return in_array(strtoupper($tif), ['GTC', 'FOK', 'IOC'], true);
    }

    private function sandboxQuote(string $assetCode): array
    {
        $baseMap = [
            'XAUUSD' => 2150.0,
            'XAGUSD' => 24.5,
            'XPTUSD' => 950.0,
            'WTICOUSD' => 78.0,
        ];
        $base = $baseMap[$assetCode] ?? 100.0;
        $jitter = mt_rand(-20, 20) / 10000;
        $mid = $base * (1 + $jitter);
        $spreadPct = 0.04;
        $halfSpread = ($mid * $spreadPct / 100) / 2;

        return [
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'bid' => $mid - $halfSpread,
            'ask' => $mid + $halfSpread,
            'mid' => $mid,
            'spread_pct' => $spreadPct,
            'raw' => ['sandbox' => true, 'asset_code' => $assetCode, 'base_price' => $base],
        ];
    }

    private function sandboxOrderKey(string $orderId): string
    {
        return "pepperstone:sandbox:order:{$orderId}";
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
