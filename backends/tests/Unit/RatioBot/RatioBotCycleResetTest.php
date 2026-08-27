<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotLotService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A "cycle" is one full BUY(s) -> SELL(s) -> FLAT round trip. Without cycle
 * tracking, buyStagesFilled() counts ALL lots ever created (closed
 * included), so a bot that completed one full cycle would see
 * buyFilled >= buyStages forever and never buy again — contradicting
 * "operate autonomously until stopped." Covers the fix: a full exit bumps
 * current_cycle_number, and lot counting is scoped to the current cycle.
 */
class RatioBotCycleResetTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotDecisionService $decisions;
    private RatioBotStateService $states;
    private RatioBotLotService $lots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decisions = new RatioBotDecisionService();
        $this->states = new RatioBotStateService();
        $this->lots = new RatioBotLotService();
    }

    /** @test */
    public function a_full_exit_bumps_the_cycle_number(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $lot = $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_OPEN);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 45000, 'last_buy_quantity' => 0.001]);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => ['sell_lot_ids' => [(string) $lot->id]],
            'decided_at' => now(),
        ]);

        $this->states->onSellFilled($config->fresh('state'), $decision, 50000, 0.001, 'o1', 'c1');

        $this->assertSame(2, $config->fresh('state')->state->current_cycle_number);
    }

    /** @test */
    public function buy_stages_filled_only_counts_the_current_cycle(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED);
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED);
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED);
        $state->update(['current_cycle_number' => 2]);

        $this->assertSame(0, $this->lots->buyStagesFilled($config->fresh()));
    }

    /** @test */
    public function bot_automatically_buys_again_in_a_fresh_cycle_after_a_full_round_trip(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $config->update(['buying_loss_ratio' => 0.02]);

        // Simulate a completed cycle: 3 closed lots from cycle 1, now in cycle 2, FLAT,
        // last sale at 70000 (this becomes the fresh Stage 1 anchor), cooldown already expired.
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED, price: 60000);
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED, price: 55000);
        $this->seedLot($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED, price: 50000);
        $state->update([
            'position_state' => RatioBotState::STATE_FLAT,
            'current_cycle_number' => 2,
            'last_sell_price' => 70000,
            'active_anchor_price' => 70000,
            'active_anchor_type' => 'sell_anchor',
            'last_buy_price' => 0,
            'last_buy_quantity' => 0,
            'cooldown_until' => now()->subMinute(),
        ]);

        // Stage 1 drop 10% off the new 70000 anchor -> eligible at 63000. This must NOT be
        // "all configured BUY stages are consumed" (the pre-fix bug) — it must genuinely re-arm.
        $result = $this->decisions->evaluate($config->fresh('state'), 62000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertSame('stage_buy_reversal_watch', $result['decision_record']->metadata['trigger']);
        $this->assertStringNotContainsString('all configured BUY stages are consumed', $result['reason']);
    }

    private function seedLot(RatioBotConfig $config, int $cycleNumber, string $status, float $price = 45000): RatioBotLot
    {
        $stage = RatioBotLot::where('ratio_bot_config_id', $config->id)->count() + 1;
        $isClosed = $status === RatioBotLot::STATUS_CLOSED;

        return RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => $cycleNumber,
            'buy_stage_index' => $stage,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => $price,
            'original_qty' => 0.001,
            'remaining_qty' => $isClosed ? 0 : 0.001,
            'spent_quote' => $price * 0.001,
            'status' => $status,
            'bought_at' => now(),
            'closed_at' => $isClosed ? now() : null,
        ]);
    }

    private function makeStageConfig(): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'enabled' => true,
            'v2_enabled' => true,
            'base_quote_amount' => 100,
            'stage_buy_allocations' => [50, 25, 25],
            'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [25, 25, 50],
            'stage_sell_rises' => [5, 5, 10],
            'min_net_profit_bps' => 0,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ]);
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
}
