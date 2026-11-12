<?php

namespace App\Domain\Market;

/**
 * Bounded, in-process latest-value buffer for the websocket transport loop.
 *
 * Closed klines bypass the buffer. Quotes and open klines are coalesced by
 * symbol/scope, ordered by exchange event time, and drained at a short fixed
 * cadence. Nothing here marks data fresh: RealtimeMarketFeedService remains
 * the authoritative stale/future-frame validator after drain.
 */
class MarketTransportCoalescer
{
    /** @var array<string, array{payload:array,event_ms:int}> */
    private array $pending = [];
    private int $lastDrainMs;
    private int $received = 0;
    private int $emitted = 0;
    private int $coalesced = 0;
    private int $olderDropped = 0;
    private int $maxBuffered = 0;
    /** @var array<string, int> */
    private array $receivedByKind = [];

    public function __construct(private readonly int $flushIntervalMs = 100, ?int $startedAtMs = null)
    {
        $this->lastDrainMs = $startedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    /** @return array<int,array> */
    public function offer(array $payload, ?int $nowMs = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $this->received++;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $kline = is_array($data['k'] ?? null) ? $data['k'] : null;
        $kind = $this->frameKind($data, $kline);
        $this->receivedByKind[$kind] = ($this->receivedByKind[$kind] ?? 0) + 1;

        // Aggregate trades are additive facts. Coalescing them like quotes
        // would corrupt executed notional and CVD, so they bypass the buffer.
        if (strtolower((string) ($data['e'] ?? '')) === 'aggtrade') {
            $this->emitted++;
            return [$payload];
        }

        // A close is durable market history and must never wait behind or be
        // replaced by a newer quote. Remove the corresponding open snapshot.
        if ($kline !== null && (bool) ($kline['x'] ?? false)) {
            unset($this->pending[$this->scopeKey($data, $kline)]);
            $this->emitted++;
            return [$payload];
        }

        $key = $this->scopeKey($data, $kline);
        if ($key === null) {
            // Unknown/control frames are rare and retain prior behaviour.
            $this->emitted++;
            return [$payload];
        }

        $eventMs = (int) ($data['E'] ?? $data['T'] ?? $kline['T'] ?? $kline['t'] ?? 0);
        $existing = $this->pending[$key] ?? null;
        if ($existing !== null && $eventMs > 0 && $existing['event_ms'] > $eventMs) {
            $this->olderDropped++;
            return $this->drainIfDue($nowMs);
        }
        if ($existing !== null) {
            $this->coalesced++;
        }
        $this->pending[$key] = ['payload' => $payload, 'event_ms' => $eventMs];
        $this->maxBuffered = max($this->maxBuffered, count($this->pending));

        return $this->drainIfDue($nowMs);
    }

    /** @return array<int,array> */
    public function drainIfDue(?int $nowMs = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        if (($nowMs - $this->lastDrainMs) < max(1, $this->flushIntervalMs)) {
            return [];
        }

        return $this->drain($nowMs);
    }

    /** @return array<int,array> */
    public function drain(?int $nowMs = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $frames = array_column(array_values($this->pending), 'payload');
        $this->pending = [];
        $this->lastDrainMs = $nowMs;
        $this->emitted += count($frames);
        return $frames;
    }

    public function stats(): array
    {
        return [
            'received' => $this->received,
            'emitted' => $this->emitted,
            'coalesced' => $this->coalesced,
            'older_dropped' => $this->olderDropped,
            'buffered' => count($this->pending),
            'max_buffered' => $this->maxBuffered,
            'flush_interval_ms' => $this->flushIntervalMs,
            'received_by_kind' => $this->receivedByKind,
        ];
    }

    private function scopeKey(array $data, ?array $kline): ?string
    {
        $symbol = strtoupper((string) ($data['s'] ?? $data['symbol'] ?? ''));
        if ($symbol === '') {
            return null;
        }
        if ($kline !== null) {
            return 'candle:'.$symbol.':'.strtolower((string) ($kline['i'] ?? '1m'));
        }
        return 'quote:'.$symbol;
    }

    private function frameKind(array $data, ?array $kline): string
    {
        if ($kline !== null) {
            return (bool) ($kline['x'] ?? false) ? 'closed_kline' : 'open_kline';
        }

        $event = strtolower((string) ($data['e'] ?? ''));

        return $event !== '' ? $event : 'unknown';
    }
}
