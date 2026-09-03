<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ratio Bot previously required an operator to call /anchor-buy or
 * /anchor-sell before it would ever trade — a fresh, funded, enabled bot sat
 * in IDLE forever. These tests prove RunRatioBotEvaluationJob::maybeAutoAnchor()
 * resolves the anchor automatically from what the account actually holds,
 * without requiring a manual call, while leaving genuinely ambiguous or
 * undeclared cases untouched.
 */
class RatioBotAutoAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_bot_with_starting_capital_auto_anchors_to_buy_watch(): void
    {
        $config = $this->makeBot(['execution_mode' => 'sandbox', 'base_quote_amount' => 500]);

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame('sell_anchor', $state->active_anchor_type);
        $this->assertEquals(2000.0, (float) $state->active_anchor_price);
    }

    public function test_sandbox_bot_with_declared_sell_starting_entry_stays_idle(): void
    {
        // starting_entry=SELL means the operator is declaring a pre-existing
        // coin position — there is nothing real to auto-detect it from in
        // sandbox mode, so this must still require /anchor-starting-inventory.
        $config = $this->makeBot([
            'execution_mode' => 'sandbox',
            'base_quote_amount' => 500,
            'starting_entry' => 'SELL',
        ]);

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_IDLE, $state->position_state);
    }

    public function test_sandbox_bot_with_no_starting_capital_stays_idle(): void
    {
        $config = $this->makeBot(['execution_mode' => 'sandbox', 'base_quote_amount' => 0]);

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_IDLE, $state->position_state);
    }

    public function test_live_bot_with_only_base_asset_auto_anchors_to_sell_watch(): void
    {
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.5, 'balance' => 0.5],
                'quote' => ['available' => 0.0, 'balance' => 0.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame('buy_anchor', $state->active_anchor_type);
        $this->assertEquals(0.5, (float) $state->last_buy_quantity);
    }

    public function test_live_bot_with_only_quote_asset_auto_anchors_to_buy_watch(): void
    {
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.0, 'balance' => 0.0],
                'quote' => ['available' => 1000.0, 'balance' => 1000.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame('sell_anchor', $state->active_anchor_type);
    }

    public function test_live_bot_with_both_assets_funded_anchors_to_the_larger_side_by_value(): void
    {
        // base 0.75 BTC @ 2000 = 1500 notional > quote 1000 -> base dominates.
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.75, 'balance' => 0.75],
                'quote' => ['available' => 1000.0, 'balance' => 1000.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame('buy_anchor', $state->active_anchor_type);
        $this->assertEquals(0.75, (float) $state->last_buy_quantity);
    }

    public function test_live_bot_with_both_assets_funded_anchors_to_quote_when_it_is_larger(): void
    {
        // base 0.1 BTC @ 2000 = 200 notional < quote 1000 -> quote dominates.
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.1, 'balance' => 0.1],
                'quote' => ['available' => 1000.0, 'balance' => 1000.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame('sell_anchor', $state->active_anchor_type);
    }

    public function test_live_bot_with_both_assets_funded_and_tied_value_prefers_quote(): void
    {
        // base 0.5 BTC @ 2000 = 1000 notional == quote 1000 -> tie resolves to quote.
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.5, 'balance' => 0.5],
                'quote' => ['available' => 1000.0, 'balance' => 1000.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame('sell_anchor', $state->active_anchor_type);
    }

    public function test_live_bot_with_dust_balances_only_stays_idle(): void
    {
        $config = $this->makeBot(['execution_mode' => 'live']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.00001, 'balance' => 0.00001],
                'quote' => ['available' => 0.50, 'balance' => 0.50],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_IDLE, $state->position_state);
    }

    public function test_multiple_live_bots_on_the_same_symbol_split_the_shared_balance_evenly(): void
    {
        // 3 live BTCUSDT bots sharing one real exchange account. Without a
        // fair share, each would auto-anchor against the FULL 1000 USDT
        // independently and all fire the same signal on the same trigger.
        $configA = $this->makeBot(['execution_mode' => 'live', 'symbol' => 'BTCUSDT', 'name' => 'BTC Bot A']);
        $configB = $this->makeBot(['execution_mode' => 'live', 'symbol' => 'BTCUSDT', 'name' => 'BTC Bot B']);
        $configC = $this->makeBot(['execution_mode' => 'live', 'symbol' => 'BTCUSDT', 'name' => 'BTC Bot C']);
        // An unrelated symbol's live bot must not count toward the split.
        $this->makeBot(['execution_mode' => 'live', 'symbol' => 'ETHUSDT', 'name' => 'ETH Bot']);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->times(3)->andReturn([
                'base' => ['available' => 0.0, 'balance' => 0.0],
                'quote' => ['available' => 900.0, 'balance' => 900.0],
            ]);
        });

        foreach ([$configA, $configB, $configC] as $config) {
            $this->invokeAutoAnchor($config, 2000.0);
        }

        foreach ([$configA, $configB, $configC] as $config) {
            $state = $config->fresh('state')->state;
            $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
            // 900 / 3 siblings = 300 quote each -> 300/2000 = 0.15 implied qty.
            $this->assertEqualsWithDelta(0.15, (float) $state->last_sell_quantity, 0.0000001);
        }
    }

    public function test_sandbox_bots_on_the_same_symbol_each_keep_their_own_configured_capital(): void
    {
        // Sandbox bots never share a real wallet, so no split applies.
        $configA = $this->makeBot(['execution_mode' => 'sandbox', 'symbol' => 'BTCUSDT', 'base_quote_amount' => 500, 'name' => 'Sandbox Bot A']);
        $configB = $this->makeBot(['execution_mode' => 'sandbox', 'symbol' => 'BTCUSDT', 'base_quote_amount' => 500, 'name' => 'Sandbox Bot B']);

        $this->invokeAutoAnchor($configA, 2000.0);
        $this->invokeAutoAnchor($configB, 2000.0);

        foreach ([$configA, $configB] as $config) {
            $state = $config->fresh('state')->state;
            $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
            $this->assertEqualsWithDelta(0.25, (float) $state->last_sell_quantity, 0.0000001);
        }
    }

    private function invokeAutoAnchor(RatioBotConfig $config, float $currentPrice): void
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'maybeAutoAnchor');
        $method->setAccessible(true);
        $method->invoke(
            $job,
            $config,
            $currentPrice,
            app(RatioBotRuntimeQueryService::class),
            app(RatioBotStateService::class),
        );
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
        ], $overrides));

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            'feed_status' => 'healthy',
        ]);
        $config->setRelation('state', $state);

        return $config;
    }
}
