<?php

namespace App\Domain\Pepperstone;

use App\Domain\Market\Indicators\Indicators;
use App\Models\Instrument;

/**
 * Classifies current market conditions for one Pepperstone instrument into
 * one of TRENDING|RANGING|VOLATILE|CHAOTIC|LOW_LIQUIDITY, per the approved
 * plan's §5. Gates both MTFConfirmationEngine's directional trust and the
 * basket engine's entry/exposure behavior (RiskEngine, BasketExitEngine).
 *
 * Reuses Indicators::atr()/adxWithDi() only -- no new indicator math, per
 * the plan's explicit instruction.
 */
class MarketRegimeDetector
{
    public const REGIME_TRENDING = 'TRENDING';
    public const REGIME_RANGING = 'RANGING';
    public const REGIME_VOLATILE = 'VOLATILE';
    public const REGIME_CHAOTIC = 'CHAOTIC';
    public const REGIME_LOW_LIQUIDITY = 'LOW_LIQUIDITY';

    /**
     * Minimum candles required before ATR-ratio/ADX detection is even
     * attempted. Below this, there simply isn't enough history to trust a
     * volatility-ratio or trend read -- RANGING is returned as the
     * conservative, non-alarming, non-falsely-confident default (mirrors
     * MTFConfirmationEngine's own "insufficient data -> neutral" rule).
     */
    private const MIN_CANDLES = 70;

    /**
     * The most recent WINDOW_SIZE candles form the "current" ATR window;
     * the WINDOW_SIZE candles immediately preceding them (back-to-back, no
     * overlap) form the "baseline" ATR window. Chosen as a fixed,
     * documented split rather than a statistically derived one --
     * consistent with this codebase's existing discipline of explicit,
     * commented threshold constants (e.g. TradingCostCalculator's
     * cost-clearance margin). RECENT_WINDOW is the decision-relevant
     * candle count; ATR_PERIOD+1 is Indicators::atr()'s own warm-up
     * requirement, included in each window's size so both windows are
     * independently computable AND never share a single candle.
     */
    private const RECENT_WINDOW = 20;
    private const ATR_PERIOD = 14;
    private const ATR_WINDOW_SIZE = self::RECENT_WINDOW + self::ATR_PERIOD + 1;

    /** Recent ATR >= this multiple of baseline ATR => VOLATILE. */
    private const VOLATILE_ATR_MULTIPLIER = 2.0;

    /** Recent ATR >= this multiple of baseline ATR => CHAOTIC (severe). */
    private const CHAOTIC_ATR_MULTIPLIER = 3.5;

    private const TRENDING_ADX_THRESHOLD = 25.0;

    /**
     * Current spread >= this multiple of the recent rolling median spread
     * => LOW_LIQUIDITY. Only evaluated when at least 5 recent spread
     * readings are supplied (a single reading has no meaningful median).
     */
    private const LOW_LIQUIDITY_SPREAD_MULTIPLIER = 3.0;
    private const MIN_SPREAD_SAMPLES_FOR_MEDIAN = 5;

    /**
     * @param  array  $quote  ['spread_pct' => float, ...] shape from
     *                        TradingVenueClientInterface::getLatestPrice().
     * @param  array  $recentCandles  Closed OHLC candles, ascending time,
     *                        ['open','high','low','close'] shape.
     * @param  array  $recentSpreadPercents  Recent historical spread_pct
     *                        readings for this instrument (rolling stat).
     *                        Empty array = check skipped (no baseline).
     */
    public function detect(Instrument $instrument, array $quote, array $recentCandles, array $recentSpreadPercents = []): string
    {
        if ($this->isLowLiquidity($quote, $recentSpreadPercents)) {
            return self::REGIME_LOW_LIQUIDITY;
        }

        if (count($recentCandles) < self::MIN_CANDLES) {
            return self::REGIME_RANGING;
        }

        $volatilityRegime = $this->detectVolatilityRegime($recentCandles);
        if ($volatilityRegime !== null) {
            return $volatilityRegime;
        }

        $adxData = Indicators::adxWithDi($recentCandles, self::ATR_PERIOD);
        if ($adxData !== null && $adxData['adx'] >= self::TRENDING_ADX_THRESHOLD) {
            return self::REGIME_TRENDING;
        }

        return self::REGIME_RANGING;
    }

    private function isLowLiquidity(array $quote, array $recentSpreadPercents): bool
    {
        $currentSpread = $quote['spread_pct'] ?? null;
        if ($currentSpread === null || count($recentSpreadPercents) < self::MIN_SPREAD_SAMPLES_FOR_MEDIAN) {
            return false;
        }

        $sorted = $recentSpreadPercents;
        sort($sorted);
        $middle = (int) floor(count($sorted) / 2);
        $median = count($sorted) % 2 === 0
            ? (($sorted[$middle - 1] + $sorted[$middle]) / 2)
            : $sorted[$middle];

        if ($median <= 0) {
            return false;
        }

        return (float) $currentSpread >= $median * self::LOW_LIQUIDITY_SPREAD_MULTIPLIER;
    }

    private function detectVolatilityRegime(array $recentCandles): ?string
    {
        $needed = self::ATR_WINDOW_SIZE * 2;
        if (count($recentCandles) < $needed) {
            return null;
        }

        // Two back-to-back, non-overlapping windows: the last
        // ATR_WINDOW_SIZE candles ("recent") and the ATR_WINDOW_SIZE
        // candles immediately before them ("baseline").
        $recentSlice = array_slice($recentCandles, -self::ATR_WINDOW_SIZE);
        $baselineSlice = array_slice($recentCandles, -(self::ATR_WINDOW_SIZE * 2), self::ATR_WINDOW_SIZE);

        $recentAtr = Indicators::atr($recentSlice, self::ATR_PERIOD);
        $baselineAtr = Indicators::atr($baselineSlice, self::ATR_PERIOD);

        if ($recentAtr === null || $baselineAtr === null || $baselineAtr <= 0) {
            return null;
        }

        $ratio = $recentAtr / $baselineAtr;

        if ($ratio >= self::CHAOTIC_ATR_MULTIPLIER) {
            return self::REGIME_CHAOTIC;
        }

        if ($ratio >= self::VOLATILE_ATR_MULTIPLIER) {
            return self::REGIME_VOLATILE;
        }

        return null;
    }
}
