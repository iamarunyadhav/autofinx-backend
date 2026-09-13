<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\RootCauseCategory;
use App\Domain\AuditResearch\RuleEvaluationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises RuleEvaluationRecorder::recordForAutoStartContext() with the
 * exact context-array shape RunRatioBotEvaluationJob::resolveAutoStartDirection()
 * really builds (see that method's own RatioBotEvent::create() call) — a
 * confidence block and a wallet-aware-direction-fallback block never create
 * a RatioBotDecision row (they're pre-anchor posture decisions), so this is
 * the correct level to test their classification.
 */
class RuleEvaluationRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit_research.enabled' => true]);
    }

    /** @test */
    public function a_confidence_block_is_classified_as_a_strategy_rule(): void
    {
        $context = [
            'direction' => null,
            'reason' => 'confidence 40 below required 60',
            'forecast_direction' => 'bullish',
            'graph_direction' => 'bullish',
            'confidence' => 40.0,
            'min_confidence' => 60.0,
            'has_base' => false,
            'has_quote' => true,
            'wallet_fallback' => null,
        ];

        $snapshot = RuleEvaluationRecorder::recordForAutoStartContext($context);

        $this->assertNotNull($snapshot);
        $this->assertSame(RootCauseCategory::STRATEGY_RULE, $snapshot->diagnosis_category);
        $this->assertSame('auto_start_min_confidence', $snapshot->failed_rules[0]['rule']);
        $this->assertEmpty($snapshot->passed_rules);
    }

    /** @test */
    public function a_wallet_fallback_block_is_classified_as_balance_insufficient(): void
    {
        $context = [
            'direction' => null,
            'reason' => 'bullish auto-start needs base-asset balance for SELL-watch',
            'forecast_direction' => 'bullish',
            'graph_direction' => 'bullish',
            'confidence' => 80.0,
            'min_confidence' => 60.0,
            'has_base' => false,
            'has_quote' => true,
            'wallet_fallback' => ['action' => 'blocked', 'reason' => 'insufficient base balance'],
        ];

        $snapshot = RuleEvaluationRecorder::recordForAutoStartContext($context);

        $this->assertNotNull($snapshot);
        $this->assertSame(RootCauseCategory::BALANCE_INSUFFICIENT, $snapshot->diagnosis_category);
        $this->assertSame('wallet_aware_direction_fallback', $snapshot->failed_rules[0]['rule']);
    }

    /** @test */
    public function a_wallet_fallback_flip_still_counts_as_a_resolved_direction(): void
    {
        $context = [
            'direction' => 'BUY',
            'reason' => null,
            'forecast_direction' => 'bearish',
            'graph_direction' => 'bearish',
            'confidence' => 80.0,
            'min_confidence' => 60.0,
            'has_base' => true,
            'has_quote' => false,
            'wallet_fallback' => ['action' => 'flip', 'new_direction' => 'BUY'],
        ];

        $snapshot = RuleEvaluationRecorder::recordForAutoStartContext($context);

        $this->assertNotNull($snapshot);
        $this->assertSame(RootCauseCategory::CORRECT_BEHAVIOR, $snapshot->diagnosis_category);
        $this->assertSame('BUY', $snapshot->final_decision);
    }

    /** @test */
    public function disabled_flag_makes_recording_a_true_no_op(): void
    {
        config(['audit_research.enabled' => false]);

        $snapshot = RuleEvaluationRecorder::recordForAutoStartContext([
            'direction' => null,
            'reason' => 'confidence 40 below required 60',
        ]);

        $this->assertNull($snapshot);
        $this->assertSame(0, \App\Models\AuditDecisionSnapshot::count());
    }
}
