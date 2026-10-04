<?php

namespace Tests\Unit\RatioBot;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Exchange\ExchangeClientInterface;
use App\Domain\RatioBot\RatioBotExecutionService;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\RatioBot\StrategyManagerService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\LiveOrderIntentService;
use App\Domain\Trading\ReconciliationService;
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * A live spot order goes through LiveOrderIntentService::place(), whose
 * reconcile step already books the fill via RatioBotOrderUpdateService; the
 * execution service then handled the same result again. Each fill must land
 * in state, lots and trade outcomes exactly once. The Order/reconcile part of
 * place() is reproduced here with the REAL ReconciliationService.
 */
class RatioBotLiveFillBookingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ExchangeConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
        $this->connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Main', 'provider' => 'binance',
            'exchange' => 'binance', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function stagePlanConfig(): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'market_type' => 'spot',
            'algorithm' => 'ratio_threshold', 'execution_mode' => 'live', 'enabled' => true,
            'v2_enabled' => true, 'base_quote_amount' => 100,
            'stage_buy_allocations' => [50, 25, 25], 'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [25, 25, 50], 'stage_sell_rises' => [5, 5, 10],
            'min_net_profit_bps' => 0, 'fee_bps' => 0, 'max_slippage_bps' => 0,
            'connection_id' => $this->connection->id, 'max_entry_price_drift_bps' => 500,
        ]);
    }

    /** Mirrors LiveOrderIntentService::place(): create the Order row, then reconcile the exchange ack. */
    private function reconcilingOrders(): LiveOrderIntentService
    {
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->andReturn(1000.0);
        $orders->shouldReceive('place')->once()->andReturnUsing(function (array $p) {
            $symbol = Symbol::firstOrCreate(['symbol' => 'BTCUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto', 'exchange' => 'binance']);
            $order = Order::create([
                'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'symbol_id' => $symbol->id,
                'provider' => 'binance', 'asset_class' => 'CRYPTO', 'asset_code' => 'BTCUSDT', 'quote_currency' => 'USDT',
                'exchange' => 'binance', 'side' => $p['side'], 'type' => 'market', 'quantity' => $p['qty'], 'qty' => $p['qty'],
                'requested_qty' => $p['qty'], 'price' => 1000.0, 'status' => Order::STATUS_PENDING_PROVIDER,
                'provider_status' => 'PENDING_PROVIDER', 'client_order_id' => $p['idempotency_key'],
                'reconciliation_state' => Order::RECONCILIATION_PENDING, 'idempotency_key' => $p['idempotency_key'],
                'trace_id' => (string) Str::uuid(), 'placed_ts' => now(), 'meta' => $p['meta'],
            ]);

            return app(ReconciliationService::class)->reconcileExchangeOrder($order, [
                'orderId' => '123456', 'symbol' => 'BTCUSDT', 'status' => 'FILLED', 'executedQty' => $p['qty'],
                'price' => 1000.0,
                'fills' => [['tradeId' => 't1', 'price' => 1000.0, 'qty' => $p['qty'], 'commission' => 0.00005, 'commissionAsset' => 'BTC', 'time' => now()->getTimestampMs()]],
                'raw' => ['clientOrderId' => $p['idempotency_key']], 'source' => 'submission_ack',
            ]);
        });

        return $orders;
    }

    private function service(LiveOrderIntentService $orders, array $balances): RatioBotExecutionService
    {
        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->andReturn($balances);
        $factory = Mockery::mock(ExchangeClientFactory::class);
        $factory->shouldReceive('makeFromConnection')->andReturn($client);

        return new RatioBotExecutionService(
            $orders, new RatioBotStateService(), Mockery::mock(FuturesOrderIntentService::class),
            Mockery::mock(FuturesConfigService::class), new RatioBotRiskService(), app(StrategyManagerService::class),
            $factory, app(\App\Domain\MarketData\MarketSnapshotService::class),
            app(\App\Domain\RatioBot\RatioBotExchangeStopOrderService::class),
            app(\App\Domain\RatioBot\RatioBotFundingCostService::class),
        );
    }

    public function test_live_spot_buy_fill_is_booked_once(): void
    {
        $config = $this->stagePlanConfig();
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_FLAT]);
        $config->setRelation('state', $state);
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id, 'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 1000.0, 'execution_status' => RatioBotDecision::EXEC_PENDING,
            'position_state_before' => RatioBotState::STATE_FLAT,
            'metadata' => ['stage_quote_amount' => 50.0], 'decided_at' => now(),
        ]);

        $this->service($this->reconcilingOrders(), [['asset' => 'USDT', 'free' => '500', 'locked' => '0']])
            ->execute($config, $decision);

        $lots = RatioBotLot::where('ratio_bot_config_id', $config->id)->get();
        $this->assertCount(1, $lots);
        $this->assertSame(1, RatioBotEvent::where('ratio_bot_config_id', $config->id)
            ->where('event_type', RatioBotEvent::EVENT_ORDER_FILLED)->count());
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->fresh()->execution_status);
        $this->assertEqualsWithDelta((float) $lots->first()->original_qty, (float) $state->fresh()->last_buy_quantity, 1e-8);
    }

    public function test_live_spot_sell_fill_is_booked_once(): void
    {
        $config = $this->stagePlanConfig();
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 900, 'last_buy_quantity' => 0.1, 'realized_pnl' => 0]);
        $lot = RatioBotLot::create(['ratio_bot_config_id' => $config->id, 'cycle_number' => 1, 'buy_stage_index' => 1,
            'lot_type' => 'NORMAL', 'allocation_pct' => 50, 'buy_price' => 900, 'original_qty' => 0.1, 'remaining_qty' => 0.1,
            'spent_quote' => 90, 'status' => 'open', 'bought_at' => now(), 'buy_order_id' => 'x']);
        $config->setRelation('state', $state);
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id, 'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 1000.0, 'execution_status' => RatioBotDecision::EXEC_PENDING,
            'position_state_before' => RatioBotState::STATE_HOLDING,
            'metadata' => ['sell_quantity' => 0.05, 'sell_lot_ids' => [$lot->id]], 'decided_at' => now(),
        ]);

        $this->service($this->reconcilingOrders(), [['asset' => 'USDT', 'free' => '500', 'locked' => '0'], ['asset' => 'BTC', 'free' => '1', 'locked' => '0']])
            ->execute($config, $decision);

        $lot = $lot->fresh();
        $state = $state->fresh();
        $this->assertEqualsWithDelta(0.05, (float) $lot->remaining_qty, 1e-8);   // half of the lot is still held
        $this->assertEqualsWithDelta(5.0, (float) $lot->realized_pnl, 1e-6);     // 0.05 x (1000 - 900), not doubled
        $this->assertEqualsWithDelta(0.05, (float) $state->last_buy_quantity, 1e-8);
        $this->assertSame(1, (int) $state->daily_trade_count);
    }

    public function test_live_reserve_tranche_accumulates_like_sandbox(): void
    {
        $results = [];
        foreach (['live', 'sandbox'] as $mode) {
            $config = RatioBotConfig::create([
                'workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'market_type' => 'spot',
                'algorithm' => 'ratio_threshold', 'execution_mode' => $mode, 'name' => 'tranche-'.$mode, 'enabled' => true,
                'base_quote_amount' => 100, 'initial_entry_pct' => 0.5, 'reserve_tranche_count' => 2,
                'sell_threshold_ratio' => 0.5, 'buy_threshold_ratio' => 0.25,
                'connection_id' => $this->connection->id, 'max_entry_price_drift_bps' => 500,
            ]);
            $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_PARTIAL_HOLDING,
                'last_buy_price' => 900, 'last_buy_quantity' => 0.05, 'reserve_tranches_remaining' => 2, 'reserve_quote_remaining' => 50]);
            $config->setRelation('state', $state);
            $decision = RatioBotDecision::create([
                'ratio_bot_config_id' => $config->id, 'decision' => RatioBotDecision::DECISION_BUY,
                'evaluated_price' => 1000.0, 'execution_status' => RatioBotDecision::EXEC_PENDING, 'decided_at' => now(),
                'position_state_before' => RatioBotState::STATE_PARTIAL_HOLDING,
            ]);
            $orders = Mockery::mock(LiveOrderIntentService::class);
            $orders->shouldReceive('fetchReferencePrice')->andReturn(1000.0);
            $orders->shouldReceive('place')->andReturnUsing(function (array $p) {
                $order = new Order();
                $order->status = 'FILLED';
                $order->filled_qty = $p['qty'];
                $order->avg_fill_price = 1000.0;
                $order->exchange_order_id = 'EX-'.uniqid();

                return $order;
            });

            $this->service($orders, [['asset' => 'USDT', 'free' => '500', 'locked' => '0']])->execute($config, $decision);

            $st = $state->fresh();
            $results[$mode] = [round((float) $st->last_buy_quantity, 8), round((float) $st->last_buy_price, 4),
                (int) $st->reserve_tranches_remaining, round((float) $st->reserve_quote_remaining, 4)];
        }

        $this->assertSame($results['sandbox'], $results['live']);
        $this->assertSame(1, $results['live'][2]);   // one tranche used, not re-armed
    }
}
