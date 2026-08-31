<?php

namespace App\Domain\MarketData;

use App\Domain\MarketData\Data\NormalizedQuote;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class MarketStateStore
{
    public function putQuote(NormalizedQuote $quote, StreamType $stream = StreamType::QUOTE): bool
    {
        $current = $this->quote($quote->provider, $quote->instrument);
        if ($current && (int) ($current['provider_event_at_ms'] ?? 0) > ($quote->providerEventAtMs + 1000)) {
            $this->incrementMetric('out_of_order_events_total', $quote->provider, $quote->instrument, $stream);
            return false;
        }
        if ($current && (int) ($current['received_at_ms'] ?? 0) > ($quote->receivedAtMs + 1000)) {
            $this->incrementMetric('obsolete_generation_events_total', $quote->provider, $quote->instrument, $stream);
            return false;
        }

        try {
            Redis::transaction(function ($redis) use ($quote, $stream): void {
                $ttl = $this->ttl($quote->marketType, $stream);
                $redis->setex($this->quoteKey($quote->provider, $quote->instrument), $ttl, json_encode($quote->toArray(), JSON_UNESCAPED_SLASHES));
                $redis->setex($this->healthKey($quote->provider, $quote->instrument, $stream), max($ttl, 120), json_encode($this->liveHealthPayload($quote, $stream), JSON_UNESCAPED_SLASHES));
            });
            $this->incrementMetric('market_events_processed_total', $quote->provider, $quote->instrument, $stream);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('[MarketData] Redis hot-state write failed', [
                'provider' => $quote->provider,
                'instrument' => $quote->instrument,
                'stream_type' => $stream->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function quote(string $provider, string $instrument): ?array
    {
        try {
            $raw = Redis::get($this->quoteKey($provider, $instrument));
        } catch (\Throwable) {
            return null;
        }

        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function transition(string $provider, string $instrument, StreamType $stream, FeedState $state, string $reason, array $context = [], ?string $timeframe = null): array
    {
        $current = $this->health($provider, $instrument, $stream, $timeframe);
        $previous = (string) ($current['state'] ?? FeedState::CONNECTING->value);
        $payload = array_merge($current, [
            'provider' => strtolower($provider),
            'instrument' => strtoupper($instrument),
            'stream_type' => $stream->value,
            'timeframe' => $timeframe,
            'previous_state' => $previous,
            'state' => $state->value,
            'reason' => $reason,
            'transitioned_at' => now()->toIso8601String(),
            'context' => $context,
        ]);

        try {
            Redis::setex($this->healthKey($provider, $instrument, $stream, $timeframe), 3600, json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $exception) {
            Log::warning('[MarketData] Feed-state transition write failed', ['provider' => $provider, 'instrument' => $instrument, 'stream_type' => $stream->value, 'state' => $state->value, 'error' => $exception->getMessage()]);
        }

        if ($previous !== $state->value) {
            Log::info('[MarketData] Feed-state transition', $payload);
        }

        return $payload;
    }

    public function health(string $provider, string $instrument, StreamType $stream = StreamType::QUOTE, ?string $timeframe = null): array
    {
        try {
            $raw = Redis::get($this->healthKey($provider, $instrument, $stream, $timeframe));
        } catch (\Throwable) {
            return ['provider' => strtolower($provider), 'instrument' => strtoupper($instrument), 'stream_type' => $stream->value, 'state' => FeedState::DEGRADED->value, 'reason' => 'redis_unavailable'];
        }

        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : ['provider' => strtolower($provider), 'instrument' => strtoupper($instrument), 'stream_type' => $stream->value, 'state' => FeedState::CONNECTING->value, 'reason' => 'no_health_state'];
    }

    public function recoveryLockKey(string $provider, string $instrument, StreamType $stream): string
    {
        return sprintf('autofinx:market:recovery:%s:%s:%s', strtolower($provider), strtoupper($instrument), $stream->value);
    }

    public function quoteKey(string $provider, string $instrument): string
    {
        return sprintf('autofinx:market:%s:%s:quote', strtolower($provider), strtoupper($instrument));
    }

    public function healthKey(string $provider, string $instrument, StreamType $stream, ?string $timeframe = null): string
    {
        return sprintf('autofinx:market:%s:%s:health:%s%s', strtolower($provider), strtoupper($instrument), $stream->value, $timeframe ? ':'.strtolower($timeframe) : '');
    }

    private function liveHealthPayload(NormalizedQuote $quote, StreamType $stream): array
    {
        $nowMs = (int) floor(microtime(true) * 1000);

        return [
            'provider' => $quote->provider,
            'instrument' => $quote->instrument,
            'market_type' => $quote->marketType,
            'stream_type' => $stream->value,
            'state' => FeedState::LIVE->value,
            'reason' => 'event_received',
            'last_provider_event_at_ms' => $quote->providerEventAtMs,
            'last_received_at_ms' => $quote->receivedAtMs,
            'last_processed_at_ms' => $quote->processedAtMs,
            'data_age_ms' => max(0, $nowMs - $quote->providerEventAtMs),
            'transport_latency_ms' => max(0, $quote->receivedAtMs - $quote->providerEventAtMs),
            'processing_latency_ms' => max(0, $quote->processedAtMs - $quote->receivedAtMs),
            'pipeline_latency_ms' => max(0, $quote->processedAtMs - $quote->providerEventAtMs),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function ttl(string $marketType, StreamType $stream): int
    {
        return max(10, (int) data_get(config('market_data.freshness', []), strtoupper($marketType).'.'.$stream->value.'.ttl_seconds', data_get(config('market_data.freshness', []), 'default.'.$stream->value.'.ttl_seconds', 120)));
    }

    private function incrementMetric(string $metric, string $provider, string $instrument, StreamType $stream): void
    {
        try {
            Redis::incr(sprintf('autofinx:metrics:%s:%s:%s:%s', $metric, strtolower($provider), strtoupper($instrument), $stream->value));
        } catch (\Throwable) {
        }
    }
}
