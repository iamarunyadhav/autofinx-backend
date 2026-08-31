<?php

namespace App\Domain\MarketData;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\Support\InstrumentMapper;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;

class MarketSnapshotService
{
    public function __construct(
        private readonly MarketStateStore $store,
        private readonly MarketFreshnessService $freshness,
        private readonly MarketPriceCacheService $legacyPrices,
        private readonly InstrumentMapper $mapper,
    ) {
    }

    public function forRatioBot(RatioBotConfig $config): array
    {
        return $this->getLiveSnapshot(
            strtolower((string) ($config->exchange ?: 'binance')),
            $config->normalizedSymbol(),
            $config->exchangeKey(),
            $config->staleFeedWindowSeconds(),
        );
    }

    public function getLiveSnapshot(string $provider, string $symbol, ?string $legacyExchange = null, int $maxAgeSeconds = 15): array
    {
        $provider = strtolower(trim($provider));
        $symbol = strtoupper(trim($symbol));
        $instrument = $this->mapper->canonical($provider, $symbol);
        $legacyExchange ??= $provider;

        $health = $this->freshness->evaluate($provider, $instrument, StreamType::QUOTE);
        $quote = $this->store->quote($provider, $instrument);
        $legacy = $this->legacyPrices->getLiveFeedSnapshot($legacyExchange, $symbol, $maxAgeSeconds);

        $selected = null;
        if (is_array($legacy) && (float) ($legacy['price'] ?? 0) > 0) {
            $legacyReceivedAtMs = (int) ($legacy['received_at_ms'] ?? 0);
            $selected = [
                'price' => (float) $legacy['price'],
                'event_time_ms' => (int) ($legacy['event_timestamp_ms'] ?: $legacyReceivedAtMs),
                'received_at_ms' => $legacyReceivedAtMs,
                'source' => 'canonical_market_live_redis',
                'update_id' => $legacy['update_id'] ?? null,
                'raw' => $legacy,
            ];
        }

        if ($selected === null && is_array($quote) && (float) ($quote['last'] ?? $quote['mid'] ?? $quote['bid'] ?? $quote['ask'] ?? 0) > 0) {
            $receivedAtMs = (int) ($quote['received_at_ms'] ?? 0);
            $selected = [
                'price' => (float) ($quote['last'] ?? $quote['mid'] ?? $quote['bid'] ?? $quote['ask']),
                'event_time_ms' => (int) ($quote['provider_event_at_ms'] ?? $receivedAtMs),
                'received_at_ms' => $receivedAtMs,
                'source' => 'normalized_market_state_redis',
                'update_id' => $quote['sequence'] ?? $quote['update_id'] ?? null,
                'raw' => $quote,
            ];
        }

        $nowMs = (int) floor(microtime(true) * 1000);
        $receivedAtMs = (int) ($selected['received_at_ms'] ?? 0);
        $ageMs = $receivedAtMs > 0 ? max(0, $nowMs - $receivedAtMs) : null;
        $rules = $this->freshness->rules($this->mapper->marketType($instrument), StreamType::QUOTE);
        $state = $this->stateForAge($ageMs, $rules, (string) ($health['state'] ?? FeedState::CONNECTING->value));
        $isFresh = in_array($state, [FeedState::LIVE->value, FeedState::DEGRADED->value], true)
            && $ageMs !== null
            && $ageMs <= ($maxAgeSeconds * 1000);

        return [
            'provider' => $provider,
            'symbol' => $symbol,
            'instrument' => $instrument,
            'price' => $selected['price'] ?? null,
            'event_time' => $this->isoFromMs((int) ($selected['event_time_ms'] ?? 0)),
            'event_time_ms' => (int) ($selected['event_time_ms'] ?? 0),
            'received_at' => $this->isoFromMs($receivedAtMs),
            'received_at_ms' => $receivedAtMs,
            'age_ms' => $ageMs,
            'age_seconds' => $ageMs !== null ? (int) floor($ageMs / 1000) : null,
            'source' => $selected['source'] ?? null,
            'connection_state' => $state,
            'sequence' => $selected['update_id'] ?? null,
            'update_id' => $selected['update_id'] ?? null,
            'is_fresh' => $isFresh,
            'stale_reason' => $isFresh ? null : $this->staleReason($ageMs, $rules, $health),
            'max_age_seconds' => $maxAgeSeconds,
            'thresholds' => [
                'live_after_ms' => 0,
                'degraded_after_ms' => $rules['degraded_after_ms'],
                'stale_after_ms' => $rules['stale_after_ms'],
            ],
            'health' => $health,
            'quote' => $quote,
            'legacy' => $legacy,
        ];
    }

    private function stateForAge(?int $ageMs, array $rules, string $fallback): string
    {
        if ($fallback === FeedState::RECOVERING->value) {
            return FeedState::RECOVERING->value;
        }

        if ($ageMs === null) {
            return FeedState::STALE->value;
        }

        if ($ageMs >= (int) $rules['stale_after_ms']) {
            return FeedState::STALE->value;
        }

        if ($ageMs >= (int) $rules['degraded_after_ms']) {
            return FeedState::DEGRADED->value;
        }

        return FeedState::LIVE->value;
    }

    private function staleReason(?int $ageMs, array $rules, array $health): string
    {
        if (($health['state'] ?? null) === FeedState::RECOVERING->value) {
            return (string) ($health['reason'] ?? 'recovery_in_progress');
        }

        if ($ageMs === null) {
            return 'no_selected_symbol_websocket_redis_snapshot';
        }

        return $ageMs >= (int) $rules['stale_after_ms']
            ? 'freshness_stale_after_ms_exceeded'
            : 'freshness_window_exceeded';
    }

    private function isoFromMs(int $timestampMs): ?string
    {
        return $timestampMs > 0 ? Carbon::createFromTimestampMs($timestampMs)->toIso8601String() : null;
    }
}
