<?php

namespace Tests\Feature\AuditResearch;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A real Ratio Bot decision tick (sandbox mode — real code running, not
 * fabricated data) must produce real audit_events/audit_decision_snapshots
 * rows with the correct correlation_id matching the real RatioBotDecision
 * row created for the same tick. Exercises RatioBotDecisionService::evaluate()
 * and ::block() directly, exactly like RatioBotDecisionServiceTest already
 * does for the trading-behavior assertions this test does NOT duplicate.
 */
class RatioBotAuditInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit_research.enabled' => true]);
        $this->service = new RatioBotDecisionService();
    }

    /** @test */
    public function a_normal_buy_tick_produces_a_matching_audit_event_and_passed_rules_snapshot(): void
    {
        [$config] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        $result = $this->service->evaluate($config, 72_000.0);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $decision = $result['decision_record'];
        $this->assertNotEmpty($decision->correlation_id);

        $event = AuditEvent::where('correlation_id', $decision->correlation_id)->first();
        $this->assertNotNull($event, 'expected an audit_event row for this real decision tick');
        $this->assertSame('decision', $event->event_category);
        $this->assertSame((string) $config->id, (string) $event->bot_instance_id);

        $snapshot = AuditDecisionSnapshot::where('ratio_bot_decision_id', $decision->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame($decision->correlation_id, $snapshot->correlation_id);
        $this->assertSame('BUY', $snapshot->final_decision);
        $this->assertNotEmpty($snapshot->passed_rules);
        $this->assertEmpty($snapshot->failed_rules ?? []);
        $this->assertSame('CORRECT_BEHAVIOR', $snapshot->diagnosis_category);
    }

    /** @test */
    public function a_normal_hold_tick_produces_a_failed_rules_snapshot(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);

        $result = $this->service->evaluate($config, 89_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $decision = $result['decision_record'];

        $snapshot = AuditDecisionSnapshot::where('ratio_bot_decision_id', $decision->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('HOLD', $snapshot->final_decision);
        $this->assertEmpty($snapshot->passed_rules ?? []);
        $this->assertNotEmpty($snapshot->failed_rules);
        $this->assertSame('ratio_threshold_not_reached', $snapshot->failed_rules[0]['rule']);
    }

    /** @test */
    public function a_stale_feed_block_produces_a_data_stale_diagnosis(): void
    {
        [$config] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        $result = $this->service->block(
            $config,
            72_000.0,
            'Market-data stale-feed breaker is active.',
            ['guard' => 'exchange_health', 'reason_code' => 'STALE_FEED'],
        );

        $decision = $result['decision_record'];
        $this->assertSame(RatioBotDecision::DECISION_BLOCK, $decision->decision);

        $snapshot = AuditDecisionSnapshot::where('ratio_bot_decision_id', $decision->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('DATA_STALE', $snapshot->diagnosis_category);
        $this->assertSame('STALE_FEED', $snapshot->failed_rules[0]['rule']);

        $event = AuditEvent::where('correlation_id', $decision->correlation_id)->first();
        $this->assertNotNull($event);
        $this->assertSame('medium', $event->severity);
    }

    /** @test */
    public function audit_research_enabled_false_is_a_true_no_op_and_does_not_affect_the_real_decision(): void
    {
        config(['audit_research.enabled' => false]);

        [$config] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        $result = $this->service->evaluate($config, 72_000.0);

        // Trading behavior is completely unaffected.
        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertDatabaseHas('ratio_bot_decisions', ['id' => $result['decision_record']->id]);

        // Zero audit writes happened.
        $this->assertSame(0, AuditEvent::count());
        $this->assertSame(0, AuditDecisionSnapshot::count());
    }

    private function makeHoldingConfig(float $buyPrice, float $sellThreshold): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => $sellThreshold,
            'buy_threshold_ratio' => 0.25,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => $buyPrice,
            'last_buy_quantity' => 1.0,
            'active_anchor_price' => $buyPrice,
            'active_anchor_type' => 'buy_anchor',
        ]);
        $config->setRelation('state', $state);

        return [$config, $state];
    }

    private function makeFlatConfig(float $sellPrice, float $buyThreshold): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => $buyThreshold,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => $sellPrice,
            'last_sell_quantity' => 1.0,
            'active_anchor_price' => $sellPrice,
            'active_anchor_type' => 'sell_anchor',
        ]);
        $config->setRelation('state', $state);

        return [$config, $state];
    }
}
