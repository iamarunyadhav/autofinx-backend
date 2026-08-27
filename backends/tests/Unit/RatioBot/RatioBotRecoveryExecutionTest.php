<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotRecoveryExecutionTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotDecisionService $decisions;
    private RatioBotStateService $states;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decisions = new RatioBotDecisionService();
        $this->states = new RatioBotStateService();
    }

    /** @test */
    public function recovery_disabled_falls_through_to_plain_buy_range_exhausted(): void
    {
        [$config, $state] = $this->makeRecoveryConfig(['recovery_enabled' => false]);
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 52000, 'last_buy_quantity' => 0.003, 'last_trade_time' => now()->subDays(2)]);

        $result = $this->decisions->evaluate($config->fresh('state'), 40000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('all configured BUY stages are consumed', $result['reason']);
        $this->assertSame(RatioBotState::STATE_STRATEGY_RANGE_EXHAUSTED_DOWN, $config->fresh('state')->state->position_state);
    }

    /** @test */
    public function recovery_does_not_fire_before_the_review_window_elapses(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 52000, 'last_buy_quantity' => 0.003, 'last_trade_time' => now()->subMinutes(30)]);

        $result = $this->decisions->evaluate($config->fresh('state'), 40000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertTrue((bool) data_get($result['decision_record']->metadata, 'recovery'));
        $this->assertSame('recovery_wait', $result['decision_record']->metadata['trigger']);
        $this->assertSame(RatioBotState::STATE_RECOVERY_MONITORING, $config->fresh('state')->state->position_state);
        $this->assertSame(1, RatioBotEvent::where('event_type', RatioBotEvent::EVENT_RECOVERY_MONITORING_ENTERED)->count());
    }

    /** @test */
    public function recovery_never_buys_immediately_on_eligibility_it_tracks_the_low_first(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 52000, 'last_buy_quantity' => 0.003, 'last_trade_time' => now()->subMinutes(90)]);

        // Lowest lot = 45000, recovery_buy_drop_pct = 5% -> eligible once price <= 42750.
        $result = $this->decisions->evaluate($config->fresh('state'), 42000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision'], 'must never buy the instant eligibility is hit');
        $this->assertSame('recovery_reversal_watch', $result['decision_record']->metadata['trigger']);
        $this->assertTrue((bool) $config->fresh('state')->state->buy_armed);
    }

    /** @test */
    public function recovery_buy_fires_only_after_a_confirmed_reversal_and_creates_a_recovery_lot(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 52000, 'last_buy_quantity' => 0.003, 'last_trade_time' => now()->subMinutes(90)]);

        // Arm: price falls to 42000 (well past the 42750 eligible point) -> trough ratio = 42000/45000-1 = -0.0667.
        $this->decisions->evaluate($config->fresh('state'), 42000);
        // Continue falling: new deeper trough at 41000.
        $this->decisions->evaluate($config->fresh('state'), 41000);
        // Reverses up to 41500: rebound-from-trough = (41500/45000-1) - (41000/45000-1) = 0.0111, >= buying_loss_ratio (0.01),
        // and still <= boundary (-0.05), so the reversal is confirmed while still meaningfully below the anchor.
        $result = $this->decisions->evaluate($config->fresh('state'), 41500);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertSame('recovery_buy', $result['decision_record']->metadata['trigger']);
        $this->assertSame(RatioBotLot::TYPE_RECOVERY, $result['decision_record']->metadata['stage']['type']);
        $this->assertEqualsWithDelta(20.0, (float) $result['decision_record']->metadata['stage']['allocation_pct'], 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $result['decision_record']->metadata['stage_quote_amount'], 0.01, 'base_quote_amount=100, strategy_reserve_pct=20 -> 20 quote');

        // Simulate the fill and confirm a RECOVERY lot is created without wiping the aggregate position.
        $decision = $result['decision_record'];
        $this->states->onBuyFilled($config->fresh('state'), $decision, 41500, 0.0005, 'order-r1', 'client-r1');

        $recoveryLot = RatioBotLot::where('ratio_bot_config_id', $config->id)->where('lot_type', RatioBotLot::TYPE_RECOVERY)->first();
        $this->assertNotNull($recoveryLot);
        $this->assertEqualsWithDelta(41500, (float) $recoveryLot->buy_price, 0.01);

        $fresh = $config->fresh('state')->state;
        $this->assertNotNull($fresh->recovery_used_at, 'recovery reserve must be marked used for this exhaustion cycle');
        $this->assertEqualsWithDelta(0.0035, (float) $fresh->last_buy_quantity, 0.00000001, 'aggregate quantity must accumulate, not reset, on a recovery fill');
        $this->assertSame(RatioBotState::STATE_HOLDING, $fresh->position_state);
        $this->assertSame(1, RatioBotEvent::where('event_type', RatioBotEvent::EVENT_RECOVERY_TRADE_EXECUTED)->count());
    }

    /** @test */
    public function recovery_fires_only_once_per_exhaustion_cycle_no_stage_five(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 52000,
            'last_buy_quantity' => 0.003,
            'last_trade_time' => now()->subMinutes(90),
            'recovery_used_at' => now()->subMinutes(10),
        ]);

        $result = $this->decisions->evaluate($config->fresh('state'), 30000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('recovery reserve has already been used', $result['reason']);
    }

    /** @test */
    public function recovery_lot_sell_only_targets_the_recovery_lot_once_normal_lots_are_gone(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [
            [1, 60000, 0.001, RatioBotLot::STATUS_CLOSED],
            [2, 52000, 0.001, RatioBotLot::STATUS_CLOSED],
            [3, 45000, 0.001, RatioBotLot::STATUS_CLOSED],
        ]);
        $recoveryLot = RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'buy_stage_index' => 4,
            'lot_type' => RatioBotLot::TYPE_RECOVERY,
            'allocation_pct' => 20,
            'buy_price' => 41500,
            'original_qty' => 0.0005,
            'remaining_qty' => 0.0005,
            'spent_quote' => 41500 * 0.0005,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 41500,
            'last_buy_quantity' => 0.0005,
            // Recovery review window (60 min) must have already elapsed before
            // the recovery-sell eligibility watch even begins (spec §43).
            'last_trade_time' => now()->subMinutes(90),
        ]);

        // recovery_sell_rise_pct = 2% -> eligible once price >= 42330. Below that: must wait, not sell immediately.
        $waiting = $this->decisions->evaluate($config->fresh('state'), 42000);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $waiting['decision']);
        $this->assertSame('recovery_sell_wait', $waiting['decision_record']->metadata['trigger']);

        // Eligible and rising well past the eligible line -> arms, tracks peak, does not sell on the very first eligible tick.
        $armed = $this->decisions->evaluate($config->fresh('state'), 43500);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $armed['decision']);
        $this->assertSame('recovery_sell_watch', $armed['decision_record']->metadata['trigger']);

        // Pulls back from peak by >= selling_loss_ratio (1%) while still above the eligible rise (2%) -> sells only the recovery lot.
        $sell = $this->decisions->evaluate($config->fresh('state'), 43080);
        $this->assertSame(RatioBotDecision::DECISION_SELL, $sell['decision']);
        $this->assertSame('recovery_sell', $sell['decision_record']->metadata['trigger']);
        $this->assertSame([(string) $recoveryLot->id], $sell['decision_record']->metadata['sell_lot_ids']);
    }

    /** @test */
    public function recovery_lot_sell_waits_for_the_review_window_before_any_eligibility_observation(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $this->seedBuyLots($config, [
            [1, 60000, 0.001, RatioBotLot::STATUS_CLOSED],
            [2, 52000, 0.001, RatioBotLot::STATUS_CLOSED],
            [3, 45000, 0.001, RatioBotLot::STATUS_CLOSED],
        ]);
        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'buy_stage_index' => 4,
            'lot_type' => RatioBotLot::TYPE_RECOVERY,
            'allocation_pct' => 20,
            'buy_price' => 41500,
            'original_qty' => 0.0005,
            'remaining_qty' => 0.0005,
            'spent_quote' => 41500 * 0.0005,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 41500,
            'last_buy_quantity' => 0.0005,
            'last_trade_time' => now()->subMinutes(10), // well under the 60-minute review window
        ]);

        // Price is already well above the eligible rise, but the review window hasn't elapsed yet -> must still wait.
        $result = $this->decisions->evaluate($config->fresh('state'), 45000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertSame('recovery_sell_review_wait', $result['decision_record']->metadata['trigger']);
        $this->assertSame(RatioBotState::STATE_RECOVERY_MONITORING, $config->fresh('state')->state->position_state);
    }

    /** @test */
    public function a_full_flat_exit_resets_recovery_used_at_for_the_next_cycle(): void
    {
        [$config, $state] = $this->makeRecoveryConfig();
        $lot = $this->seedBuyLots($config, [[1, 45000, 0.001]])->first();
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 45000,
            'last_buy_quantity' => 0.001,
            'recovery_used_at' => now()->subHour(),
        ]);
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => ['sell_lot_ids' => [(string) $lot->id]],
            'decided_at' => now(),
        ]);

        $this->states->onSellFilled($config->fresh('state'), $decision, 50000, 0.001, 'o1', 'c1');

        $fresh = $config->fresh('state')->state;
        $this->assertNull($fresh->recovery_used_at);
        $this->assertSame(RatioBotState::STATE_FLAT, $fresh->position_state);
    }

    private function makeRecoveryConfig(array $overrides = []): array
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'enabled' => true,
            'v2_enabled' => true,
            'base_quote_amount' => 100,
            'stage_buy_allocations' => [40, 20, 20],
            'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [20, 20, 40],
            'stage_sell_rises' => [5, 5, 10],
            'strategy_reserve_pct' => 20,
            'recovery_enabled' => true,
            'recovery_timeout_minutes' => 60,
            'recovery_buy_drop_pct' => 5,
            'recovery_sell_rise_pct' => 2,
            'buying_loss_ratio' => 0.01,
            'selling_loss_ratio' => 0.01,
            'min_net_profit_bps' => 0,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ], $overrides));
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 70000,
            'active_anchor_price' => 70000,
            'active_anchor_type' => 'sell_anchor',
        ]);
        $config->setRelation('state', $state);

        return [$config, $state];
    }

    private function seedBuyLots(RatioBotConfig $config, array $rows)
    {
        return collect($rows)->map(function (array $row) use ($config) {
            [$stage, $price, $qty] = $row;
            $status = $row[3] ?? RatioBotLot::STATUS_OPEN;

            return RatioBotLot::create([
                'ratio_bot_config_id' => $config->id,
                'buy_stage_index' => $stage,
                'lot_type' => RatioBotLot::TYPE_NORMAL,
                'allocation_pct' => [1 => 40, 2 => 20, 3 => 20][$stage] ?? 0,
                'buy_price' => $price,
                'original_qty' => $qty,
                'remaining_qty' => $status === RatioBotLot::STATUS_OPEN ? $qty : 0,
                'spent_quote' => $price * $qty,
                'status' => $status,
                'bought_at' => now(),
            ]);
        });
    }
}
