<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\TimeframeRegistry;
use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;

/**
 * Genuinely new for Ratio Bot: no existing service walks backward through
 * history to check whether a past TradeForecastService::forecast() call
 * would have been directionally right (verified before writing this --
 * TradeForecastService/SignalPreviewService/RatioBotBrainService have no
 * such logic). Walk-forward, no lookahead: at each historical index $i,
 * only candles[0..i] are fed into forecast() (exactly what would have been
 * available at that moment), and the outcome is read from the REAL candle
 * horizonCandles later -- never from data forecast() itself could see.
 *
 * NEUTRAL calls are excluded from the hit-rate on purpose -- counting them
 * either way would make an honest "did the directional call work" number
 * dishonest (a bot that mostly says NEUTRAL would look artificially
 * accurate or inaccurate depending on how ties are scored).
 */
class ForecastHorizonBacktestService
{
    private const MAX_CANDLES = 500;
    private const MIN_SEED_CANDLES = 30;
    private const MAX_SAMPLES = 300;

    public function __construct(
        private readonly TradeForecastService $forecast,
        private readonly RatioBotBrainService $brain,
        private readonly TimeframeRegistry $timeframes,
    ) {
    }

    public function runForConfig(RatioBotConfig $config): ?RatioBotForecastBacktest
    {
        if (! (bool) $config->brain_enabled) {
            return null;
        }

        $timeframe = $config->timeFrame();
        $horizonSeconds = $this->brain->horizonSeconds($config);
        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        $horizonCandles = max(1, (int) ceil($horizonSeconds / $tfSeconds));

        $candles = MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $timeframe)
            ->orderByDesc('ts')
            ->limit(self::MAX_CANDLES)
            ->get(['open', 'high', 'low', 'close'])
            ->reverse()
            ->values()
            ->map(fn ($candle) => [
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
            ])
            ->all();

        $count = count($candles);
        $lastIndex = $count - $horizonCandles - 1;
        if ($lastIndex < self::MIN_SEED_CANDLES) {
            return null;
        }

        $iterations = $lastIndex - self::MIN_SEED_CANDLES + 1;
        $step = $iterations > self::MAX_SAMPLES ? (int) ceil($iterations / self::MAX_SAMPLES) : 1;

        $hits = 0;
        $total = 0;
        $confidenceSum = 0.0;

        for ($i = self::MIN_SEED_CANDLES; $i <= $lastIndex; $i += $step) {
            $slice = array_slice($candles, 0, $i + 1);
            $result = $this->forecast->forecast($slice, $timeframe, $horizonSeconds);
            if (! ($result['available'] ?? false)) {
                continue;
            }

            $bias = $result['bias'] ?? 'NEUTRAL';
            if ($bias === 'NEUTRAL') {
                continue;
            }

            $currentClose = (float) $slice[count($slice) - 1]['close'];
            if ($currentClose <= 0) {
                continue;
            }
            $futureClose = (float) $candles[$i + $horizonCandles]['close'];
            $actualMove = ($futureClose / $currentClose) - 1;
            $correct = ($bias === 'BUY' && $actualMove > 0) || ($bias === 'SELL' && $actualMove < 0);

            $total++;
            if ($correct) {
                $hits++;
            }
            $confidenceSum += (float) ($result['confidence'] ?? 0);
        }

        return RatioBotForecastBacktest::updateOrCreate(
            ['ratio_bot_config_id' => $config->id, 'horizon_seconds' => $horizonSeconds],
            [
                'sample_size' => $total,
                'hits' => $hits,
                'hit_rate_pct' => $total > 0 ? round(($hits / $total) * 100, 2) : null,
                'avg_confidence_pct' => $total > 0 ? round($confidenceSum / $total, 2) : null,
                'computed_at' => now(),
            ],
        );
    }
}
