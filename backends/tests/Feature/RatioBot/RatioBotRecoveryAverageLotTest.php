<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotLotService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotRecoveryAverageLotTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
    }

    /** @test */
    public function a_second_recovery_buy_merges_into_the_existing_average_lot_instead_of_creating_a_second_row(): void
    {
        $config = $this->createConfig();

        $this->fillRecoveryBuy($config, price: 100.0, qty: 10.0);
        $this->fillRecoveryBuy($config, price: 80.0, qty: 10.0);

        $lots = RatioBotLot::where('ratio_bot_config_id', $config->id)->where('is_recovery_average', true)->get();
        $this->assertCount(1, $lots, 'a second recovery buy must merge, never create a second row');

        $lot = $lots->first();
        // Volume-weighted average: (10*100 + 10*80) / 20 = 90
        $this->assertEqualsWithDelta(90.0, (float) $lot->buy_price, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $lot->original_qty, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $lot->remaining_qty, 0.0001);
    }

    /** @test */
    public function the_merged_lots_sell_decision_uses_the_recovery_floor_where_the_normal_floor_would_refuse(): void
    {
        $config = $this->createConfig(['recovery_lot_min_profit_ratio' => 0.001, 'min_net_profit_bps' => 300]);
        $this->fillRecoveryBuy($config, price: 100.0, qty: 10.0);

        // Price is only 0.15% above buy price -- the normal ladder-wide
        // floor (3%, passed in as $minNetProfitRatio below) would refuse
        // this on its own; confirmed separately by the plain-normal-lot
        // regression test below using the identical price/floor combination.
        // profitableFifoSellPlan() must still sell it because the lot is
        // flagged is_recovery_average and therefore uses its own lenient
        // recoveryLotMinProfitRatio() instead, regardless of the
        // $minNetProfitRatio argument passed in for the ladder generally.
        $currentPrice = 100.15;
        $normalFloor = max(0.0, (float) $config->min_net_profit_bps / 10000); // 0.03

        $plan = app(RatioBotLotService::class)->profitableFifoSellPlan($config, $currentPrice, $normalFloor, 10.0);
        $this->assertGreaterThan(0.0, $plan['qty'], 'the recovery-average lot must sell using its own lenient floor, not the normal one');
    }

    /** @test */
    public function the_recovery_floor_can_never_compute_below_the_real_execution_cost_floor_even_if_misconfigured(): void
    {
        $config = $this->createConfig([
            'fee_bps' => 50,
            'max_slippage_bps' => 20,
            'recovery_lot_min_profit_ratio' => 0.0, // misconfigured -- attempting zero margin
        ]);

        // executionCostRatio = ((50*2)+20)/10000 = 0.012
        $this->assertEqualsWithDelta(0.012, $config->recoveryLotMinProfitRatio(), 0.00001);
    }

    /** @test */
    public function a_brand_new_non_recovery_positions_normal_lots_are_completely_unaffected(): void
    {
        $config = $this->createConfig(['min_net_profit_bps' => 300]);
        $decision = RatioBotDecision::create($this->decisionAttrs($config));
        app(RatioBotStateService::class)->onBuyFilled($config, $decision, 100.0, 10.0, 'order-1', 'client-1');

        $lot = RatioBotLot::where('ratio_bot_config_id', $config->id)->first();
        $this->assertFalse((bool) $lot->is_recovery_average);
        $this->assertSame(RatioBotLot::TYPE_NORMAL, $lot->lot_type);

        // Same strict normal floor still applies -- barely-profitable price
        // must NOT sell under the normal 3% floor.
        $plan = app(RatioBotLotService::class)->profitableFifoSellPlan($config->fresh('state'), 100.15, 0.03, 10.0);
        $this->assertSame(0.0, $plan['qty']);
    }

    private function fillRecoveryBuy(RatioBotConfig $config, float $price, float $qty): void
    {
        // A prior normal position must already be open for a recovery fill
        // to be recognised as "accumulating on top of" rather than a fresh
        // entry (see RatioBotStateService::onBuyFilled()'s $isTrancheFill).
        $state = $config->state()->first();
        if ((float) ($state->last_buy_quantity ?? 0) <= 0) {
            $seed = RatioBotDecision::create($this->decisionAttrs($config));
            app(RatioBotStateService::class)->onBuyFilled($config, $seed, 120.0, 5.0, 'seed-order', 'seed-client');
        }

        $decision = RatioBotDecision::create($this->decisionAttrs($config, [
            'stage' => ['side' => 'BUY', 'type' => RatioBotLot::TYPE_RECOVERY, 'allocation_pct' => 20],
        ]));

        app(RatioBotStateService::class)->onBuyFilled($config->fresh('state'), $decision, $price, $qty, 'order-' . uniqid(), 'client-' . uniqid());
    }

    private function decisionAttrs(RatioBotConfig $config, array $metadata = []): array
    {
        return [
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 100.0,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata,
            'decided_at' => now(),
        ];
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'v2_enabled' => true,
            'algorithm' => 'ratio_threshold',
            'stage_buy_allocations' => [40, 20, 20],
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }
}
