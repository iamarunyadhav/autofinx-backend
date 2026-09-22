<?php

namespace App\Domain\Market;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Bounded, server-owned candle window for live signal calculation.
 *
 * This deliberately stores a compact JSON array rather than an unbounded Redis
 * stream: every key is capped, expires, and is scoped by exchange/symbol/timeframe.
 * It is a realtime cache, not the historical source of record (closed candles
 * continue to be persisted by RealtimeMarketFeedService).
 */
class LiveCandleCacheService
{
    private const MAX_CANDLES = 240;
    private const TTL_SECONDS = 21600;

    /** @param array{timestamp:int,open:float,high:float,low:float,close:float,volume:float,opens_at?:string} $candle */
    public function upsert(string $exchange, string $symbol, string $timeframe, array $candle, bool $closed, int $eventTimestampMs, bool $publish = true): bool
    {
        $key = $this->key($exchange, $symbol, $timeframe);
        $timestamp = (int) ($candle['timestamp'] ?? 0);
        if ($timestamp <= 0 || min((float) $candle['open'], (float) $candle['high'], (float) $candle['low'], (float) $candle['close']) <= 0) {
            return false;
        }

        try {
            $rows = json_decode((string) Redis::get($key), true);
            $rows = is_array($rows) ? $rows : [];
            $index = null;
            $wasClosed = false;
            foreach ($rows as $i => $row) {
                if ((int) ($row['timestamp'] ?? 0) === $timestamp) {
                    $index = $i;
                    $wasClosed = (bool) ($row['is_closed'] ?? false);
                    if ((int) ($row['event_timestamp_ms'] ?? 0) > $eventTimestampMs) {
                        return false;
                    }
                    break;
                }
            }

            $record = [
                'timestamp' => $timestamp,
                'open' => (float) $candle['open'], 'high' => (float) $candle['high'],
                'low' => (float) $candle['low'], 'close' => (float) $candle['close'],
                'volume' => (float) $candle['volume'],
                'opens_at' => (string) ($candle['opens_at'] ?? ''),
                // Once a candle is closed, a delayed open-frame must never reopen it.
                'is_closed' => $closed || (bool) ($index !== null ? ($rows[$index]['is_closed'] ?? false) : false),
                'event_timestamp_ms' => $eventTimestampMs,
            ];
            if ($index === null) {
                $rows[] = $record;
            } else {
                $rows[$index] = $record;
            }
            usort($rows, static fn (array $a, array $b): int => ((int) $a['timestamp']) <=> ((int) $b['timestamp']));
            $rows = array_slice($rows, -self::MAX_CANDLES);
            Redis::setex($key, self::TTL_SECONDS, json_encode($rows, JSON_THROW_ON_ERROR));
            if ($closed && ! $wasClosed && $publish) {
                Redis::publish($this->channel($exchange, $symbol, $timeframe), json_encode($record, JSON_THROW_ON_ERROR));
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, array<string,mixed>> $candles */
    public function seed(string $exchange, string $symbol, string $timeframe, array $candles): void
    {
        foreach ($candles as $candle) {
            $this->upsert($exchange, $symbol, $timeframe, $candle, true, (int) ($candle['timestamp'] ?? 0), false);
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function candles(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        try {
            $rows = json_decode((string) Redis::get($this->key($exchange, $symbol, $timeframe)), true);
            return is_array($rows) ? array_slice($rows, -max(1, min($limit, self::MAX_CANDLES))) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Read-only bounded-cache telemetry for operator diagnostics. */
    public function stats(string $exchange, string $symbol, string $timeframe): array
    {
        $rows = $this->candles($exchange, $symbol, $timeframe, self::MAX_CANDLES);
        $latest = $rows === [] ? null : $rows[array_key_last($rows)];

        // Scan newest-first for the most recent open/closed event timestamps
        // -- distinct from "latest row", since the newest row by candle
        // timestamp can itself be either state depending on what has arrived
        // most recently (e.g. the newest row is the still-open current
        // candle, while the previous row was the last one to actually close).
        $lastOpenEventMs = null;
        $lastClosedEventMs = null;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            $eventMs = (int) ($row['event_timestamp_ms'] ?? 0);
            if ($eventMs <= 0) {
                continue;
            }
            if (($row['is_closed'] ?? false) && $lastClosedEventMs === null) {
                $lastClosedEventMs = $eventMs;
            }
            if (! ($row['is_closed'] ?? false) && $lastOpenEventMs === null) {
                $lastOpenEventMs = $eventMs;
            }
            if ($lastOpenEventMs !== null && $lastClosedEventMs !== null) {
                break;
            }
        }

        return [
            'size' => count($rows),
            'latest_timestamp' => $latest['timestamp'] ?? null,
            'latest_event_timestamp_ms' => $latest['event_timestamp_ms'] ?? null,
            'latest_is_closed' => $latest['is_closed'] ?? null,
            'last_open_kline_at' => $lastOpenEventMs !== null ? Carbon::createFromTimestampMs($lastOpenEventMs)->toIso8601String() : null,
            'last_closed_kline_at' => $lastClosedEventMs !== null ? Carbon::createFromTimestampMs($lastClosedEventMs)->toIso8601String() : null,
        ];
    }

    private function key(string $exchange, string $symbol, string $timeframe): string
    {
        return sprintf('live-candles:%s:%s:%s', strtolower($exchange), strtoupper($symbol), strtolower($timeframe));
    }

    private function channel(string $exchange, string $symbol, string $timeframe): string
    {
        return 'live-candle:'.strtolower($exchange).':'.strtoupper($symbol).':'.strtolower($timeframe);
    }
}
