<?php

namespace App\Domain\Pepperstone;

use App\Domain\Market\Indicators\Indicators;
use App\Models\Instrument;
use App\Models\MarketOhlc;

/**
 * Pepperstone-scoped composite trend/direction scorer -- this module's own
 * "V3", built in the same SPIRIT as
 * App\Domain\RatioBot\MultiWindowTrendScoringService::score() (a
 * short/medium/long candle-count composite), but genuinely net-new: there
 * is no existing shared "V3 API" to call into for a non-RatioBotConfig
 * instrument (confirmed during planning -- see the plan's "What I
 * verified" section, point 1). Operates on Instrument/InstrumentMapping,
 * not RatioBotConfig.
 *
 * Confirmation-only: never throws, always returns a structured result;
 * insufficient candle history reports NEUTRAL/insufficient-data rather
 * than erroring (mirrors MTFConfirmationEngine's own rule).
 */
class V3CompositeScorer
{
    /**
     * Three candle-count windows over a single timeframe (mirrors
     * MultiWindowTrendScoringService's short/medium/long shape). Long
     * carries the most weight -- a longer read is less noise-sensitive.
     */
    private const WINDOWS = ['short' => 20, 'medium' => 50, 'long' => 100];
    private const WINDOW_WEIGHTS = ['short' => 0.20, 'medium' => 0.30, 'long' => 0.50];

    private const EMA_FAST = 10;
    private const EMA_SLOW = 30;
    private const RSI_PERIOD = 14;

    /** Composite score band inside which direction is reported NEUTRAL. */
    private const NEUTRAL_BAND = 0.10;

    public function score(Instrument $instrument, string $timeframe = '1h', string $exchange = 'pepperstone'): array
    {
        $required = max(self::WINDOWS) + self::EMA_SLOW;
        $closes = $this->recentCloses($exchange, $instrument->normalizedSymbol(), $timeframe, $required + 10);

        if (count($closes) < $required) {
            return [
                'direction' => 'NEUTRAL',
                'confidence' => 0.0,
                'insufficient_data' => true,
                'windows' => [],
            ];
        }

        $windowResults = [];
        $weightedSum = 0.0;

        foreach (self::WINDOWS as $name => $windowSize) {
            $slice = array_slice($closes, -($windowSize + self::EMA_SLOW));
            $emaFast = Indicators::ema($slice, self::EMA_FAST);
            $emaSlow = Indicators::ema($slice, self::EMA_SLOW);
            $rsi = Indicators::rsi($slice, self::RSI_PERIOD);

            $trendSign = ($emaFast !== null && $emaSlow !== null) ? ($emaFast > $emaSlow ? 1.0 : -1.0) : 0.0;
            // RSI distance from 50 (clamped) expresses conviction strength,
            // not an independent BUY/SELL rule -- it scales the trend
            // signal's magnitude, never flips its sign.
            $rsiConviction = $rsi !== null ? min(1.0, abs($rsi - 50.0) / 50.0) : 0.5;

            $windowScore = $trendSign * $rsiConviction;
            $windowResults[$name] = [
                'ema_fast' => $emaFast,
                'ema_slow' => $emaSlow,
                'rsi' => $rsi,
                'score' => $windowScore,
            ];

            $weightedSum += $windowScore * self::WINDOW_WEIGHTS[$name];
        }

        $direction = 'NEUTRAL';
        if ($weightedSum > self::NEUTRAL_BAND) {
            $direction = 'BUY';
        } elseif ($weightedSum < -self::NEUTRAL_BAND) {
            $direction = 'SELL';
        }

        return [
            'direction' => $direction,
            'confidence' => round(min(1.0, abs($weightedSum)), 4),
            'insufficient_data' => false,
            'windows' => $windowResults,
        ];
    }

    private function recentCloses(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        return MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->orderByDesc('ts')
            ->limit($limit)
            ->get(['close'])
            ->reverse()
            ->values()
            ->map(static fn ($row) => (float) $row->close)
            ->all();
    }
}
