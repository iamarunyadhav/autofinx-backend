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
use App\Models\ExchangeSymbolMetadata;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Live spot execution safety: protective exits in fast markets, fee-aware
 * PnL, base-coin fee and dust bookkeeping, exchange minimum order size.
 */
class RatioBotLiveSafetyBatchTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ExchangeConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
        $this->connection = ExchangeConnection::create(['workspace_id' => $this->workspace->id, 'name' => 'Main',
            'provider' => 'binance', 'exchange' => 'binance', 'is_active' => true]);
        ExchangeSymbolMetadata::create(['id' => (string) Str::uuid(), 'exchange' => 'binance', 'symbol' => 'BTCUSDT',
            'base_asset' => 'BTC', 'quote_asset' => 'USDT', 'min_qty' => 0.00001, 'max_qty' => 1000,
            'step_size' => 0.00001, 'min_notional' => 10, 'tick_size' => 0.01]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bot(array $state, array $config = []): RatioBotConfig
    {
        $bot = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'name' => 'bot-'.Str::random(6),
            'market_type' => 'spot', 'algorithm' => 'ratio_threshold', 'execution_mode' => 'live', 'enabled' => true,
            'base_quote_amount' => 100, 'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01,
            'fee_bps' => 0, 'max_slippage_bps' => 0, 'min_net_profit_bps' => 0,
            'connection_id' => $this->connection->id, 'max_entry_price_drift_bps' => 50,
        ], $config));
        RatioBotState::create(array_merge(['ratio_bot_config_id' => $bot->id], $state));

        return $bot->fresh('state');
    }

    private function holding(float $qty, float $price = 1000.0, array $config = []): RatioBotConfig
    {
        return $this->bot(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => $price,
            'last_buy_quantity' => $qty, 'realized_pnl' => 0], $config);
    }

    private function decision(RatioBotConfig $bot, string $side, float $price, array $metadata = []): RatioBotDecision
    {
        return RatioBotDecision::create(['ratio_bot_config_id' => $bot->id, 'decision' => $side, 'evaluated_price' => $price,
            'execution_status' => RatioBotDecision::EXEC_PENDING, 'decided_at' => now(), 'metadata' => $metadata,
            'position_state_before' => $bot->state->position_state]);
    }

    /** @param  array<int, array<string, string>>  $balances */
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

    /** An order intent that fills whatever it is asked, optionally charging a fee. */
    private function filling(float $price, ?float $fee = null, ?string $feeAsset = null, float $referencePrice = 1000.0): LiveOrderIntentService
    {
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->andReturn($referencePrice);
        $orders->shouldReceive('place')->andReturnUsing(function (array $p) use ($price, $fee, $feeAsset) {
            $order = new Order();
            $order->status = 'FILLED';
            $order->filled_qty = $p['qty'];
            $order->avg_fill_price = $price;
            $order->fee_amount = $fee;
            $order->fee_asset = $feeAsset;
            $order->exchange_order_id = 'EX-'.uniqid();

            return $order;
        });

        return $orders;
    }

    private const BALANCES = [['asset' => 'USDT', 'free' => '1000', 'locked' => '0'], ['asset' => 'BTC', 'free' => '1', 'locked' => '0']];

    public function test_1_a_stop_loss_goes_out_through_a_price_jump_that_blocks_a_normal_sell(): void
    {
        // Fresh quote 5% away from the decision price; drift limit is 50 bps.
        $normal = $this->holding(0.05);
        $normalDecision = $this->decision($normal, RatioBotDecision::DECISION_SELL, 1000.0);
        $orders = $this->filling(950.0, referencePrice: 950.0);
        $this->service($orders, self::BALANCES)->execute($normal, $normalDecision);
        $this->assertSame('price_drift_exceeded', data_get($normalDecision->fresh()->metadata, 'risk_block.code'));
        $orders->shouldNotHaveReceived('place');

        $stopped = $this->holding(0.05);
        $stopDecision = $this->decision($stopped, RatioBotDecision::DECISION_SELL, 1000.0, ['trigger' => 'stop_loss', 'protective_exit' => true]);
        $this->service($this->filling(950.0, referencePrice: 950.0), self::BALANCES)->execute($stopped, $stopDecision);
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $stopDecision->fresh()->execution_status);
        $this->assertSame(RatioBotState::STATE_FLAT, $stopped->state->fresh()->position_state);
    }

    public function test_2_realized_pnl_and_the_loss_gates_are_net_of_fees(): void
    {
        // +0.10 gross on 1 BTC from 1000 to 1000.10, but 10 bps on both legs costs ~2.0001.
        $bot = $this->holding(1.0, 1000.0, ['fee_bps' => 10]);
        $decision = $this->decision($bot, RatioBotDecision::DECISION_SELL, 1000.10);
        $this->service($this->filling(1000.10, referencePrice: 1000.10), self::BALANCES)->execute($bot, $decision);

        $state = $bot->state->fresh();
        $this->assertEqualsWithDelta(0.10 - 2.0001, (float) $state->realized_pnl, 1e-6);
        $this->assertSame(1, (int) $state->consecutive_losses);              // a gross "win" that lost money
        $this->assertEqualsWithDelta(1.9001, (float) $state->daily_loss_total, 1e-6);
    }

    public function test_3_a_base_coin_commission_is_not_booked_as_held(): void
    {
        $bot = $this->bot(['position_state' => RatioBotState::STATE_FLAT, 'last_sell_price' => 1000]);
        $decision = $this->decision($bot, RatioBotDecision::DECISION_BUY, 1000.0, ['stage_quote_amount' => 50.0]);
        $this->service($this->filling(1000.0, 0.00005, 'BTC'), self::BALANCES)->execute($bot, $decision);

        $this->assertEqualsWithDelta(0.04995, (float) $bot->state->fresh()->last_buy_quantity, 1e-8);
    }

    public function test_3_a_remainder_below_the_exchange_minimum_closes_the_position(): void
    {
        $bot = $this->holding(0.05);
        $lot = RatioBotLot::create(['ratio_bot_config_id' => $bot->id, 'cycle_number' => 1, 'buy_stage_index' => 1,
            'lot_type' => 'NORMAL', 'allocation_pct' => 100, 'buy_price' => 1000, 'original_qty' => 0.05, 'remaining_qty' => 0.05,
            'spent_quote' => 50, 'status' => 'open', 'bought_at' => now(), 'buy_order_id' => 'x']);
        $decision = $this->decision($bot, RatioBotDecision::DECISION_SELL, 1000.0, ['sell_quantity' => 0.05, 'sell_lot_ids' => [$lot->id]]);
        // The account holds a little less than booked (fees): the sell is capped and leaves 0.00004 BTC (~$0.04).
        $balances = [['asset' => 'USDT', 'free' => '0', 'locked' => '0'], ['asset' => 'BTC', 'free' => '0.04996', 'locked' => '0']];
        $this->service($this->filling(1000.0), $balances)->execute($bot, $decision);

        $state = $bot->state->fresh();
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertEqualsWithDelta(0.0, (float) $state->last_buy_quantity, 1e-12);
        $this->assertSame(RatioBotLot::STATUS_CLOSED, $lot->fresh()->status);
    }

    public function test_4_an_order_below_the_exchange_minimum_is_not_sent_and_not_counted_as_a_failure(): void
    {
        $bot = $this->bot(['position_state' => RatioBotState::STATE_FLAT, 'last_sell_price' => 1000]);
        $decision = $this->decision($bot, RatioBotDecision::DECISION_BUY, 1000.0, ['stage_quote_amount' => 5.0]); // $5 < $10 min
        $orders = $this->filling(1000.0);
        $this->service($orders, self::BALANCES)->execute($bot, $decision);

        $orders->shouldNotHaveReceived('place');
        $state = $bot->state->fresh();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->fresh()->execution_status);
        $this->assertSame(0, (int) $state->consecutive_order_failures);
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame('order_below_exchange_minimum', $state->block_reason_code);
    }

    public function test_4_a_whole_position_below_the_exchange_minimum_is_written_off_as_dust(): void
    {
        $bot = $this->holding(0.004);   // $4 of BTC, can never be sold on its own
        $decision = $this->decision($bot, RatioBotDecision::DECISION_SELL, 1000.0);
        $orders = $this->filling(1000.0);
        $this->service($orders, self::BALANCES)->execute($bot, $decision);

        $orders->shouldNotHaveReceived('place');
        $this->assertSame(RatioBotState::STATE_FLAT, $bot->state->fresh()->position_state);
        $this->assertStringContainsString('dust position written off', (string) $decision->fresh()->reason);
    }

    public function test_4_the_order_intents_own_size_rejection_does_not_count_toward_the_failure_streak(): void
    {
        ExchangeSymbolMetadata::query()->delete();   // rules not cached locally: the intent validates and refuses
        $bot = $this->bot(['position_state' => RatioBotState::STATE_FLAT, 'last_sell_price' => 1000]);
        $decision = $this->decision($bot, RatioBotDecision::DECISION_BUY, 1000.0, ['stage_quote_amount' => 5.0]);
        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->andReturn(1000.0);
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('Order notional is below Binance minNotional for BTCUSDT.'));
        $this->service($orders, self::BALANCES)->execute($bot, $decision);

        $state = $bot->state->fresh();
        $this->assertSame(0, (int) $state->consecutive_order_failures);
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);   // not stuck awaiting
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->fresh()->execution_status);
    }
}
