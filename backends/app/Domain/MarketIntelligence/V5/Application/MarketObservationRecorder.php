<?php

namespace App\Domain\MarketIntelligence\V5\Application;

use App\Domain\MarketOpportunityBrain\Data\Candle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persists only provider-confirmed closed candles, idempotently by symbol/timeframe/open time. */
final class MarketObservationRecorder
{
    /** @param list<Candle> $candles */
    public function record(array $candles): int
    {
        $now = now();
        $rows = [];

        foreach ($candles as $candle) {
            if (! $candle->isClosed) {
                continue;
            }

            $openMs = $this->milliseconds($candle->openTimeUtc);
            $closeMs = $this->milliseconds($candle->closeTimeUtc);
            $hashPayload = implode('|', [
                $candle->symbol, $candle->timeframe, $openMs, $closeMs,
                $candle->open, $candle->high, $candle->low, $candle->close,
                $candle->volume, $candle->takerVolume ?? '', $candle->source,
            ]);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'symbol' => $candle->symbol,
                'timeframe' => $candle->timeframe,
                'candle_open_ms' => $openMs,
                'candle_close_ms' => $closeMs,
                'open' => $candle->open,
                'high' => $candle->high,
                'low' => $candle->low,
                'close' => $candle->close,
                'volume' => $candle->volume,
                'taker_buy_volume' => $candle->takerVolume,
                'funding_rate' => null,
                'open_interest' => null,
                'source' => $candle->source,
                'source_revision' => null,
                'data_hash_sha256' => hash('sha256', $hashPayload),
                'observed_at' => $candle->observedAtUtc,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('v5_market_observations')->upsert(
            $rows,
            ['symbol', 'timeframe', 'candle_open_ms'],
            [
                'candle_close_ms', 'open', 'high', 'low', 'close', 'volume',
                'taker_buy_volume', 'source', 'source_revision', 'data_hash_sha256',
                'observed_at', 'updated_at',
            ]
        );

        return count($rows);
    }

    private function milliseconds(string $timestamp): int
    {
        $date = new \DateTimeImmutable($timestamp);

        return ($date->getTimestamp() * 1000) + (int) $date->format('v');
    }
}
