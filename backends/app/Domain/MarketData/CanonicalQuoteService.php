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
        $exchange = $config->exchangeKey();
        $provider = strtolower((string) ($config->exchange ?: 'binance'));
        $instrument = $this->mapper->canonical($provider, $config->normalizedSymbol());
        $maxAgeSeconds = $config->staleFeedWindowSeconds();

        $canonical = $this->forInstrument($provider, $instrument, $maxAgeSeconds);
        $quote = $canonical['quote'];
        $health = $canonical['health'];

        if ($quote && (bool) ($health['fresh'] ?? false)) {
            $price = $quote['last'] ?? $quote['mid'] ?? $quote['bid'] ?? $quote['ask'] ?? null;
            if ((float) $price > 0) {
                $tsMs = (int) ($quote['provider_event_at_ms'] ?? $quote['received_at_ms'] ?? floor(microtime(true) * 1000));

                return [
                    'price' => (float) $price,
                    'source' => 'canonical_market_state',
                    'timestamp' => Carbon::createFromTimestampMs($tsMs),
                    'price_age_seconds' => max(0, (int) floor((int) ($health['data_age_ms'] ?? 0) / 1000)),
                    'latency_ms' => (int) ($health['pipeline_latency_ms'] ?? $health['transport_latency_ms'] ?? 0),
                    'quality' => $canonical,
                ];
            }
        }

        $legacy = $this->legacyPrices->getLiveFeedSnapshot($exchange, $config->normalizedSymbol(), $maxAgeSeconds);
        if ($legacy && (bool) ($legacy['fresh'] ?? false) && (float) ($legacy['price'] ?? 0) > 0) {
            return [
                'price' => (float) $legacy['price'],
                'source' => 'legacy_live_feed_snapshot',
                'timestamp' => Carbon::createFromTimestampMs((int) ($legacy['event_timestamp_ms'] ?: $legacy['received_at_ms'])),
                'price_age_seconds' => (int) ($legacy['age_seconds'] ?? 0),
                'latency_ms' => (int) ($legacy['event_lag_ms'] ?? 0),
                'quality' => [
                    'quote' => [
                        'provider' => $provider,
                        'instrument' => $instrument,
                        'last' => (float) $legacy['price'],
                    ],
                    'health' => [
                        'state' => FeedState::LIVE->value,
                        'fresh' => true,
                        'source' => 'legacy_live_feed_snapshot',
                    ],
                ],
            ];
        }

        return null;
    }
}
