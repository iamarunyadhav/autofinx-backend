<?php

namespace App\Domain\MarketData;

use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\Support\InstrumentMapper;
use App\Domain\MarketData\Support\MarketClock;

class MarketFreshnessService
{
    public function __construct(private readonly MarketStateStore $store, private readonly InstrumentMapper $mapper, private readonly MarketClock $clock)
    {
    }

    public function evaluate(string $provider, string $instrument, StreamType $stream = StreamType::QUOTE, ?string $timeframe = null): array
    {
        $provider = strtolower($provider);
        $instrument = strtoupper($instrument);
        $marketType = $this->mapper->marketType($instrument);

        if (! $this->clock->isMarketExpectedOpen($provider, $instrument)) {
            return $this->store->transition($provider, $instrument, $stream, FeedState::MARKET_CLOSED, 'market_session_closed', ['session' => $this->clock->session($provider, $instrument)], $timeframe);
        }

        $health = $this->store->health($provider, $instrument, $stream, $timeframe);
        $lastReceivedMs = (int) ($health['last_received_at_ms'] ?? 0);
        $lastProviderEventMs = (int) ($health['last_provider_event_at_ms'] ?? 0);
        $quote = $stream === StreamType::QUOTE ? $this->store->quote($provider, $instrument) : null;
        if ($quote) {
            $lastReceivedMs = max($lastReceivedMs, (int) ($quote['received_at_ms'] ?? 0));
            $lastProviderEventMs = max($lastProviderEventMs, (int) ($quote['provider_event_at_ms'] ?? 0));
        }
        $referenceMs = $lastReceivedMs > 0 ? $lastReceivedMs : $lastProviderEventMs;

        if ($referenceMs <= 0) {
            if (($health['state'] ?? null) === FeedState::RECOVERING->value) {
                return array_merge($health, ['reason' => $health['reason'] ?? 'recovery_in_progress']);
            }

            return $this->store->transition($provider, $instrument, $stream, FeedState::STALE, 'no_stream_events_seen', ['market_type' => $marketType], $timeframe);
        }

        $ageMs = max(0, ((int) floor(microtime(true) * 1000)) - $referenceMs);
        $rules = $this->rules($marketType, $stream);

        if ($ageMs >= $rules['stale_after_ms']) {
            return $this->store->transition($provider, $instrument, $stream, FeedState::STALE, 'freshness_stale_after_ms_exceeded', ['age_ms' => $ageMs, 'stale_after_ms' => $rules['stale_after_ms']], $timeframe);
        }

        if ($ageMs >= $rules['degraded_after_ms']) {
            return $this->store->transition($provider, $instrument, $stream, FeedState::DEGRADED, 'freshness_degraded_after_ms_exceeded', ['age_ms' => $ageMs, 'degraded_after_ms' => $rules['degraded_after_ms']], $timeframe);
        }

        if (($health['state'] ?? null) !== FeedState::LIVE->value) {
            return $this->store->transition($provider, $instrument, $stream, FeedState::LIVE, 'fresh_quote_observed', [
                'data_age_ms' => max(0, ((int) floor(microtime(true) * 1000)) - $lastProviderEventMs),
                'received_age_ms' => $ageMs,
                'market_type' => $marketType,
            ], $timeframe);
        }

        return array_merge($health, ['state' => FeedState::LIVE->value, 'reason' => 'freshness_ok', 'data_age_ms' => max(0, ((int) floor(microtime(true) * 1000)) - $lastProviderEventMs), 'received_age_ms' => $ageMs, 'market_type' => $marketType]);
    }

    public function rules(string $marketType, StreamType $stream): array
    {
        $rules = (array) data_get(config('market_data.freshness', []), strtoupper($marketType).'.'.$stream->value, data_get(config('market_data.freshness', []), 'default.'.$stream->value, []));

        return [
            'expected_interval_ms' => max(1, (int) ($rules['expected_interval_ms'] ?? 1000)),
            'degraded_after_ms' => max(1, (int) ($rules['degraded_after_ms'] ?? 5000)),
            'stale_after_ms' => max(1, (int) ($rules['stale_after_ms'] ?? 15000)),
        ];
    }
}
