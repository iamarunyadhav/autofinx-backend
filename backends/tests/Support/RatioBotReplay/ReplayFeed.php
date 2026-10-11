<?php

namespace Tests\Support\RatioBotReplay;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\MarketSnapshotService;
use App\Models\RatioBotConfig;

/**
 * The replay's price feed: one authoritative object holding the latest tick
 * per symbol in SIMULATED time. The two fakes below expose it through the
 * production service types the Ratio Bot reads prices from, because the real
 * implementations judge freshness against the wall clock (microtime), which
 * can never agree with a replayed clock.
 *
 * `$healthy = false` (or a tick timestamp left in the past) models a feed gap:
 * the snapshot turns stale and the production stale-feed paths take over.
 */
class ReplayFeed
{
    /** @var array<string, array{price: float, ts_ms: int}> */
    public array $ticks = [];

    public int $nowMs = 0;

    public function tick(string $symbol, float $price, int $tsMs): void
    {
        $this->ticks[strtoupper($symbol)] = ['price' => $price, 'ts_ms' => $tsMs];
    }

    public function snapshot(string $symbol, int $maxAgeSeconds): array
    {
        $t = $this->ticks[strtoupper($symbol)] ?? null;
        $ageMs = $t ? max(0, $this->nowMs - $t['ts_ms']) : null;
        $fresh = $t !== null && $ageMs <= $maxAgeSeconds * 1000;

        return [
            'provider' => 'binance',
            'symbol' => strtoupper($symbol),
            'instrument' => strtoupper($symbol),
            'price' => $t['price'] ?? null,
            'event_time' => null,
            'event_time_ms' => $t['ts_ms'] ?? 0,
            'received_at' => null,
            'received_at_ms' => $t['ts_ms'] ?? 0,
            'age_ms' => $ageMs,
            'age_seconds' => $ageMs !== null ? (int) floor($ageMs / 1000) : null,
            'source' => 'replay_feed',
            'connection_state' => $fresh ? 'live' : 'stale',
            'sequence' => $t['ts_ms'] ?? null,
            'update_id' => $t['ts_ms'] ?? null,
            'is_fresh' => $fresh,
            'stale_reason' => $fresh ? null : ($t === null ? 'no_tick' : 'replay_feed_gap'),
            'max_age_seconds' => $maxAgeSeconds,
            'thresholds' => [],
            'health' => ['state' => $fresh ? 'live' : 'stale', 'pipeline_latency_ms' => 0, 'transport_latency_ms' => 0],
            'quote' => null,
            'legacy' => null,
        ];
    }
}

class ReplaySnapshotService extends MarketSnapshotService
{
    public function __construct(private readonly ReplayFeed $feed)
    {
        // parent constructor deliberately skipped: no Redis, no wall clock.
    }

    public function forRatioBot(RatioBotConfig $config): array
    {
        return $this->feed->snapshot($config->normalizedSymbol(), $config->staleFeedWindowSeconds());
    }

    public function getLiveSnapshot(string $provider, string $symbol, ?string $legacyExchange = null, int $maxAgeSeconds = 15): array
    {
        return $this->feed->snapshot($symbol, $maxAgeSeconds);
    }
}

class ReplayPriceCache extends MarketPriceCacheService
{
    public function __construct(private readonly ReplayFeed $feed)
    {
    }

    public function setLatestPrice(string $exchange, string $symbol, float $price, ?int $tsMs = null, array $meta = []): void
    {
        // The replay feed is the only writer.
    }

    public function getLatestPrice(string $exchange, string $symbol): ?array
    {
        $t = $this->feed->ticks[strtoupper($symbol)] ?? null;
        if (! $t) {
            return null;
        }

        return ['exchange' => strtolower($exchange), 'symbol' => strtoupper($symbol), 'price' => $t['price'], 'timestamp_ms' => $t['ts_ms'], 'source' => 'replay_cache'];
    }

    public function getLiveFeedSnapshot(string $exchange, string $symbol, int $maxAgeSeconds = 15): ?array
    {
        return null;
    }
}
