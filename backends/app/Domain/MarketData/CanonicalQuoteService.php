<?php

namespace App\Domain\MarketData;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\Support\InstrumentMapper;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;

class CanonicalQuoteService
{
    public function __construct(
        private readonly MarketStateStore $store,
        private readonly MarketFreshnessService $freshness,
        private readonly MarketPriceCacheService $legacyPrices,
        private readonly InstrumentMapper $mapper,
        private readonly MarketSnapshotService $snapshots,
    ) {
    }

    public function forInstrument(string $provider, string $instrument, int $maxAgeSeconds = 15): array
    {
        $provider = strtolower($provider);
        $instrument = strtoupper($instrument);
        $health = $this->freshness->evaluate($provider, $instrument, StreamType::QUOTE);
        $quote = $this->store->quote($provider, $instrument);
        $state = (string) ($health['state'] ?? FeedState::CONNECTING->value);
        $ageMs = (int) ($health['data_age_ms'] ?? $health['received_age_ms'] ?? PHP_INT_MAX);
        $fresh = in_array($state, [FeedState::LIVE->value, FeedState::DEGRADED->value], true)
            && $ageMs <= ($maxAgeSeconds * 1000);

        return [
            'quote' => $quote,
            'health' => array_merge($health, [
                'fresh' => $fresh,
                'max_age_seconds' => $maxAgeSeconds,
            ]),
        ];
    }

    public function forRatioBot(RatioBotConfig $config): ?array
    {
        $snapshot = $this->snapshots->forRatioBot($config);
        if ((bool) ($snapshot['is_fresh'] ?? false) && (float) ($snapshot['price'] ?? 0) > 0) {
            return [
                'price' => (float) $snapshot['price'],
                'source' => 'canonical_market_snapshot',
                'timestamp' => Carbon::createFromTimestampMs((int) ($snapshot['event_time_ms'] ?: $snapshot['received_at_ms']))->setTimezone(config('app.timezone')),
                'price_age_seconds' => (int) ($snapshot['age_seconds'] ?? 0),
                'latency_ms' => (int) data_get($snapshot, 'health.pipeline_latency_ms', data_get($snapshot, 'health.transport_latency_ms', 0)),
                'quality' => [
                    'snapshot' => $snapshot,
                    'quote' => $snapshot['quote'] ?? null,
                    'health' => $snapshot['health'] ?? null,
                ],
            ];
        }

        return null;
    }
}
