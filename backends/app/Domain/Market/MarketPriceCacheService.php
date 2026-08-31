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
    private const LIVE_FEED_TTL_S = 120;

    public function setLatestPrice(
        string $exchange,
        string $symbol,
        float $price,
        ?int $tsMs = null,
        array $meta = [],
    ): void {
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

            Redis::transaction(function ($redis) use ($priceKey, $tsKey, $price, $tsMs): void {
                $redis->setex($priceKey, self::LIVE_FEED_TTL_S, (string) $price);
                $redis->setex($tsKey, self::LIVE_FEED_TTL_S, (string) $tsMs);
            });
            $this->writeLiveFeedSnapshot($exchange, $symbol, $price, $tsMs, $meta);
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

    public function getLiveFeedSnapshot(string $exchange, string $symbol, int $maxAgeSeconds = 15): ?array
    {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);

        try {
            $canonicalRaw = Redis::get($this->canonicalLiveKey($symbol));
            $feedKey = $this->feedKey($exchange, $symbol);
            $feedRaw = Redis::get($feedKey);
            [$raw, $sourceKey] = $this->freshestMatchingSnapshot($exchange, [
                $this->canonicalLiveKey($symbol) => $canonicalRaw,
                $feedKey => $feedRaw,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $receivedAtMs = (int) ($data['received_at_ms'] ?? 0);
        $ageSeconds = $receivedAtMs > 0
            ? max(0, (int) floor(((int) floor(microtime(true) * 1000) - $receivedAtMs) / 1000))
            : null;

        return [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'price' => (float) ($data['price'] ?? 0),
            'event_timestamp_ms' => (int) ($data['event_timestamp_ms'] ?? 0),
            'received_at_ms' => $receivedAtMs,
            'received_at' => (string) ($data['received_at'] ?? ''),
            'age_seconds' => $ageSeconds,
            'fresh' => $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds,
            'max_age_seconds' => $maxAgeSeconds,
            'source' => 'redis_live_feed',
            'source_key' => $sourceKey,
            'stream' => (string) ($data['stream'] ?? ''),
            'event_type' => (string) ($data['event_type'] ?? ''),
            'market_type' => (string) ($data['market_type'] ?? ''),
            'event_lag_ms' => (int) ($data['event_lag_ms'] ?? 0),
            'update_id' => $data['update_id'] ?? null,
        ];
    }

    /**
     * @param  string[]  $symbols
     */
    public function freshestLiveFeedSnapshot(string $exchange, array $symbols, int $maxAgeSeconds = 15): ?array
    {
        $freshest = null;

        foreach ($symbols as $symbol) {
            $snapshot = $this->getLiveFeedSnapshot($exchange, (string) $symbol, $maxAgeSeconds);
            if (! $snapshot) {
                continue;
            }

            if (! $freshest || (int) ($snapshot['received_at_ms'] ?? 0) > (int) ($freshest['received_at_ms'] ?? 0)) {
                $freshest = $snapshot;
            }
        }

        return $freshest;
    }

    private function writeLiveFeedSnapshot(string $exchange, string $symbol, float $price, int $tsMs, array $meta): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $payload = [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'price' => $price,
            'event_timestamp_ms' => $tsMs,
            'received_at_ms' => $nowMs,
            'received_at' => now()->toIso8601String(),
            'source' => 'binance_websocket',
            'stream' => (string) ($meta['stream'] ?? ''),
            'event_type' => (string) ($meta['event_type'] ?? ''),
            'market_type' => (string) ($meta['market_type'] ?? ''),
            'event_lag_ms' => (int) ($meta['event_lag_ms'] ?? max(0, $nowMs - $tsMs)),
            'update_id' => $meta['update_id'] ?? null,
            'worker_id' => $meta['worker_id'] ?? null,
            'connection_id' => $meta['connection_id'] ?? null,
            'state' => 'LIVE',
        ];

        Redis::setex($this->feedKey($exchange, $symbol), self::LIVE_FEED_TTL_S, json_encode($payload, JSON_UNESCAPED_SLASHES));
        Redis::setex($this->feedKey($exchange, '*'), self::LIVE_FEED_TTL_S, json_encode($payload, JSON_UNESCAPED_SLASHES));
        Redis::setex($this->canonicalLiveKey($symbol), self::LIVE_FEED_TTL_S, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function priceKey(string $exchange, string $symbol): string
    {
        return "price:{$exchange}:{$symbol}";
    }

    private function tsKey(string $exchange, string $symbol): string
    {
        return "ts:{$exchange}:{$symbol}";
    }

    private function feedKey(string $exchange, string $symbol): string
    {
        return "live-feed:{$exchange}:".strtoupper($symbol);
    }

    private function snapshotMatchesExchange(mixed $raw, string $exchange): bool
    {
        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return false;
        }

        $snapshotExchange = strtolower((string) ($data['exchange'] ?? ''));
        $marketType = strtolower((string) ($data['market_type'] ?? ''));

        if ($snapshotExchange === $exchange) {
            return true;
        }

        return match ($exchange) {
            'binance' => $marketType === '' || $marketType === 'spot',
            'binance_futures' => $marketType === 'futures',
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $snapshots
     * @return array{0: mixed, 1: string}
     */
    private function freshestMatchingSnapshot(string $exchange, array $snapshots): array
    {
        $selectedRaw = null;
        $selectedKey = '';
        $selectedReceivedAtMs = 0;

        foreach ($snapshots as $key => $raw) {
            if (! $this->snapshotMatchesExchange($raw, $exchange)) {
                continue;
            }

            $data = json_decode((string) $raw, true);
            $receivedAtMs = is_array($data) ? (int) ($data['received_at_ms'] ?? 0) : 0;
            if ($receivedAtMs >= $selectedReceivedAtMs) {
                $selectedRaw = $raw;
                $selectedKey = (string) $key;
                $selectedReceivedAtMs = $receivedAtMs;
            }
        }

        return [$selectedRaw, $selectedKey];
    }

    private function canonicalLiveKey(string $symbol): string
    {
        return 'market:live:'.strtoupper($symbol);
    }
}
