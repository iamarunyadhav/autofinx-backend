<?php

namespace App\Domain\Pepperstone;

use App\Domain\Market\Indicators\Indicators;
use App\Models\Instrument;
use App\Models\MarketOhlc;
use Illuminate\Support\Carbon;

/**
 * Multi-timeframe confirmation engine for the Pepperstone Metals & Energy
 * module -- see the approved plan's §4. Reads CLOSED candles only (the
 * still-forming candle for each timeframe is excluded, per the user's
 * §3.F) from `market_ohlc`, computes EMA/RSI/ATR/ADX+DI via the existing,
 * broker-agnostic `Indicators` library (zero new indicator math), and rolls
 * per-timeframe raw signals into GROUPED scores (Trend/Momentum/
 * Price-Action/Volatility) to avoid double-counting individual indicators.
 *
 * Never returns a bare BUY/SELL -- always the full structured shape
 * (direction, confidence, trend_strength, agreement_score, timeframes{},
 * features{}, conflicts[]) the plan's §4 calls for.
 *
 * A timeframe with no (or insufficient) candle history reports NEUTRAL /
 * insufficient-data rather than erroring -- there is currently zero live
 * candle ingestion for Pepperstone (Phase 0 pending), so this is the
 * expected, deterministic, tested case today.
 */
class MTFConfirmationEngine
{
    public const DEFAULT_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h', '1d'];

    /**
     * Grouped score weights -- deliberately NOT a naive sum of every
     * individual indicator (anti-double-counting, per the user's §5).
     * Trend and Momentum and Price-Action are directional (their signed
     * scores decide direction); Volatility is NOT directional -- it scales
     * how much this timeframe's read is trusted (a normal-volatility read
     * is trusted more than an elevated-volatility one), folded into the
     * confidence contribution rather than the direction sign.
     */
    private const WEIGHT_TREND = 0.40;
    private const WEIGHT_MOMENTUM = 0.20;
    private const WEIGHT_PRICE_ACTION = 0.20;
    private const WEIGHT_VOLATILITY = 0.20;

    /**
     * Timeframe agreement weights for basket DIRECTION -- H4/D1 (dominant
     * trend / macro bias) carry the most weight; M5/M15 (execution timing
     * / short-term momentum) the least, per the user's own stated roles.
     */
    private const TIMEFRAME_WEIGHTS = [
        '5m' => 0.05,
        '15m' => 0.10,
        '30m' => 0.10,
        '1h' => 0.15,
        '4h' => 0.30,
        '1d' => 0.30,
    ];

    /** Minutes-per-candle map, used to detect/exclude the still-forming candle. */
    private const TIMEFRAME_MINUTES = [
        '5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '4h' => 240, '1d' => 1440,
    ];

    /**
     * Needs at least enough closed candles for a trustworthy EMA200 read
     * (the slowest indicator this engine uses) plus a small buffer. Below
     * this, the timeframe is reported NEUTRAL/insufficient rather than
     * computed on a partial, misleading basis.
     */
    private const MIN_CANDLES_PER_TIMEFRAME = 210;

    private const EMA_FAST = 20;
    private const EMA_MID = 50;
    private const EMA_SLOW = 200;
    private const RSI_PERIOD = 14;
    private const ATR_PERIOD = 14;
    private const ADX_PERIOD = 14;

    private const RSI_BULLISH_THRESHOLD = 55.0;
    private const RSI_BEARISH_THRESHOLD = 45.0;

    /** ATR / last-close ratio above which this timeframe is "elevated" volatility. */
    private const ATR_ELEVATED_RATIO = 0.02;

    /** Confidence multiplier applied to a timeframe reporting elevated volatility. */
    private const VOLATILITY_ELEVATED_CONFIDENCE_FACTOR = 0.5;

    private const DOJI_BODY_TO_RANGE_RATIO = 0.1;
    private const REJECTION_WICK_TO_BODY_RATIO = 2.0;

    /** Per-timeframe directional score band treated as "no clear direction". */
    private const NEUTRAL_BAND = 0.05;

    public function confirm(Instrument $instrument, string $exchange = 'pepperstone', array $timeframes = self::DEFAULT_TIMEFRAMES): array
    {
        $symbol = $instrument->normalizedSymbol();

        $perTimeframe = [];
        foreach ($timeframes as $timeframe) {
            $perTimeframe[$timeframe] = $this->analyzeTimeframe($exchange, $symbol, $timeframe);
        }

        $weightedDirectionSum = 0.0;
        $totalDirectionWeight = 0.0;
        $trendStrengthSum = 0.0;
        $trendStrengthWeight = 0.0;
        $agreeingCount = 0;
        $consideredCount = 0;
        $conflicts = [];

        // First pass: figure out the provisional weighted direction so we
        // can compute agreement/conflicts against it in a second pass.
        foreach ($perTimeframe as $timeframe => $tf) {
            if (! $tf['sufficient_data']) {
                continue;
            }
            $weight = self::TIMEFRAME_WEIGHTS[$timeframe] ?? 0.0;
            $weightedDirectionSum += $tf['score'] * $tf['confidence'] * $weight;
            $totalDirectionWeight += $weight;

            if ($tf['adx']['adx'] !== null) {
                $trendStrengthSum += $tf['adx']['adx'] * $weight;
                $trendStrengthWeight += $weight;
            }
        }

        $overallDirection = 'NEUTRAL';
        $confidence = 0.0;
        if ($totalDirectionWeight > 0) {
            $normalizedScore = $weightedDirectionSum / $totalDirectionWeight;
            $confidence = min(1.0, abs($normalizedScore));
            if ($normalizedScore > self::NEUTRAL_BAND) {
                $overallDirection = 'BUY';
            } elseif ($normalizedScore < -self::NEUTRAL_BAND) {
                $overallDirection = 'SELL';
            }
        }

        $overallSign = $overallDirection === 'BUY' ? 1 : ($overallDirection === 'SELL' ? -1 : 0);

        foreach ($perTimeframe as $timeframe => $tf) {
            if (! $tf['sufficient_data']) {
                continue;
            }
            $consideredCount++;
            $tfSign = $tf['score'] > self::NEUTRAL_BAND ? 1 : ($tf['score'] < -self::NEUTRAL_BAND ? -1 : 0);

            if ($overallSign !== 0 && $tfSign !== 0 && $tfSign !== $overallSign) {
                $conflicts[] = $timeframe;
            } elseif ($overallSign === 0 || $tfSign === $overallSign) {
                $agreeingCount++;
            }
        }

        $agreementScore = $consideredCount > 0 ? $agreeingCount / $consideredCount : 0.0;
        $trendStrength = $trendStrengthWeight > 0 ? round($trendStrengthSum / $trendStrengthWeight, 2) : 0.0;

        return [
            'direction' => $overallDirection,
            'confidence' => round($confidence, 4),
            'trend_strength' => $trendStrength,
            'agreement_score' => round($agreementScore, 4),
            'timeframes' => $perTimeframe,
            'features' => $this->buildFeatureSummary($perTimeframe),
            'conflicts' => $conflicts,
        ];
    }

    private function analyzeTimeframe(string $exchange, string $symbol, string $timeframe): array
    {
        $candles = $this->closedCandles($exchange, $symbol, $timeframe);

        if (count($candles) < self::MIN_CANDLES_PER_TIMEFRAME) {
            return $this->insufficientDataResult();
        }

        $closes = array_map(static fn (array $c) => (float) $c['close'], $candles);

        $ema20 = Indicators::ema($closes, self::EMA_FAST);
        $ema50 = Indicators::ema($closes, self::EMA_MID);
        $ema200 = Indicators::ema($closes, self::EMA_SLOW);
        $rsi = Indicators::rsi($closes, self::RSI_PERIOD);
        $atr = Indicators::atr($candles, self::ATR_PERIOD);
        $adxData = Indicators::adxWithDi($candles, self::ADX_PERIOD) ?? ['adx' => null, 'plus_di' => null, 'minus_di' => null];

        $trendDirection = 'transition';
        if ($ema20 !== null && $ema50 !== null && $ema200 !== null) {
            if ($ema20 > $ema50 && $ema50 > $ema200) {
                $trendDirection = 'bullish';
            } elseif ($ema20 < $ema50 && $ema50 < $ema200) {
                $trendDirection = 'bearish';
            }
        }

        $diBias = 'neutral';
        if ($adxData['plus_di'] !== null && $adxData['minus_di'] !== null) {
            $diBias = $adxData['plus_di'] > $adxData['minus_di'] ? 'bullish' : 'bearish';
        }

        // Combine EMA-stack + DI direction into one Trend-group score
        // (both are trend indicators; combining them avoids the Trend
        // group internally double-counting two directional reads as if
        // they were independent signals).
        $trendScore = $this->directionToScore($trendDirection);
        $diScore = $diBias === 'bullish' ? 1.0 : ($diBias === 'bearish' ? -1.0 : 0.0);
        $trendCombined = $trendDirection === 'transition' ? ($diScore * 0.5) : (($trendScore + $diScore) / 2);

        $momentumBias = 'neutral';
        if ($rsi !== null) {
            if ($rsi >= self::RSI_BULLISH_THRESHOLD) {
                $momentumBias = 'bullish';
            } elseif ($rsi <= self::RSI_BEARISH_THRESHOLD) {
                $momentumBias = 'bearish';
            }
        }
        $momentumScore = $this->directionToScore($momentumBias);

        $priceAction = $this->classifyPriceAction($candles);
        $priceActionScore = $this->directionToScore($priceAction['bias']);

        $lastClose = (float) end($closes);
        $volatilityState = 'normal';
        if ($atr !== null && $lastClose > 0 && ($atr / $lastClose) > self::ATR_ELEVATED_RATIO) {
            $volatilityState = 'elevated';
        }
        $volatilityConfidenceFactor = $volatilityState === 'elevated' ? self::VOLATILITY_ELEVATED_CONFIDENCE_FACTOR : 1.0;

        $directionalScore = ($trendCombined * self::WEIGHT_TREND)
            + ($momentumScore * self::WEIGHT_MOMENTUM)
            + ($priceActionScore * self::WEIGHT_PRICE_ACTION);

        $rawConfidence = (abs($trendCombined) * self::WEIGHT_TREND)
            + (abs($momentumScore) * self::WEIGHT_MOMENTUM)
            + (abs($priceActionScore) * self::WEIGHT_PRICE_ACTION)
            + (self::WEIGHT_VOLATILITY * $volatilityConfidenceFactor);

        return [
            'sufficient_data' => true,
            'score' => $directionalScore,
            'confidence' => min(1.0, $rawConfidence),
            'trend' => ['direction' => $trendDirection, 'ema20' => $ema20, 'ema50' => $ema50, 'ema200' => $ema200],
            'momentum' => ['rsi' => $rsi, 'bias' => $momentumBias],
            'volatility' => ['atr' => $atr, 'state' => $volatilityState],
            'price_action' => $priceAction,
            'adx' => [
                'adx' => $adxData['adx'],
                'plus_di' => $adxData['plus_di'],
                'minus_di' => $adxData['minus_di'],
                'strength' => $adxData['adx'] !== null && $adxData['adx'] >= 25.0 ? 'strong' : 'weak',
                'di_bias' => $diBias,
            ],
        ];
    }

    private function insufficientDataResult(): array
    {
        return [
            'sufficient_data' => false,
            'score' => 0.0,
            'confidence' => 0.0,
            'trend' => ['direction' => 'insufficient_data', 'ema20' => null, 'ema50' => null, 'ema200' => null],
            'momentum' => ['rsi' => null, 'bias' => 'neutral'],
            'volatility' => ['atr' => null, 'state' => 'unknown'],
            'price_action' => ['pattern' => 'insufficient_data', 'bias' => 'neutral'],
            'adx' => ['adx' => null, 'plus_di' => null, 'minus_di' => null, 'strength' => 'unknown', 'di_bias' => 'neutral'],
        ];
    }

    private function directionToScore(string $bias): float
    {
        return match ($bias) {
            'bullish' => 1.0,
            'bearish' => -1.0,
            default => 0.0,
        };
    }

    /**
     * Simple closed-candle price-action classification -- engulfing /
     * rejection-wick / doji, using only the final two closed candles. No
     * existing helper for this was found in the codebase (per the plan's
     * §4), so this is small and new.
     */
    private function classifyPriceAction(array $candles): array
    {
        $count = count($candles);
        $current = $candles[$count - 1];
        $previous = $candles[$count - 2];

        $open = (float) $current['open'];
        $close = (float) $current['close'];
        $high = (float) $current['high'];
        $low = (float) $current['low'];
        $range = $high - $low;
        $body = abs($close - $open);

        if ($range > 0 && $body <= (self::DOJI_BODY_TO_RANGE_RATIO * $range)) {
            return ['pattern' => 'doji', 'bias' => 'neutral'];
        }

        $prevOpen = (float) $previous['open'];
        $prevClose = (float) $previous['close'];
        $prevBearish = $prevClose < $prevOpen;
        $prevBullish = $prevClose > $prevOpen;
        $currBullish = $close > $open;
        $currBearish = $close < $open;

        if ($prevBearish && $currBullish && $open <= $prevClose && $close >= $prevOpen) {
            return ['pattern' => 'bullish_engulfing', 'bias' => 'bullish'];
        }
        if ($prevBullish && $currBearish && $open >= $prevClose && $close <= $prevOpen) {
            return ['pattern' => 'bearish_engulfing', 'bias' => 'bearish'];
        }

        $upperWick = $high - max($open, $close);
        $lowerWick = min($open, $close) - $low;

        if ($body > 0 && $lowerWick >= (self::REJECTION_WICK_TO_BODY_RATIO * $body) && $lowerWick > $upperWick) {
            return ['pattern' => 'rejection_wick_bullish', 'bias' => 'bullish'];
        }
        if ($body > 0 && $upperWick >= (self::REJECTION_WICK_TO_BODY_RATIO * $body) && $upperWick > $lowerWick) {
            return ['pattern' => 'rejection_wick_bearish', 'bias' => 'bearish'];
        }

        return ['pattern' => 'none', 'bias' => 'neutral'];
    }

    /**
     * @return array<int, array{open: float, high: float, low: float, close: float}>
     */
    private function closedCandles(string $exchange, string $symbol, string $timeframe): array
    {
        $minutes = self::TIMEFRAME_MINUTES[$timeframe] ?? null;
        if ($minutes === null) {
            return [];
        }

        $cutoff = Carbon::now()->subMinutes($minutes);

        return MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('ts', '<=', $cutoff)
            ->orderBy('ts')
            ->get(['open', 'high', 'low', 'close'])
            ->map(static fn ($row) => [
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
            ])
            ->all();
    }

    private function buildFeatureSummary(array $perTimeframe): array
    {
        $summary = [];
        foreach ($perTimeframe as $timeframe => $tf) {
            $summary["{$timeframe}_adx"] = $tf['adx']['adx'];
            $summary["{$timeframe}_rsi"] = $tf['momentum']['rsi'];
            $summary["{$timeframe}_atr"] = $tf['volatility']['atr'];
            $summary["{$timeframe}_trend"] = $tf['trend']['direction'];
        }

        return $summary;
    }
}
