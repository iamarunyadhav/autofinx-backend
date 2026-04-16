<?php

namespace App\Domain\Market;

use App\Models\MarketPriceCache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MarketPriceCacheService
{
    public function setLatestPrice(string $exchange, string $symbol, float $price, ?int $tsMs = null): void
    {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $tsMs = $tsMs ?? (int) floor(microtime(true) * 1000);

        $priceKey = $this->priceKey($exchange, $symbol);
        $tsKey = $this->tsKey($exchange, $symbol);

        try {
            Redis::set($priceKey, (string) $price);
            Redis::set($tsKey, (string) $tsMs);
        } catch (\Throwable) {
            // Keep DB cache as fallback even if Redis is unavailable.
        }

        MarketPriceCache::updateOrCreate(
            ['exchange' => $exchange, 'symbol' => $symbol],
            [
                'id' => MarketPriceCache::query()->where('exchange', $exchange)->where('symbol', $symbol)->value('id') ?: (string) Str::uuid(),
                'last_price' => $price,
                'last_ts' => now(),
            ]
        );
    }

    public function getLatestPrice(string $exchange, string $symbol): ?array
    {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);

        $priceKey = $this->priceKey($exchange, $symbol);
        $tsKey = $this->tsKey($exchange, $symbol);

        try {
            $price = Redis::get($priceKey);
            $ts = Redis::get($tsKey);
            if ($price !== null) {
                return [
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'price' => (float) $price,
                    'timestamp_ms' => $ts !== null ? (int) $ts : null,
                    'source' => 'redis',
                ];
            }
        } catch (\Throwable) {
            // fall back to DB cache
        }

        $row = MarketPriceCache::where('exchange', $exchange)->where('symbol', $symbol)->first();
        if (! $row) {
            return null;
        }

        return [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'price' => (float) $row->last_price,
            'timestamp_ms' => $row->last_ts ? $row->last_ts->getTimestampMs() : null,
            'source' => 'db-cache',
        ];
    }

    private function priceKey(string $exchange, string $symbol): string
    {
        return "price:{$exchange}:{$symbol}";
    }

    private function tsKey(string $exchange, string $symbol): string
    {
        return "ts:{$exchange}:{$symbol}";
    }
}
