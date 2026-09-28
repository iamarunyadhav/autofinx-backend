<?php

namespace App\Domain\Prediction;

use App\Models\PredictionMarketSnapshot;
use Illuminate\Support\Carbon;

/**
 * Append-only research archive. Duplicate market points are ignored; existing
 * observations are never updated by the prediction system.
 */
class PredictionMarketArchiveService
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function capture(array $snapshot): bool
    {
        $symbol = strtoupper(trim((string) ($snapshot['symbol'] ?? '')));
        $exchange = strtolower(trim((string) ($snapshot['exchange'] ?? '')));
        $timeframe = strtolower(trim((string) ($snapshot['timeframe'] ?? '')));
        $ts = (int) ($snapshot['ts'] ?? 0);

        if ($symbol === '' || $exchange === '' || $timeframe === '' || $ts <= 0) {
            return false;
        }

        return PredictionMarketSnapshot::query()->insertOrIgnore([
            'exchange' => $exchange,
            'market_type' => strtolower((string) ($snapshot['market_type'] ?? 'crypto')),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'ts' => $ts,
            'bid' => $snapshot['bid'] ?? null,
            'ask' => $snapshot['ask'] ?? null,
            'mid' => $snapshot['mid'] ?? null,
            'last' => $snapshot['last'] ?? null,
            'mark' => $snapshot['mark'] ?? null,
            'spread' => $snapshot['spread'] ?? null,
            'open' => $snapshot['open'] ?? null,
            'high' => $snapshot['high'] ?? null,
            'low' => $snapshot['low'] ?? null,
            'close' => $snapshot['close'] ?? null,
            'volume' => $snapshot['volume'] ?? null,
            'source' => (string) ($snapshot['source'] ?? 'canonical_market_feed'),
            'feed_sequence' => isset($snapshot['feed_sequence']) ? (string) $snapshot['feed_sequence'] : null,
            'quality_json' => isset($snapshot['quality']) ? json_encode($snapshot['quality'], JSON_THROW_ON_ERROR) : null,
            'captured_at' => $snapshot['captured_at'] ?? Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]) === 1;
    }
}
