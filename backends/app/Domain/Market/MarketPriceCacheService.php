<?php

namespace App\Domain\Market;

use App\Models\MarketPriceCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MarketPriceCacheService
{
    /**
     * Throttle MarketPriceCache DB writes per exchange:symbol.
     * Redis is always written immediately; DB is only written every 30 s.
     * This eliminates 30-50 spurious DB writes/minute from the live-feed trade stream.
     */
    private static array $dbWriteLastAt = [];
    private const DB_WRITE_INTERVAL_S = 30;
    private const MAX_FUTURE_SKEW_MS = 30000;
    private const MAX_BACKWARD_SKEW_MS = 1000;
    private const MAX_FRESH_JUMP_PERCENT = 5.0;
    private const FRESH_JUMP_WINDOW_MS = 5000;

    public function setLatestPrice(string $exchange, string $symbol, float $price, ?int $tsMs = null): void
    {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $tsMs = $tsMs ?? (int) floor(microtime(true) * 1000);
        $nowMs = (int) floor(microtime(true) * 1000);

        if ($price <= 0 || $tsMs > ($nowMs + self::MAX_FUTURE_SKEW_MS)) {
            return;
        }

        $priceKey = $this->priceKey($exchange, $symbol);
        $tsKey = $this->tsKey($exchange, $symbol);

        $redisOk = false;
        try {
            $currentTs = (int) (Redis::get($tsKey) ?? 0);
            $currentPrice = (float) (Redis::get($priceKey) ?? 0);

            if ($currentTs > 0 && $tsMs < ($currentTs - self::MAX_BACKWARD_SKEW_MS)) {
                return;
            }

            if (
                $currentTs > 0
                && $currentPrice > 0
                && abs($tsMs - $currentTs) <= self::FRESH_JUMP_WINDOW_MS
                && abs(($price - $currentPrice) / $currentPrice) * 100 > self::MAX_FRESH_JUMP_PERCENT
            ) {
                return;
            }

            Redis::set($priceKey, (string) $price);
            Redis::set($tsKey, (string) $tsMs);
            $redisOk = true;
        } catch (\Throwable) {
            // Redis unavailable — fall through to unconditional DB write below.
        }

        // Only hit the DB when Redis is unavailable (fallback) or the per-symbol
        // throttle window has expired.  During normal operation the trade stream
        // for 4 symbols fires 2-5 times/second; without throttling that equals
        // ~600-900 MarketPriceCache DB writes/minute — unnecessary given Redis.
        $throttleKey = "{$exchange}:{$symbol}";
        $now = time();
        $lastWrite = self::$dbWriteLastAt[$throttleKey] ?? 0;

        if (! $redisOk || ($now - $lastWrite) >= self::DB_WRITE_INTERVAL_S) {
            self::$dbWriteLastAt[$throttleKey] = $now;
            MarketPriceCache::updateOrCreate(
                ['exchange' => $exchange, 'symbol' => $symbol],
                [
                    'id' => MarketPriceCache::query()->where('exchange', $exchange)->where('symbol', $symbol)->value('id') ?: (string) Str::uuid(),
                    'last_price' => $price,
                    'last_ts' => Carbon::createFromTimestampMs($tsMs),
                ]
            );
        }
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
