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

class RatioBotPhase1StagePlanTest extends TestCase
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
    public function normal_stage_buy_never_fires_immediately_on_eligibility_it_tracks_the_low_first(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $config->update(['buying_loss_ratio' => 0.02]);

        // Stage 1 drop = 10% off the 70000 sell anchor -> eligible at 63000.
        // 62000 is already past eligible (old immediate-fire code would have
        // bought right here) -- must only arm and track, never fire.
        $armed = $this->decisions->evaluate($config->fresh('state'), 62000);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $armed['decision']);
        $this->assertSame('stage_buy_reversal_watch', $armed['decision_record']->metadata['trigger']);
    }

    /** @test */
    public function normal_stage_buy_fires_only_after_a_confirmed_reversal(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $config->update(['buying_loss_ratio' => 0.02]);

        // Arm past eligible (63000), then track a new deeper low.
        $this->decisions->evaluate($config->fresh('state'), 62000);
        $this->decisions->evaluate($config->fresh('state'), 60000);

        // Rebounds from the 60000 trough by >= buying_loss_ratio (2%) while still meaningfully below the anchor.
        $result = $this->decisions->evaluate($config->fresh('state'), 61500);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertSame('stage_buy', $result['decision_record']->metadata['trigger']);
        $this->assertSame(1, $result['decision_record']->metadata['stage']['index']);
    }

    /** @test */
    public function normal_stage_buy_reversal_watch_resets_when_price_fully_recovers_before_confirming(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $config->update(['buying_loss_ratio' => 0.02]);

        // Arm on a deep dip (trough at -14.3%, well past the -10% eligible line).
        $this->decisions->evaluate($config->fresh('state'), 60000);

        // Price fully recovers back ABOVE the eligible line entirely (not just
        // a partial rebound off the trough) -- the watch must re-arm from
        // scratch, not keep riding the abandoned 60000 trough.
        $this->decisions->evaluate($config->fresh('state'), 71000);

        // A later, much shallower, unrelated dip barely crosses eligible again.
        $armed = $this->decisions->evaluate($config->fresh('state'), 62500);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $armed['decision']);
        $this->assertSame('stage_buy_reversal_watch', $armed['decision_record']->metadata['trigger']);

        // A small tick up from 62500 is nowhere near a 2% rebound off a FRESH
        // trough at 62500 -- must still HOLD. The pre-fix bug reused the old
        // 60000 trough here, which was already >2% below 62700, and fired a
        // premature buy despite this dip never having gone anywhere near 60000.
        $result = $this->decisions->evaluate($config->fresh('state'), 62700);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertSame('stage_buy_reversal_watch', $result['decision_record']->metadata['trigger']);
    }

    /** @test */
    public function all_buy_stages_consumed_and_market_keeps_falling_generates_no_extra_buy(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 52000,
            'last_buy_quantity' => 0.003,
        ]);

        $result = $this->decisions->evaluate($config->fresh('state'), 40000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertSame(RatioBotState::STATE_STRATEGY_RANGE_EXHAUSTED_DOWN, $config->fresh('state')->state->position_state);
        $this->assertSame('STRATEGY_RANGE_EXHAUSTED_DOWN', $config->fresh('state')->state->strategy_health_status);
    }

    /** @test */
    public function exhausted_buy_range_does_not_normal_sell_below_profit(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 52000,
            'last_buy_quantity' => 0.003,
        ]);

        $result = $this->decisions->evaluate($config->fresh('state'), 43000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('all configured BUY stages are consumed', $result['reason']);
    }

    /** @test */
    public function all_sell_stages_completed_and_market_keeps_rising_does_not_chase_buy(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001, 'closed'], [2, 52000, 0.001, 'closed'], [3, 45000, 0.001, 'closed']]);
        $state->update([
            'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 70000,
            'last_sell_quantity' => 0.003,
            'last_buy_quantity' => 0,
        ]);

        $result = $this->decisions->evaluate($config->fresh('state'), 90000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertSame(RatioBotState::STATE_STRATEGY_RANGE_EXHAUSTED_UP, $config->fresh('state')->state->position_state);
        $this->assertSame('STRATEGY_RANGE_EXHAUSTED_UP', $config->fresh('state')->state->strategy_health_status);
    }

    /** @test */
    public function reverse_lot_sell_uses_strict_reverse_purchase_order_lifo_after_a_confirmed_reversal(): void
    {
        // Typical monotonic-decline accumulation: highest stage index (most
        // recently bought) also happens to be the cheapest here, same as it
        // usually is in practice — LIFO and price-order coincide.
        [$config, $state] = $this->makeStageConfig();
        $config->update(['selling_loss_ratio' => 0.02]);
        $this->seedBuyLots($config, [[1, 60000, 0.002], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 54250,
            'last_buy_quantity' => 0.004,
        ]);

        // Stage 1 sell_rise = 5% off the LIFO top lot (stage 3 @ 45000) -> eligible at 47250.
        // Arm at 50000 (well past eligible), never sells on the very first eligible tick.
        $armed = $this->decisions->evaluate($config->fresh('state'), 50000);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $armed['decision']);
        $this->assertSame('stage_sell_reversal_watch', $armed['decision_record']->metadata['trigger']);

        // Pulls back from peak by >= selling_loss_ratio (2%) while still above the eligible rise -> sells.
        $result = $this->decisions->evaluate($config->fresh('state'), 48600);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertSame('stage_reverse_lot_sell', $result['decision_record']->metadata['trigger']);
        $selected = RatioBotLot::find($result['decision_record']->metadata['sell_lot_ids'][0]);
        // Stage 3 (buy_stage_index=3, the LAST one bought) sells first — BUY3 -> SELL1.
        $this->assertEqualsWithDelta(45000, (float) $selected->buy_price, 0.01);
    }

    /** @test */
    public function reverse_lot_sell_never_skips_the_most_recent_lot_to_cash_out_an_older_cheaper_one(): void
    {
        // Non-monotonic fixture where LIFO and "cheapest first" genuinely
        // diverge: stage 3 (bought LAST) is the most EXPENSIVE lot. At a
        // price where stage 1 (cheap, oldest) would already be profitable
        // but stage 3 (top of the LIFO stack) is not yet, the bot must wait
        // — never reach past stage 3 to sell stage 1 out of order.
        [$config, $state] = $this->makeStageConfig();
        $this->seedBuyLots($config, [[1, 45000, 0.001], [2, 52000, 0.001], [3, 60000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 52333,
            'last_buy_quantity' => 0.003,
        ]);

        // 50000 clears stage 1's (45000) profit floor easily but not stage 3's (60000).
        $result = $this->decisions->evaluate($config->fresh('state'), 50000);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
    }

    /** @test */
    public function normal_stage_sell_hold_reason_reflects_the_net_profit_floor_not_a_bogus_pullback_shortfall(): void
    {
        // sell_rise (5%) and the net-profit floor (8%, via min_net_profit_bps)
        // are deliberately set apart so a price can clear the reversal watch's
        // OWN trigger (ratio >= sell_rise) while still failing
        // reverseSellPlan()'s stricter net-profit re-check.
        [$config, $state] = $this->makeStageConfig();
        $config->update(['selling_loss_ratio' => 0.01, 'min_net_profit_bps' => 800]);
        $this->seedBuyLots($config, [[1, 70000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 70000,
            'last_buy_quantity' => 0.001,
        ]);

        // Peak at 75000 (ratio 7.14%) -> arms, first tick never fires.
        $this->decisions->evaluate($config->fresh('state'), 75000);

        // Pulls back to 73500 (ratio exactly 5%): clears sell_rise AND the
        // >=1% pullback-from-peak confirm, so the reversal watch itself is
        // satisfied -- but 5% is still short of the 8% net-profit floor, so
        // reverseSellPlan() returns qty=0.
        $result = $this->decisions->evaluate($config->fresh('state'), 73500);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $reason = $result['decision_record']->reason;
        // Must name the real cause (net-profit floor), not claim the
        // pullback was insufficient -- it wasn't.
        $this->assertStringContainsString('reversal confirmed', $reason);
        $this->assertStringContainsString('net-profit floor', $reason);
        $this->assertStringNotContainsString('< required', $reason);
    }

    /** @test */
    public function review_alert_is_rate_limited_for_same_exhausted_condition(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $this->seedBuyLots($config, [[1, 60000, 0.001], [2, 52000, 0.001], [3, 45000, 0.001]]);
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 52000,
            'last_buy_quantity' => 0.003,
        ]);

        $this->decisions->evaluate($config->fresh('state'), 40000);
        $this->decisions->evaluate($config->fresh('state'), 39000);

        $this->assertSame(1, RatioBotEvent::where('ratio_bot_config_id', $config->id)->where('event_type', 'review_recommended')->count());
    }

    /** @test */
    public function sell_fill_reduces_selected_lot_without_rewriting_buy_cost_basis(): void
    {
        [$config, $state] = $this->makeStageConfig();
        $lot = $this->seedBuyLots($config, [[3, 45000, 0.001]])->first();
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 45000,
            'last_buy_quantity' => 0.001,
        ]);
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => ['sell_lot_ids' => [(string) $lot->id]],
            'decided_at' => now(),
        ]);

        $this->states->onSellFilled($config->fresh('state'), $decision, 50000, 0.0005, 'o1', 'c1');
        $lot->refresh();

        $this->assertEqualsWithDelta(45000, (float) $lot->buy_price, 0.01);
        $this->assertEqualsWithDelta(0.0005, (float) $lot->remaining_qty, 0.00000001);
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

    private function seedBuyLots(RatioBotConfig $config, array $rows)
    {
        return collect($rows)->map(function (array $row) use ($config) {
            [$stage, $price, $qty] = $row;
            $status = $row[3] ?? RatioBotLot::STATUS_OPEN;

            return RatioBotLot::create([
                'ratio_bot_config_id' => $config->id,
                'buy_stage_index' => $stage,
                'allocation_pct' => [1 => 50, 2 => 25, 3 => 25][$stage] ?? 0,
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
