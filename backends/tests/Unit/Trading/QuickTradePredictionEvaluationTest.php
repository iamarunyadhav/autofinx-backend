<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\QuickTradeResearchService;
use Tests\TestCase;

/**
 * Part A3 -- the two predictions are stored separately at entry and
 * evaluated separately after settlement, and the aggregate audit metrics are
 * computed from them.
 *
 * The convention under test throughout: unknown data is EXCLUDED, never
 * counted as false; a NEUTRAL direction is `not_applicable`, never a miss.
 */
class QuickTradePredictionEvaluationTest extends TestCase
{
    private QuickTradeResearchService $research;

    protected function setUp(): void
    {
        parent::setUp();
        $this->research = new QuickTradeResearchService();
        config()->set('quick_trade.bucket_min_samples', 2);
    }

    /** @return array<string,mixed> */
    private function snapshot(string $direction, string $decision): array
    {
        return [
            'primary' => [
                'available' => true,
                'bias' => $direction,
                'decision_trend' => $decision,
                'direction' => [
                    'value' => $direction, 'fee_aware' => false,
                    'expiry_score' => 0.34, 'expected_move_pct' => 0.05,
                ],
                'trade_decision' => [
                    'value' => $decision, 'fee_aware' => true,
                    'required_move_pct' => 0.28, 'max_achievable_move_pct' => 0.141,
                    'cost_pct' => 0.14, 'gate_reason' => null, 'fee_model' => 'taker',
                ],
            ],
        ];
    }

    public function test_both_predictions_are_extracted_with_their_fee_aware_flags(): void
    {
        $snapshot = $this->snapshot('BUY', 'WAIT');

        $direction = $this->research->directionPrediction($snapshot['primary']);
        $decision = $this->research->tradeDecisionPrediction($snapshot['primary']);

        $this->assertSame('BUY', $direction['value']);
        $this->assertFalse($direction['fee_aware']);
        $this->assertSame(0.34, $direction['expiry_score']);

        $this->assertSame('WAIT', $decision['value']);
        $this->assertTrue($decision['fee_aware']);
        $this->assertSame(0.28, $decision['required_move_pct']);
        $this->assertSame(0.141, $decision['max_achievable_move_pct']);
    }

    public function test_legacy_snapshots_without_the_new_blocks_still_evaluate(): void
    {
        // A snapshot captured before Part A landed: only bias/decision_trend.
        $legacy = ['primary' => ['available' => true, 'bias' => 'SELL', 'decision_trend' => 'SELL']];

        $this->assertSame('SELL', $this->research->directionPrediction($legacy['primary'])['value']);
        $this->assertSame('SELL', $this->research->tradeDecisionPrediction($legacy['primary'])['value']);
    }

    public function test_direction_and_trade_decision_are_evaluated_separately(): void
    {
        // Direction was right (price rose), but the trade still lost money
        // after fees -- the single most important case in the whole spec (F7).
        $result = $this->research->evaluatePredictions(
            $this->snapshot('BUY', 'BUY'),
            entryPrice: 100.0, exitPrice: 100.5, netPnl: -0.42, closed: true,
        );

        $this->assertTrue($result['direction_correct']);
        $this->assertSame('evaluated', $result['direction_status']);
        $this->assertFalse($result['trade_decision_correct']);
        $this->assertSame('evaluated', $result['trade_decision_status']);
    }

    public function test_a_neutral_direction_is_not_applicable_never_false(): void
    {
        $result = $this->research->evaluatePredictions(
            $this->snapshot('NEUTRAL', 'WAIT'),
            entryPrice: 100.0, exitPrice: 90.0, netPnl: -5.0, closed: true,
        );

        $this->assertNull($result['direction_correct']);
        $this->assertSame('not_applicable', $result['direction_status']);
    }

    public function test_a_wait_decision_is_never_scored_as_a_pass_or_fail(): void
    {
        $result = $this->research->evaluatePredictions(
            $this->snapshot('BUY', 'WAIT'),
            entryPrice: 100.0, exitPrice: 105.0, netPnl: 4.0, closed: true,
        );

        $this->assertNull($result['trade_decision_correct']);
        $this->assertSame('not_applicable_wait', $result['trade_decision_status']);
        // The direction is still judged on its own merits.
        $this->assertTrue($result['direction_correct']);
    }

    public function test_an_open_trade_evaluates_to_unavailable_not_false(): void
    {
        $result = $this->research->evaluatePredictions(
            $this->snapshot('BUY', 'BUY'),
            entryPrice: 100.0, exitPrice: null, netPnl: null, closed: false,
        );

        $this->assertNull($result['direction_correct']);
        $this->assertSame('unavailable', $result['direction_status']);
        $this->assertNull($result['trade_decision_correct']);
        $this->assertSame('unavailable', $result['trade_decision_status']);
    }

    // ---- audit_summary aggregates ---------------------------------------

    public function test_audit_summary_computes_the_four_spec_metrics(): void
    {
        $rows = [
            // direction right, decision right
            ['direction_evaluation_status' => 'evaluated', 'direction_correct' => true,
             'trade_decision_evaluation_status' => 'evaluated', 'trade_decision_correct' => true,
             'wait_shadow' => null, 'manual_override' => false],
            // direction right, decision wrong (ate the fees)
            ['direction_evaluation_status' => 'evaluated', 'direction_correct' => true,
             'trade_decision_evaluation_status' => 'evaluated', 'trade_decision_correct' => false,
             'wait_shadow' => null, 'manual_override' => true],
            // direction wrong
            ['direction_evaluation_status' => 'evaluated', 'direction_correct' => false,
             'trade_decision_evaluation_status' => 'evaluated', 'trade_decision_correct' => false,
             'wait_shadow' => null, 'manual_override' => false],
            // NEUTRAL -- excluded from the denominator entirely
            ['direction_evaluation_status' => 'not_applicable', 'direction_correct' => null,
             'trade_decision_evaluation_status' => 'not_applicable_wait', 'trade_decision_correct' => null,
             'wait_shadow' => null, 'manual_override' => false],
            // a WAIT that saved money
            ['direction_evaluation_status' => 'unavailable', 'direction_correct' => null,
             'trade_decision_evaluation_status' => 'not_applicable_wait', 'trade_decision_correct' => null,
             'wait_shadow' => ['status' => 'evaluated', 'trade_decision' => 'WAIT',
                 'wait_saved_usdt' => 1.25, 'wait_missed_usdt' => 0.0], 'manual_override' => false],
            // a WAIT that missed money
            ['direction_evaluation_status' => 'unavailable', 'direction_correct' => null,
             'trade_decision_evaluation_status' => 'not_applicable_wait', 'trade_decision_correct' => null,
             'wait_shadow' => ['status' => 'evaluated', 'trade_decision' => 'WAIT',
                 'wait_saved_usdt' => 0.0, 'wait_missed_usdt' => 0.4], 'manual_override' => false],
        ];

        $summary = $this->research->auditSummary($rows);

        $this->assertSame(6, $summary['trade_count']);
        // 3 evaluated directions, 2 correct -> 66.67%. NEUTRAL excluded.
        $this->assertSame(3, $summary['direction_evaluated']);
        $this->assertSame(1, $summary['direction_not_applicable']);
        $this->assertEqualsWithDelta(66.67, $summary['direction_accuracy_pct'], 0.01);
        // 3 evaluated BUY/SELL decisions, 1 profitable -> 33.33%.
        $this->assertSame(3, $summary['trade_decision_evaluated']);
        $this->assertEqualsWithDelta(33.33, $summary['trade_decision_accuracy_pct'], 0.01);
        // WAIT shadow totals.
        $this->assertSame(2, $summary['wait_decisions_evaluated']);
        $this->assertEqualsWithDelta(1.25, $summary['wait_decision_saved_usdt'], 1e-8);
        $this->assertEqualsWithDelta(0.4, $summary['wait_decision_missed_usdt'], 1e-8);
        $this->assertEqualsWithDelta(0.85, $summary['wait_decision_net_benefit_usdt'], 1e-8);
        $this->assertSame(1, $summary['manual_override_count']);
    }

    public function test_audit_summary_reports_insufficient_data_rather_than_a_percentage_off_one_trade(): void
    {
        config()->set('quick_trade.bucket_min_samples', 30);

        $summary = $this->research->auditSummary([
            ['direction_evaluation_status' => 'evaluated', 'direction_correct' => true,
             'trade_decision_evaluation_status' => 'evaluated', 'trade_decision_correct' => true,
             'wait_shadow' => null, 'manual_override' => false],
        ]);

        $this->assertNull($summary['direction_accuracy_pct']);
        $this->assertNull($summary['trade_decision_accuracy_pct']);
        $this->assertSame('insufficient_data', $summary['status']);
        // The raw counts are still there -- honest, just not a percentage.
        $this->assertSame(1, $summary['direction_evaluated']);
        $this->assertSame(1, $summary['direction_correct_count']);
    }
}
