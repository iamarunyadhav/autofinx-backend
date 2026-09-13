<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\MarketRegimeDetector;
use App\Models\Instrument;
use Tests\TestCase;

class MarketRegimeDetectorTest extends TestCase
{
    private function instrument(): Instrument
    {
        return new Instrument([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);
    }

    /**
     * @param  float[]  $closes
     * @param  float  $range  Fixed high-low range applied to every candle (keeps ATR stable/comparable).
     */
    private function candlesFrom(array $closes, float $range): array
    {
        $candles = [];
        foreach ($closes as $close) {
            $candles[] = [
                'open' => $close,
                'high' => $close + ($range / 2),
                'low' => $close - ($range / 2),
                'close' => $close,
            ];
        }

        return $candles;
    }

    /** @test */
    public function reports_ranging_when_there_is_insufficient_candle_history(): void
    {
        $detector = new MarketRegimeDetector();
        $candles = $this->candlesFrom(array_fill(0, 10, 100.0), 1.0);

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.01], $candles);

        $this->assertSame(MarketRegimeDetector::REGIME_RANGING, $regime);
    }

    /** @test */
    public function reports_low_liquidity_when_current_spread_far_exceeds_the_recent_median(): void
    {
        $detector = new MarketRegimeDetector();
        $candles = $this->candlesFrom(array_fill(0, 80, 100.0), 1.0);
        $recentSpreads = [0.01, 0.011, 0.009, 0.01, 0.012];

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.5], $candles, $recentSpreads);

        $this->assertSame(MarketRegimeDetector::REGIME_LOW_LIQUIDITY, $regime);
    }

    /** @test */
    public function reports_trending_for_a_strong_consistent_uptrend_with_stable_volatility(): void
    {
        $detector = new MarketRegimeDetector();

        $closes = [];
        $price = 100.0;
        foreach (range(1, 100) as $i) {
            $price += 1.0; // strong, consistent one-directional move every candle
            $closes[] = $price;
        }
        $candles = $this->candlesFrom($closes, 1.0); // fixed range -> stable ATR (ratio ~1)

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.01], $candles);

        $this->assertSame(MarketRegimeDetector::REGIME_TRENDING, $regime);
    }

    /** @test */
    public function reports_ranging_for_a_flat_oscillating_series_with_stable_volatility(): void
    {
        $detector = new MarketRegimeDetector();

        $closes = [];
        foreach (range(1, 100) as $i) {
            $closes[] = 100.0 + (($i % 2 === 0) ? 0.2 : -0.2); // tight back-and-forth, no net direction
        }
        $candles = $this->candlesFrom($closes, 1.0);

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.01], $candles);

        $this->assertSame(MarketRegimeDetector::REGIME_RANGING, $regime);
    }

    /**
     * Detector's ATR windows are, from the end of the array: a 35-candle
     * "recent" slice, then (immediately before it) a 35-candle "baseline"
     * slice. 60 baseline candles + exactly 35 recent candles keeps each
     * slice purely inside its own fixed-range region (no overlap), so the
     * resulting ATR ratio is exactly baseline-range vs recent-range.
     */
    /** @test */
    public function reports_volatile_when_recent_atr_is_moderately_elevated_over_baseline(): void
    {
        $detector = new MarketRegimeDetector();

        $baseline = $this->candlesFrom(array_fill(0, 60, 100.0), 1.0);
        $recent = $this->candlesFrom(array_fill(0, 35, 100.0), 2.6);
        $candles = array_merge($baseline, $recent);

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.01], $candles);

        $this->assertSame(MarketRegimeDetector::REGIME_VOLATILE, $regime);
    }

    /** @test */
    public function reports_chaotic_when_recent_atr_severely_exceeds_baseline(): void
    {
        $detector = new MarketRegimeDetector();

        $baseline = $this->candlesFrom(array_fill(0, 60, 100.0), 1.0);
        $recent = $this->candlesFrom(array_fill(0, 35, 100.0), 10.0);
        $candles = array_merge($baseline, $recent);

        $regime = $detector->detect($this->instrument(), ['spread_pct' => 0.01], $candles);

        $this->assertSame(MarketRegimeDetector::REGIME_CHAOTIC, $regime);
    }
}
