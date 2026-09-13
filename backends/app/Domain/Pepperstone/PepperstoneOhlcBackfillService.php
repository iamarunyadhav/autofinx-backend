<?php

namespace App\Domain\Pepperstone;

use App\Domain\Venue\SupportsHistoricalCandles;
use App\Models\Instrument;
use App\Models\MarketOhlc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Multi-timeframe candle ingestion for Pepperstone instruments -- mirrors
 * App\Domain\Market\OandaOhlcBackfillService's granularity-map *pattern*
 * (per the plan's §2/§12), writing into the same `market_ohlc` table
 * (exchange='pepperstone') MTFConfirmationEngine/V3CompositeScorer/
 * MarketRegimeDetector all read from.
 */
class PepperstoneOhlcBackfillService
{
    public const DEFAULT_LIMIT = 500;

    public const DEFAULT_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h', '1d'];

    public function backfill(SupportsHistoricalCandles $venue, Instrument $instrument, string $timeframe, int $limit = self::DEFAULT_LIMIT): int
    {
        $symbol = $instrument->normalizedSymbol();
        $candles = $venue->fetchCandles($symbol, $timeframe, $limit);

        $written = 0;
        foreach ($candles as $candle) {
            if (! isset($candle['ts'])) {
                continue;
            }

            $ts = Carbon::parse((string) $candle['ts']);
            $existingId = MarketOhlc::query()
                ->where('exchange', 'pepperstone')
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->where('ts', $ts)
                ->value('id');

            MarketOhlc::updateOrCreate(
                ['exchange' => 'pepperstone', 'symbol' => $symbol, 'timeframe' => $timeframe, 'ts' => $ts],
                [
                    'id' => $existingId ?: (string) Str::uuid(),
                    'open' => (float) $candle['open'],
                    'high' => (float) $candle['high'],
                    'low' => (float) $candle['low'],
                    'close' => (float) $candle['close'],
                    'volume' => (float) ($candle['volume'] ?? 0),
                ],
            );
            $written++;
        }

        return $written;
    }

    /**
     * @param  string[]  $timeframes
     * @return array<string, int> timeframe => rows written
     */
    public function backfillAllTimeframes(SupportsHistoricalCandles $venue, Instrument $instrument, array $timeframes = self::DEFAULT_TIMEFRAMES, int $limit = self::DEFAULT_LIMIT): array
    {
        $results = [];
        foreach ($timeframes as $timeframe) {
            $results[$timeframe] = $this->backfill($venue, $instrument, $timeframe, $limit);
        }

        return $results;
    }
}
