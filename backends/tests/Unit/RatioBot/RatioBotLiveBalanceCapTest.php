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
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Multi-bot / shared-wallet safety: two bots on different symbols (e.g.
 * BTCUSDT + ETHUSDT) sharing one real exchange account must never both
 * assume their full configured budget is available. Every BUY caps its
 * quote spend at the LIVE free balance right before submission.
 */
class RatioBotLiveBalanceCapTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    /** @test */
    public function buy_quantity_is_capped_to_the_live_free_quote_balance(): void
    {
        $connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Main Binance',
            'provider' => 'binance',
            'exchange' => 'binance',
            'is_active' => true,
        ]);

        // Configured budget wants 80 USDT, but only 20 USDT is actually free
        // on the exchange right now (e.g. another bot already committed the rest).
        [$config, $decision] = $this->makeFixture($connection->id, stageQuoteAmount: 80.0, evaluatedPrice: 1000.0);

        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->once()->andReturn([
            ['asset' => 'USDT', 'free' => '20.00000000', 'locked' => '0.00000000'],
            ['asset' => 'BTC', 'free' => '0.5', 'locked' => '0'],
        ]);
        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldReceive('makeFromConnection')->once()->andReturn($client);

        $capturedQty = null;
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1000.0); // within drift tolerance
        $orders->shouldReceive('place')->once()->andReturnUsing(function (array $payload) use (&$capturedQty) {
            $capturedQty = $payload['qty'];
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $payload['qty'];
            $order->exchange_order_id = 'EX-1';
            return $order;
        });

        $this->makeService($orders, $exchangeClients)->execute($config, $decision);

        // Capped to 20 USDT / 1000 price = 0.02, NOT 80/1000 = 0.08.
        $this->assertEqualsWithDelta(0.02, $capturedQty, 0.0000001);
    }

    /** @test */
    public function buy_proceeds_at_full_size_when_live_balance_comfortably_covers_it(): void
    {
        $connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Main Binance',
            'provider' => 'binance',
            'exchange' => 'binance',
            'is_active' => true,
        ]);

        [$config, $decision] = $this->makeFixture($connection->id, stageQuoteAmount: 80.0, evaluatedPrice: 1000.0);

        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->once()->andReturn([
            ['asset' => 'USDT', 'free' => '500.00000000', 'locked' => '0.00000000'],
        ]);
        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldReceive('makeFromConnection')->once()->andReturn($client);

        $capturedQty = null;
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1000.0);
        $orders->shouldReceive('place')->once()->andReturnUsing(function (array $payload) use (&$capturedQty) {
            $capturedQty = $payload['qty'];
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $payload['qty'];
            $order->exchange_order_id = 'EX-2';
            return $order;
        });

        $this->makeService($orders, $exchangeClients)->execute($config, $decision);

        $this->assertEqualsWithDelta(0.08, $capturedQty, 0.0000001);
    }

    /** @test */
    public function live_balance_fetch_is_shared_across_bots_on_the_same_connection(): void
    {
        $connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Main Binance',
            'provider' => 'binance',
            'exchange' => 'binance',
            'is_active' => true,
        ]);

        // Two bots, two different symbols, same connection + same quote
        // asset (USDT) — evaluating back-to-back the way two bots on the
        // same account's evaluation loop would. Only ONE live fetchBalances
        // call should happen for both; the second reads the short-TTL cache.
        [$configA, $decisionA] = $this->makeFixture($connection->id, stageQuoteAmount: 50.0, evaluatedPrice: 1000.0, symbol: 'BTCUSDT');
        [$configB, $decisionB] = $this->makeFixture($connection->id, stageQuoteAmount: 50.0, evaluatedPrice: 1000.0, symbol: 'ETHUSDT');

        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->once()->andReturn([
            ['asset' => 'USDT', 'free' => '500.00000000', 'locked' => '0.00000000'],
        ]);
        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldReceive('makeFromConnection')->once()->andReturn($client);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->twice()->andReturn(1000.0);
        $orders->shouldReceive('place')->twice()->andReturnUsing(function (array $payload) {
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $payload['qty'];
            $order->exchange_order_id = 'EX-' . uniqid();
            return $order;
        });

        $service = $this->makeService($orders, $exchangeClients);
        $service->execute($configA, $decisionA);
        $service->execute($configB, $decisionB);

        // Reaching here means fetchBalances()->once() was satisfied across
        // BOTH executions instead of firing twice — Mockery would otherwise
        // throw "expected exactly once but called twice".
        $this->assertTrue(true);
    }

    /** @test */
    public function duplicate_same_side_order_check_runs_before_the_live_balance_fetch(): void
    {
        $connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Main Binance',
            'provider' => 'binance',
            'exchange' => 'binance',
            'is_active' => true,
        ]);

        [$config, $decision] = $this->makeFixture($connection->id, stageQuoteAmount: 80.0, evaluatedPrice: 1000.0);

        // An already-open same-side order for this symbol -> execute() must
        // block on this cheap local check WITHOUT ever calling out to the
        // exchange for a live balance read.
        Order::create([
            'workspace_id' => $this->workspace->id,
            'asset_code' => $config->normalizedSymbol(),
            'side' => 'buy',
            'quantity' => 0.01,
            'status' => Order::STATUS_NEW,
            'meta' => ['market_type' => 'spot'],
            'client_order_id' => 'rb-some-other-pending-order',
            'created_at' => now(),
        ]);

        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldNotReceive('makeFromConnection');

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldNotReceive('fetchReferencePrice');
        $orders->shouldNotReceive('place');

        $this->makeService($orders, $exchangeClients)->execute($config, $decision);

        $this->assertSame(RatioBotDecision::EXEC_DUPLICATE_BLOCKED, $decision->fresh()->execution_status);
    }

    /** @test */
    public function sell_quantity_is_capped_to_the_live_free_base_balance(): void
    {
        $connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Main Binance',
            'provider' => 'binance',
            'exchange' => 'binance',
            'is_active' => true,
        ]);

        $config = RatioBotConfig::create([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'live',
            'enabled' => true,
            'base_quote_amount' => 100,
            'sell_threshold_ratio' => 0.5,
            'buy_threshold_ratio' => 0.25,
            'connection_id' => $connection->id,
            'max_entry_price_drift_bps' => 0,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 1000.0,
            // Bookkeeping thinks we hold 0.05 BTC, but the real account only
            // has 0.02 BTC free right now (e.g. another bot on the same
            // shared wallet already spent the rest).
            'last_buy_quantity' => 0.05,
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 1200.0,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => [],
            'decided_at' => now(),
        ]);

        $client = Mockery::mock(ExchangeClientInterface::class);
        $client->shouldReceive('fetchBalances')->once()->andReturn([
            ['asset' => 'BTC', 'free' => '0.02000000', 'locked' => '0'],
        ]);
        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldReceive('makeFromConnection')->once()->andReturn($client);

        $capturedQty = null;
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1200.0);
        $orders->shouldReceive('place')->once()->andReturnUsing(function (array $payload) use (&$capturedQty) {
            $capturedQty = $payload['qty'];
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $payload['qty'];
            $order->exchange_order_id = 'EX-SELL-1';
            return $order;
        });

        $this->makeService($orders, $exchangeClients)->execute($config, $decision);

        // Capped to the live 0.02 BTC free balance, NOT the bookkeeping's 0.05.
        $this->assertEqualsWithDelta(0.02, $capturedQty, 0.0000001);
    }

    /** @test */
    public function cap_is_skipped_for_sandbox_bots(): void
    {
        [$config, $decision] = $this->makeFixture(null, stageQuoteAmount: 80.0, evaluatedPrice: 1000.0);
        $config->update(['execution_mode' => 'sandbox']);

        $exchangeClients = Mockery::mock(ExchangeClientFactory::class);
        $exchangeClients->shouldNotReceive('makeFromConnection');

        $this->makeService(Mockery::mock(LiveOrderIntentService::class), $exchangeClients)
            ->execute($config->fresh(), $decision);

        $this->assertTrue(true); // reaching here without the mock expectation failing is the assertion
    }

    private function makeService(LiveOrderIntentService $orders, ExchangeClientFactory $exchangeClients): RatioBotExecutionService
    {
        return new RatioBotExecutionService(
            $orders,
            new RatioBotStateService(),
            Mockery::mock(FuturesOrderIntentService::class),
            Mockery::mock(FuturesConfigService::class),
            new RatioBotRiskService(),
            app(StrategyManagerService::class),
            $exchangeClients,
            app(\App\Domain\MarketData\MarketSnapshotService::class),
            app(\App\Domain\RatioBot\RatioBotExchangeStopOrderService::class),
            app(\App\Domain\RatioBot\RatioBotFundingCostService::class),
        );
    }

    private function makeFixture(?string $connectionId, float $stageQuoteAmount, float $evaluatedPrice, string $symbol = 'BTCUSDT'): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $this->workspace->id,
            'symbol' => $symbol,
            'market_type' => 'spot',
            'execution_mode' => 'live',
            'enabled' => true,
            'base_quote_amount' => 100,
            'sell_threshold_ratio' => 0.5,
            'buy_threshold_ratio' => 0.25,
            'connection_id' => $connectionId,
            'max_entry_price_drift_bps' => 500,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => $evaluatedPrice,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => ['stage_quote_amount' => $stageQuoteAmount],
            'decided_at' => now(),
        ]);

        return [$config, $decision];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
