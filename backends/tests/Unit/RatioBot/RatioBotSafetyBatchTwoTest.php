<?php

namespace Tests\Unit\RatioBot;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Exchange\ExchangeClientInterface;
use App\Domain\RatioBot\RatioBotExecutionService;
use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\RatioBot\StrategyManagerService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\LiveOrderIntentService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RatioBotSafetyBatchTwoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function holdingBot(array $config = []): RatioBotConfig
    {
        $bot = RatioBotConfig::create(array_merge([
            'workspace_id' => Workspace::factory()->create()->id, 'symbol' => 'BTCUSDT', 'name' => 'bot-'.Str::random(6),
            'enabled' => true, 'market_type' => 'spot', 'algorithm' => 'ratio_threshold', 'trigger_mode' => 'threshold',
            'sell_threshold_ratio' => 0.015, 'buy_threshold_ratio' => -0.015, 'fee_bps' => 10, 'max_slippage_bps' => 30,
            'min_net_profit_bps' => 10, 'cooldown_seconds_after_trade' => 60,
        ], $config));
        RatioBotState::create(['ratio_bot_config_id' => $bot->id, 'position_state' => 'holding', 'last_buy_price' => 100,
            'last_buy_quantity' => 1, 'active_anchor_price' => 100, 'active_anchor_type' => 'buy_anchor', 'last_trade_side' => 'BUY',
            'last_trade_time' => now()->subHour(), 'last_seen_price' => 103]);

        return $bot->fresh('state');
    }

    private function orphanPending(RatioBotConfig $bot, int $minutesAgo): void
    {
        $decision = RatioBotDecision::create(['ratio_bot_config_id' => $bot->id, 'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 103, 'execution_status' => RatioBotDecision::EXEC_PENDING, 'decided_at' => now()]);
        RatioBotDecision::query()->whereKey($decision->id)->update([
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo)]);
    }

    private function evaluate(RatioBotConfig $bot, float $price): RatioBotDecision
    {
        return app(RatioBotRealtimeEngine::class)->evaluate($bot, $price, [
            'price_source' => 'canonical_market_snapshot', 'price_timestamp' => Carbon::now(), 'price_age_seconds' => 1,
        ])['decision_record']->fresh();
    }

    public function test_6_an_orphaned_pending_decision_no_longer_blocks_trading(): void
    {
        $fresh = $this->holdingBot();
        $this->orphanPending($fresh, 1);
        $this->assertSame('duplicate_unresolved_trade', data_get($this->evaluate($fresh, 103)->metadata, 'guard'));

        $orphaned = $this->holdingBot();
        $this->orphanPending($orphaned, 10);   // its worker died 10 minutes ago
        $decision = $this->evaluate($orphaned, 103);
        $this->assertNotSame('duplicate_unresolved_trade', data_get($decision->metadata, 'guard'));
        $this->assertSame(RatioBotDecision::DECISION_SELL, $decision->decision);
    }

    public function test_7_8_loss_gates_and_todays_pnl_use_this_bots_lot_cost_net_of_fees(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = ExchangeConnection::create(['workspace_id' => $workspace->id, 'name' => 'Main', 'provider' => 'binance',
            'exchange' => 'binance', 'is_active' => true]);
        $bot = RatioBotConfig::create(['workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT', 'name' => 'lots',
            'market_type' => 'spot', 'algorithm' => 'ratio_threshold', 'execution_mode' => 'live', 'enabled' => true,
            'base_quote_amount' => 100, 'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01, 'fee_bps' => 0,
            'max_slippage_bps' => 0, 'min_net_profit_bps' => 0, 'connection_id' => $connection->id, 'max_entry_price_drift_bps' => 500]);
        // Averaged cost 1000, but the lot being sold was bought at 900.
        RatioBotState::create(['ratio_bot_config_id' => $bot->id, 'position_state' => 'holding', 'last_buy_price' => 1000,
            'last_buy_quantity' => 0.2, 'realized_pnl' => 0]);
        $lot = RatioBotLot::create(['ratio_bot_config_id' => $bot->id, 'cycle_number' => 1, 'buy_stage_index' => 1, 'lot_type' => 'NORMAL',
            'allocation_pct' => 50, 'buy_price' => 900, 'original_qty' => 0.1, 'remaining_qty' => 0.1, 'spent_quote' => 90,
            'status' => 'open', 'bought_at' => now(), 'buy_order_id' => 'x']);
        $bot = $bot->fresh('state');
        $decision = RatioBotDecision::create(['ratio_bot_config_id' => $bot->id, 'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 950, 'execution_status' => RatioBotDecision::EXEC_PENDING, 'decided_at' => now(),
            'position_state_before' => 'holding', 'metadata' => ['sell_quantity' => 0.1, 'sell_lot_ids' => [$lot->id]]]);

        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->andReturn([['asset' => 'BTC', 'free' => '1', 'locked' => '0']]);
        $factory = Mockery::mock(ExchangeClientFactory::class);
        $factory->shouldReceive('makeFromConnection')->andReturn($client);
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->andReturn(950.0);
        $orders->shouldReceive('place')->andReturnUsing(function (array $p) {
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $p['qty'];
            $order->avg_fill_price = 950.0;
            $order->exchange_order_id = 'EX-1';

            return $order;
        });
        (new RatioBotExecutionService($orders, new RatioBotStateService(), Mockery::mock(FuturesOrderIntentService::class),
            Mockery::mock(FuturesConfigService::class), new RatioBotRiskService(), app(StrategyManagerService::class), $factory,
            app(\App\Domain\MarketData\MarketSnapshotService::class), app(\App\Domain\RatioBot\RatioBotExchangeStopOrderService::class),
            app(\App\Domain\RatioBot\RatioBotFundingCostService::class)))->execute($bot, $decision);

        $state = $bot->state->fresh();
        $this->assertSame(0, (int) $state->consecutive_losses);              // +5 on the lot, not -5 off the average
        $this->assertEqualsWithDelta(0.0, (float) $state->daily_loss_total, 1e-9);
        $this->assertEqualsWithDelta(5.0, (float) $state->daily_realized_pnl, 1e-6);
        $this->assertEqualsWithDelta(5.0, app(RatioBotRuntimeQueryService::class)->pnlSummary($bot->fresh('state'))['today_realized_pnl'], 1e-6);
    }

    public function test_9_the_auto_start_wait_reason_is_surfaced_and_cleared(): void
    {
        $bot = $this->holdingBot();
        $job = new RunRatioBotEvaluationJob((string) $bot->id);
        $note = new \ReflectionMethod($job, 'noteAutoStartWait');

        $note->invoke($job, $bot, 'Waiting to start: confidence 40 below required 60.');
        $this->assertSame('auto_start_waiting', $bot->state->fresh()->block_reason_code);
        $this->assertStringContainsString('confidence 40', (string) $bot->state->fresh()->block_reason_detail);

        $note->invoke($job, $bot->fresh('state'), null);
        $this->assertNull($bot->state->fresh()->block_reason_code);

        // Never overwrites a real block reason.
        $bot->state->update(['block_reason_code' => 'stale_feed']);
        $note->invoke($job, $bot->fresh('state'), 'Waiting to start: something');
        $this->assertSame('stale_feed', $bot->state->fresh()->block_reason_code);
    }

    public function test_10_no_active_scheduled_task_keeps_a_day_long_overlap_lock(): void
    {
        $dayLong = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->withoutOverlapping && $event->expiresAt >= 1440)
            ->map(fn ($event) => $event->description ?: $event->command);

        $this->assertSame([], $dayLong->values()->all());
    }
}
