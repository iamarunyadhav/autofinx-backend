<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\QuickTradeAccuracyReport;
use App\Domain\Trading\QuickTradeCostModel;
use Tests\TestCase;

/**
 * Part D1/D2/D3 -- bucket math, `insufficient`/`tradeable` labelling, the
 * break-even formula, and the Brier score / calibration table.
 */
class QuickTradeAccuracyReportTest extends TestCase
{
    private QuickTradeAccuracyReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
        $this->report = new QuickTradeAccuracyReport(new QuickTradeCostModel());
    }

    /**
     * @return array<string,mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'timeframe' => '5m',
            'expiry_seconds' => 3600,
            'entry_price' => 1000.0,
            'exit_price' => 1010.0,      // 1% realised move
            'gross_pnl' => 1.0,
            'net_pnl_booked' => 0.86,
            'manual_override' => false,
            'prediction_confidence' => 81.0,
            'direction_evaluation_status' => 'evaluated',
            'direction_correct' => true,
            'trade_decision_evaluation_status' => 'evaluated',
            'trade_decision_correct' => true,
            'direction_prediction' => ['value' => 'BUY', 'expiry_score' => 0.5],
            'trade_decision_prediction' => ['value' => 'BUY', 'cost_pct' => 0.14, 'fee_model' => 'taker'],
            'research_snapshot' => ['primary' => [
                'confidence' => 70.0,
                'market_context' => ['volume_status' => 'normal'],
            ]],
        ], $overrides);
    }

    /** @return array<int, array<string,mixed>> */
    private function rows(int $count, array $overrides = []): array
    {
        return array_map(fn () => $this->row($overrides), range(1, $count));
    }

    // ---- D2: break-even formula ------------------------------------------

    public function test_break_even_accuracy_matches_the_spec_formula(): void
    {
        // (avg_abs_move + cost) / (2 * avg_abs_move)
        // (1.0 + 0.14) / 2.0 = 0.57
        $this->assertEqualsWithDelta(0.57, $this->report->breakEvenAccuracy(1.0, 0.14), 1e-9);
        // A tiny move needs a huge edge: (0.1 + 0.14) / 0.2 = 1.2 -> impossible.
        $this->assertEqualsWithDelta(1.2, $this->report->breakEvenAccuracy(0.1, 0.14), 1e-9);
        // With zero cost it collapses to a coin flip.
        $this->assertEqualsWithDelta(0.5, $this->report->breakEvenAccuracy(1.0, 0.0), 1e-9);
    }

    public function test_break_even_is_null_rather_than_a_guessed_coin_flip_without_evidence(): void
    {
        $this->assertNull($this->report->breakEvenAccuracy(null, 0.14));
        $this->assertNull($this->report->breakEvenAccuracy(0.0, 0.14));
    }

    // ---- D1: bucket sizing ------------------------------------------------

    public function test_a_bucket_below_the_minimum_sample_size_is_insufficient_with_null_percentages(): void
    {
        config()->set('quick_trade.bucket_min_samples', 30);

        $result = $this->report->generate($this->rows(5));

        $this->assertSame('insufficient', $result['overall']['status']);
        $this->assertNull($result['overall']['direction_accuracy_pct']);
        $this->assertNull($result['overall']['trade_decision_accuracy_pct']);
        // Raw counts are still reported -- honest, just not a percentage.
        $this->assertSame(5, $result['overall']['direction_evaluated']);
        $this->assertSame(5, $result['overall']['direction_correct']);
    }

    public function test_a_bucket_at_or_above_the_minimum_reports_real_percentages(): void
    {
        config()->set('quick_trade.bucket_min_samples', 4);

        $rows = array_merge(
            $this->rows(3),
            $this->rows(1, ['direction_correct' => false, 'trade_decision_correct' => false]),
        );
        $result = $this->report->generate($rows);

        $this->assertSame('observed', $result['overall']['status']);
        $this->assertEqualsWithDelta(75.0, $result['overall']['direction_accuracy_pct'], 0.01);
        $this->assertEqualsWithDelta(75.0, $result['overall']['trade_decision_accuracy_pct'], 0.01);
    }

    public function test_unknown_and_not_applicable_rows_are_excluded_never_counted_as_wrong(): void
    {
        config()->set('quick_trade.bucket_min_samples', 2);

        $rows = array_merge(
            $this->rows(2),
            $this->rows(2, ['direction_evaluation_status' => 'not_applicable', 'direction_correct' => null]),
            $this->rows(1, ['direction_evaluation_status' => 'unavailable', 'direction_correct' => null]),
        );
        $result = $this->report->generate($rows);

        $this->assertSame(5, $result['sample_size']);
        $this->assertSame(2, $result['overall']['direction_evaluated']);
        $this->assertEqualsWithDelta(100.0, $result['overall']['direction_accuracy_pct'], 0.01);
    }

    // ---- D2: tradeable labelling ------------------------------------------

    public function test_tradeable_requires_both_the_break_even_edge_and_the_sample_size(): void
    {
        config()->set('quick_trade.bucket_min_samples', 30);
        config()->set('quick_trade.tradeable_min_samples', 100);

        // 100% accuracy but only 50 samples -> not tradeable yet.
        $small = $this->report->generate($this->rows(50));
        $this->assertFalse($small['overall']['tradeable']);
        $this->assertStringContainsString('Needs 100 evaluated directions', $small['overall']['tradeable_reason']);

        // 120 samples, all correct, break-even 57% -> tradeable.
        $big = $this->report->generate($this->rows(120));
        $this->assertTrue($big['overall']['tradeable']);
        $this->assertEqualsWithDelta(57.0, $big['overall']['break_even_accuracy_pct'], 0.01);
    }

    public function test_an_accuracy_below_break_even_is_not_tradeable_however_large_the_sample(): void
    {
        config()->set('quick_trade.bucket_min_samples', 30);
        config()->set('quick_trade.tradeable_min_samples', 100);

        // 55% accuracy against a 57% break-even -> the whole point of F2/F7.
        $rows = array_merge(
            $this->rows(55),
            $this->rows(45, ['direction_correct' => false]),
        );
        $result = $this->report->generate($rows);

        $this->assertEqualsWithDelta(55.0, $result['overall']['direction_accuracy_pct'], 0.01);
        $this->assertEqualsWithDelta(57.0, $result['overall']['break_even_accuracy_pct'], 0.01);
        $this->assertFalse($result['overall']['tradeable']);
        $this->assertStringContainsString('does not exceed', $result['overall']['tradeable_reason']);
    }

    // ---- D1: grouping dimensions ------------------------------------------

    public function test_every_spec_dimension_is_grouped(): void
    {
        $result = $this->report->generate($this->rows(2));

        foreach (['timeframe', 'expiry_seconds', 'direction', 'trade_decision',
            'expiry_score_bucket', 'volume_status', 'manual_override', 'fee_model'] as $dimension) {
            $this->assertArrayHasKey($dimension, $result['buckets'], "Missing bucket dimension '{$dimension}'.");
            $this->assertNotEmpty($result['buckets'][$dimension]);
        }
    }

    public function test_expiry_score_buckets_use_the_spec_boundaries(): void
    {
        $rows = [
            $this->row(['direction_prediction' => ['value' => 'BUY', 'expiry_score' => 0.25]]),
            $this->row(['direction_prediction' => ['value' => 'BUY', 'expiry_score' => 0.55]]),
            $this->row(['direction_prediction' => ['value' => 'BUY', 'expiry_score' => 0.75]]),
            $this->row(['direction_prediction' => ['value' => 'BUY', 'expiry_score' => 0.95]]),
            $this->row(['direction_prediction' => ['value' => 'NEUTRAL', 'expiry_score' => 0.05]]),
        ];
        $labels = array_column($this->report->generate($rows)['buckets']['expiry_score_bucket'], 'bucket');

        $this->assertContains('0.2-0.4', $labels);
        $this->assertContains('0.4-0.6', $labels);
        $this->assertContains('0.6-0.8', $labels);
        $this->assertContains('0.8-1.0', $labels);
        $this->assertContains('below_0.2', $labels);
    }

    // ---- D3: Brier score + calibration table ------------------------------

    public function test_brier_score_on_a_known_fixture(): void
    {
        // Two rows: 100% confident and right (0), 100% confident and wrong (1).
        // Mean = 0.5 -- the worst possible score for confident predictions.
        $rows = [
            $this->row(['prediction_confidence' => 100.0, 'direction_correct' => true]),
            $this->row(['prediction_confidence' => 100.0, 'direction_correct' => false]),
        ];

        $reliability = $this->report->reliability($rows, 'prediction_confidence');

        $this->assertSame(2, $reliability['sample_size']);
        $this->assertEqualsWithDelta(0.5, $reliability['brier_score'], 1e-9);
        // Zero separation: the score said the same thing about both.
        $this->assertEqualsWithDelta(0.0, $reliability['separation'], 1e-9);
    }

    public function test_brier_score_rewards_a_calibrated_confident_model(): void
    {
        // 80% confident and right every time: (0.8 - 1)^2 = 0.04.
        $rows = $this->rows(10, ['prediction_confidence' => 80.0, 'direction_correct' => true]);

        $this->assertEqualsWithDelta(0.04, $this->report->reliability($rows, 'prediction_confidence')['brier_score'], 1e-9);
    }

    public function test_separation_shows_when_confidence_barely_distinguishes_wins_from_losses(): void
    {
        // Finding F6: avg confidence 75.1 on wins vs 70.8 on losses.
        $rows = array_merge(
            $this->rows(10, ['prediction_confidence' => 75.1, 'direction_correct' => true]),
            $this->rows(10, ['prediction_confidence' => 70.8, 'direction_correct' => false]),
        );

        $reliability = $this->report->reliability($rows, 'prediction_confidence');

        $this->assertEqualsWithDelta(75.1, $reliability['avg_confidence_correct'], 0.01);
        $this->assertEqualsWithDelta(70.8, $reliability['avg_confidence_incorrect'], 0.01);
        $this->assertEqualsWithDelta(4.3, $reliability['separation'], 0.01);
    }

    public function test_calibration_table_reports_observed_hit_rate_only_above_the_bucket_minimum(): void
    {
        config()->set('quick_trade.bucket_min_samples', 30);

        // 40 rows in the 80-100 bucket: 30 right, 10 wrong -> 75% observed.
        $rows = array_merge(
            $this->rows(30, ['prediction_confidence' => 85.0, 'direction_correct' => true]),
            $this->rows(10, ['prediction_confidence' => 85.0, 'direction_correct' => false]),
            // 5 rows in 60-80 -- below the minimum, stays uncalibrated.
            $this->rows(5, ['prediction_confidence' => 65.0, 'direction_correct' => true]),
        );

        $table = collect($this->report->reliability($rows, 'prediction_confidence')['calibration'])->keyBy('bucket');

        $this->assertSame(40, $table['80-100']['n']);
        $this->assertTrue($table['80-100']['calibrated']);
        $this->assertEqualsWithDelta(75.0, $table['80-100']['observed_hit_rate_pct'], 0.01);

        $this->assertSame(5, $table['60-80']['n']);
        $this->assertFalse($table['60-80']['calibrated']);
        $this->assertNull($table['60-80']['observed_hit_rate_pct']);
        // The raw number is still available for inspection, just not published
        // as a calibrated hit rate.
        $this->assertEqualsWithDelta(100.0, $table['60-80']['raw_hit_rate_pct'], 0.01);
    }

    public function test_calibrated_flag_needs_the_calibration_minimum_not_just_the_bucket_minimum(): void
    {
        config()->set('quick_trade.calibration_min_samples', 150);

        $this->assertFalse($this->report->reliability($this->rows(100), 'prediction_confidence')['calibrated']);
        $this->assertTrue($this->report->reliability($this->rows(150), 'prediction_confidence')['calibrated']);
    }

    public function test_both_confidence_sources_are_reported_separately(): void
    {
        $result = $this->report->generate($this->rows(10));

        $this->assertArrayHasKey('indicator_vote', $result['confidence_reliability']);
        $this->assertArrayHasKey('forecast_confidence', $result['confidence_reliability']);
        // Different sources, different numbers -- never merged into one score.
        $this->assertNotSame(
            $result['confidence_reliability']['indicator_vote']['brier_score'],
            $result['confidence_reliability']['forecast_confidence']['brier_score'],
        );
    }

    public function test_no_evaluable_rows_reports_insufficient_rather_than_a_fabricated_score(): void
    {
        $reliability = $this->report->reliability(
            $this->rows(10, ['direction_evaluation_status' => 'unavailable', 'direction_correct' => null]),
            'prediction_confidence',
        );

        $this->assertSame(0, $reliability['sample_size']);
        $this->assertSame('insufficient', $reliability['status']);
        $this->assertNull($reliability['brier_score']);
    }
}
