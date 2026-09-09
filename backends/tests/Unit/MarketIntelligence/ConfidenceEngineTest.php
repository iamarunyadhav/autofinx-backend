<?php

namespace Tests\Unit\MarketIntelligence;

use App\Domain\MarketIntelligence\ConfidenceEngine;
use App\Domain\MarketIntelligence\MarketAnalysisSnapshot;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves ConfidenceEngine::scoreConfidence() produces the same
 * favoredDirection/contribution values as
 * RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting() for
 * equivalent inputs -- no drift between the extracted and original math.
 * Scenarios mirror tests\Feature\RatioBot\AnchorDirectionReliabilityWeightingTest
 * exactly (same composite_score / hit-rate pairs).
 */
class ConfidenceEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_backtest_record_means_trend_wins_with_full_trend_weight(): void
    {
        $config = $this->makeBot();
        $snapshot = $this->snapshotFor(-0.5);

        $result = app(ConfidenceEngine::class)->scoreConfidence($snapshot, $config, 60);

        // Mirrors AnchorDirectionReliabilityWeightingTest::test_no_backtest_record_at_all_means_the_trend_always_wins_on_disagreement
        $this->assertSame('trend', $result->favoredDirection);
        $this->assertNull($result->hitRatePct);
        $this->assertSame(0.0, $result->forecastWeight);
        $this->assertEqualsWithDelta(0.5 * 1.0, $result->trendContribution, 0.0001);
        $this->assertEqualsWithDelta(1.0 * 0.0, $result->forecastContribution, 0.0001);
    }

    public function test_a_sufficiently_reliable_forecast_can_outweigh_a_weak_trend(): void
    {
        $config = $this->makeBot();
        RatioBotForecastBacktest::create([
            'ratio_bot_config_id' => $config->id,
            'horizon_seconds' => 60,
            'sample_size' => 100,
            'hits' => 85,
            'hit_rate_pct' => 85.0,
            'avg_confidence_pct' => 70.0,
            'computed_at' => now(),
        ]);
        $snapshot = $this->snapshotFor(-0.2);

        $result = app(ConfidenceEngine::class)->scoreConfidence($snapshot, $config, 60);

        // Mirrors AnchorDirectionReliabilityWeightingTest::test_a_sufficiently_reliable_forecast_can_outweigh_a_weak_trend
        // trendContribution = 0.2*0.70=0.14, forecastContribution = 1.0*0.30=0.30 -> forecast wins.
        $this->assertSame('forecast', $result->favoredDirection);
        $this->assertSame(85.0, $result->hitRatePct);
        $this->assertSame(100, $result->sampleSize);
        $this->assertEqualsWithDelta(0.30, $result->forecastWeight, 0.0001);
        $this->assertEqualsWithDelta(0.14, $result->trendContribution, 0.0001);
        $this->assertEqualsWithDelta(0.30, $result->forecastContribution, 0.0001);
    }

    public function test_a_tie_favors_trend(): void
    {
        $config = $this->makeBot();
        // hit_rate_pct <= 50 -> weight 0.0, trendWeight = 1.0. A composite of
        // 0.0 makes both contributions exactly 0.0 -- tie favors trend (>=).
        RatioBotForecastBacktest::create([
            'ratio_bot_config_id' => $config->id,
            'horizon_seconds' => 60,
            'sample_size' => 10,
            'hits' => 5,
            'hit_rate_pct' => 50.0,
            'avg_confidence_pct' => 50.0,
            'computed_at' => now(),
        ]);
        $snapshot = $this->snapshotFor(0.0);

        $result = app(ConfidenceEngine::class)->scoreConfidence($snapshot, $config, 60);

        $this->assertSame('trend', $result->favoredDirection);
        $this->assertEqualsWithDelta(0.0, $result->trendContribution, 0.0001);
        $this->assertEqualsWithDelta(0.0, $result->forecastContribution, 0.0001);
    }

    public function test_basis_and_disclaimer_are_always_populated(): void
    {
        $config = $this->makeBot();
        $snapshot = $this->snapshotFor(0.4);

        $result = app(ConfidenceEngine::class)->scoreConfidence($snapshot, $config, 60);

        $this->assertSame('statistical_calibration', $result->basis);
        $this->assertNotEmpty($result->disclaimer);
    }

    private function snapshotFor(float $compositeScore): MarketAnalysisSnapshot
    {
        return new MarketAnalysisSnapshot(
            trendDirection: $compositeScore >= 0 ? 'bullish' : 'bearish',
            trendCompositeScore: $compositeScore,
            windowBreakdown: [],
            minComposite: 0.15,
            raw: ['composite_score' => $compositeScore],
        );
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => Workspace::factory()->create()->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ], $overrides));
    }
}
