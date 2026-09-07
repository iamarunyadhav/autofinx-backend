<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Engine-level wiring: RatioBrain's CONTROLLED_LOSS_EXIT recommendation
 * actually places a real (sandbox) trade once shadow_mode is explicitly
 * turned off for a bot, mirroring the same
 * assess-then-early-return-on-force-action shape RatioBotLiquidationGuardTest
 * already proves for the futures Liquidation Guard. Also covers the
 * SpotEmergencyGuard -> RatioBotRiskService block-new-buy gate, which is
 * wired through persisted state rather than a live re-assessment.
 */
class RatioBrainEngineWiringTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
        // Global kill switch -- off by default (config/autofinx.php), and a
        // per-bot ratio_brain_enabled alone is never enough to activate it
        // (see RatioBotConfig::ratioBrainEnabled()). Confirmed this actually
        // gates real behavior: without this line, every test below observed
        // RatioBrain never running at all and the normal pipeline (a plain
        // stop-loss decision) taking over instead.
        config(['autofinx.ratio_brain.enabled' => true]);
    }

    public function test_a_non_shadow_controlled_loss_exit_places_a_real_sell_via_the_engine(): void
    {
        $config = $this->makeBot([
            'mode' => 'restricted_auto',
            'ratio_brain_enabled' => true,
            'ratio_brain_shadow_mode' => false,
        ]);
        $this->seedDowntrend($config);

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_RECOVERY_MONITORING,
            'last_buy_price' => 100.0,
            'last_buy_quantity' => 1.0,
            'current_cycle_number' => 1,
        ]);

        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => 1,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 90.0,
            'original_qty' => 1.0,
            'remaining_qty' => 0.0,
            'spent_quote' => 90.0,
            'status' => RatioBotLot::STATUS_CLOSED,
            'realized_pnl' => 50.0,
            'bought_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), currentPrice: 95.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertStringContainsString('ratio_brain_controlled_loss_exit', (string) $result['decision_record']->reason);
        $this->assertTrue((bool) data_get($result['decision_record']->metadata, 'protective_exit'));
        $this->assertEqualsWithDelta(1.0, (float) data_get($result['decision_record']->metadata, 'sell_quantity'), 0.0001);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RATIO_BRAIN_CONTROLLED_LOSS_EXIT,
        ]);
    }

    public function test_shadow_mode_logs_a_recommendation_but_never_returns_it_as_the_executed_decision(): void
    {
        $config = $this->makeBot([
            'mode' => 'restricted_auto',
            'ratio_brain_enabled' => true,
            'ratio_brain_shadow_mode' => true, // explicit, though this is also the default
        ]);
        $this->seedDowntrend($config);

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_RECOVERY_MONITORING,
            'last_buy_price' => 100.0,
            'last_buy_quantity' => 1.0,
            'current_cycle_number' => 1,
        ]);

        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => 1,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 90.0,
            'original_qty' => 1.0,
            'remaining_qty' => 0.0,
            'spent_quote' => 90.0,
            'status' => RatioBotLot::STATUS_CLOSED,
            'realized_pnl' => 50.0,
            'bought_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), currentPrice: 95.0);

        // A shadow-mode recommendation must be logged (proving RatioBrain
        // actually ran and recommended the exit)...
        $this->assertDatabaseHas('ratio_brain_decision_logs', [
            'ratio_bot_config_id' => $config->id,
            'action' => 'CONTROLLED_LOSS_EXIT',
            'shadow_mode' => true,
        ]);
        // ...but must never itself be the decision the engine returned/executed.
        $this->assertStringNotContainsString('ratio_brain_controlled_loss_exit', (string) $result['decision_record']->reason);
        $this->assertDatabaseMissing('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RATIO_BRAIN_CONTROLLED_LOSS_EXIT,
        ]);
    }

    public function test_an_active_emergency_blocks_a_new_buy_but_not_a_protective_sell(): void
    {
        $config = $this->makeBot(['ratio_brain_enabled' => true]);
        $state = app(RatioBotStateService::class)->getOrCreate($config);
        app(RatioBotStateService::class)->syncEmergencyStatus($config, true);

        $risk = app(RatioBotRiskService::class);

        $buyDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $block = $risk->preTradeBlockReason($config, $buyDecision);
        $this->assertNotNull($block);
        $this->assertSame('ratio_brain_emergency_block_new_entries', $block['code']);

        $sellDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_SELL, ['protective_exit' => true]));
        $this->assertNull($risk->preTradeBlockReason($config, $sellDecision));
    }

    public function test_emergency_status_clears_once_the_flash_move_condition_normalizes(): void
    {
        $config = $this->makeBot(['ratio_brain_enabled' => true]);
        app(RatioBotStateService::class)->getOrCreate($config);
        $stateService = app(RatioBotStateService::class);

        $stateService->syncEmergencyStatus($config, true);
        $this->assertSame(RatioBotState::STATE_EMERGENCY, $config->fresh('state')->state->emergency_state);

        $stateService->syncEmergencyStatus($config, false);
        $this->assertNull($config->fresh('state')->state->emergency_state);
    }

    private function decisionAttrs(RatioBotConfig $config, string $decision, array $metadata = []): array
    {
        return [
            'ratio_bot_config_id' => $config->id,
            'decision' => $decision,
            'evaluated_price' => 95.0,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata,
            'decided_at' => now(),
        ];
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'v2_enabled' => true,
            'recovery_enabled' => true,
            'recovery_timeout_minutes' => 60,
            'enabled' => true,
        ], $overrides));
    }

    private function seedDowntrend(RatioBotConfig $config): void
    {
        $time = now()->subMinutes(51);
        $start = 110.0;
        $step = -0.3;
        for ($i = 0; $i < 50; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $open,
                'high' => max($open, $close) + 0.2,
                'low' => min($open, $close) - 0.2,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
