<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\DynamicThresholdService;
use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Drives the real engine (the same entry point RunRatioBotEvaluationJob uses)
 * with UI-default style settings.
 */
class RatioBotStrategySafetyTest extends TestCase
{
    use RefreshDatabase;

    private function bot(array $config, array $state): RatioBotConfig
    {
        $bot = RatioBotConfig::create(array_merge([
            'workspace_id' => '00000000-0000-0000-0000-000000000001', 'symbol' => 'BTCUSDT', 'enabled' => true, 'name' => 'bot-'.uniqid(),
            'market_type' => 'spot', 'algorithm' => 'ratio_threshold', 'trigger_mode' => 'threshold',
            'sell_threshold_ratio' => 0.015, 'buy_threshold_ratio' => -0.015,
            'fee_bps' => 10, 'max_slippage_bps' => 30, 'min_net_profit_bps' => 10, 'cooldown_seconds_after_trade' => 60,
        ], $config));
        RatioBotState::create(array_merge(['ratio_bot_config_id' => $bot->id], $state));

        return $bot->fresh('state');
    }

    private function holding(array $config = []): RatioBotConfig
    {
        return $this->bot($config, ['position_state' => 'holding', 'last_buy_price' => 100, 'last_buy_quantity' => 1,
            'active_anchor_price' => 100, 'active_anchor_type' => 'buy_anchor', 'last_trade_side' => 'BUY',
            'last_trade_time' => now()->subHour(), 'last_seen_price' => 100]);
    }

    private function flatAfterSellAt100(): RatioBotConfig
    {
        return $this->bot([], ['position_state' => 'flat', 'last_sell_price' => 100, 'last_sell_quantity' => 1,
            'active_anchor_price' => 100, 'active_anchor_type' => 'sell_anchor', 'last_trade_side' => 'SELL',
            'last_trade_time' => now()->subHour(), 'last_seen_price' => 100]);
    }

    private function evaluate(RatioBotConfig $config, float $price): RatioBotDecision
    {
        $result = app(RatioBotRealtimeEngine::class)->evaluate($config, $price, [
            'price_source' => 'canonical_market_snapshot', 'price_timestamp' => Carbon::now(), 'price_age_seconds' => 1,
        ]);

        return $result['decision_record']->fresh();
    }

    public function test_a_blank_stop_loss_has_no_hidden_stop(): void
    {
        $dynamic = app(DynamicThresholdService::class)->resolve(
            RatioBotConfig::make(['sell_threshold_ratio' => 0.015, 'buy_threshold_ratio' => -0.015, 'stop_loss_pct' => null]), 100);
        $this->assertSame(0.0, (float) $dynamic['stop_loss_ratio']);

        $decision = $this->evaluate($this->holding(['stop_loss_pct' => null]), 99.0);
        $this->assertNotSame(RatioBotDecision::DECISION_SELL, $decision->decision);
        $this->assertNotSame('stop_loss', data_get($decision->metadata, 'trigger'));
    }

    public function test_a_stop_loss_the_user_set_still_fires(): void
    {
        $decision = $this->evaluate($this->holding(['stop_loss_pct' => 2]), 97.5);
        $this->assertSame(RatioBotDecision::DECISION_SELL, $decision->decision);
        $this->assertSame('stop_loss', data_get($decision->metadata, 'trigger'));

        $this->assertNotSame(RatioBotDecision::DECISION_SELL, $this->evaluate($this->holding(['stop_loss_pct' => 2]), 98.5)->decision);
    }

    public function test_the_stage_ladder_is_not_stopped_out_by_a_hidden_stop(): void
    {
        $bot = $this->bot(['v2_enabled' => true, 'stop_loss_pct' => null, 'base_quote_amount' => 1000,
            'stage_buy_allocations' => [50, 25, 25], 'stage_buy_drops' => [10, 5, 5], 'stage_sell_rises' => [5, 5, 10],
            'selling_loss_ratio' => 0.02, 'buying_loss_ratio' => 0.02],
            ['position_state' => 'holding', 'last_buy_price' => 100, 'last_buy_quantity' => 5, 'active_anchor_price' => 100,
                'active_anchor_type' => 'buy_anchor', 'last_trade_side' => 'BUY', 'last_trade_time' => now()->subHour(),
                'current_cycle_number' => 1, 'last_seen_price' => 100]);
        RatioBotLot::create(['ratio_bot_config_id' => $bot->id, 'cycle_number' => 1, 'buy_stage_index' => 1, 'lot_type' => 'normal',
            'allocation_pct' => 50, 'buy_price' => 100, 'original_qty' => 5, 'remaining_qty' => 5, 'spent_quote' => 500,
            'status' => 'open', 'bought_at' => now()->subHour()]);

        $decision = $this->evaluate($bot->fresh('state'), 99.0);
        $this->assertNotSame('capital_protection_stop_loss', data_get($decision->metadata, 'trigger'));
        $this->assertNotSame(RatioBotDecision::DECISION_SELL, $decision->decision);
    }

    public function test_a_negative_buy_threshold_means_a_drop_not_a_buy_above_the_sell_price(): void
    {
        $this->assertNotSame(RatioBotDecision::DECISION_BUY, $this->evaluate($this->flatAfterSellAt100(), 101.0)->decision);
        $this->assertNotSame(RatioBotDecision::DECISION_BUY, $this->evaluate($this->flatAfterSellAt100(), 99.0)->decision);

        // 1.5% drop + 0.5% cost (2 x 10 bps fee + 30 bps slippage): needs 100 / 1.02 = 98.04 or lower.
        $decision = $this->evaluate($this->flatAfterSellAt100(), 97.9);
        $this->assertSame(RatioBotDecision::DECISION_BUY, $decision->decision);
    }

    public function test_the_single_tick_spike_filter_compares_against_the_previous_tick(): void
    {
        $decision = $this->evaluate($this->holding(['max_spike_deviation_bps' => 50]), 110.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $decision->decision);
        $this->assertSame('single_tick_spike', data_get($decision->metadata, 'guard'));
    }
}
