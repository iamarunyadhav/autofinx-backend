<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotExecutionService;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\LiveOrderIntentService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec §24 flash-drop protection: BUY=60k, SELL eligible=66k, high=66.5k, then
 * a sudden 66.5k -> 59k move must NOT execute the stale, now-unprofitable
 * decision. Covers RatioBotRiskService::sellNetProfitBlock().
 */
class RatioBotSellNetProfitRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flash_drop_blocks_a_now_unprofitable_sell_even_with_drift_checking_disabled(): void
    {
        [$config, $decision] = $this->makeSellFixture(anchorPrice: 60000, evaluatedPrice: 66500, minimumNetProfitRatio: 0.05, maxDriftBps: 0);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(59000.0); // flash drop
        $orders->shouldNotReceive('place');

        $this->makeService($orders)->execute($config, $decision);

        $decision->refresh();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->execution_status);
        $this->assertSame('net_profit_gate_failed_on_refresh', $decision->metadata['risk_block']['code']);
        // Transient — must never disable the whole bot, only skip this submission.
        $this->assertTrue((bool) $config->fresh()->enabled);
    }

    /** @test */
    public function sell_proceeds_when_fresh_price_still_clears_the_profit_floor(): void
    {
        [$config, $decision] = $this->makeSellFixture(anchorPrice: 60000, evaluatedPrice: 66500, minimumNetProfitRatio: 0.05, maxDriftBps: 0);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(66200.0); // still ~10.3% above cost basis
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub: stop before real order placement'));

        $this->makeService($orders)->execute($config, $decision);

        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('net_profit_gate_failed_on_refresh', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    /** @test */
    public function protective_exit_still_fires_even_when_the_fresh_price_is_worse(): void
    {
        [$config, $decision] = $this->makeSellFixture(anchorPrice: 60000, evaluatedPrice: 66500, minimumNetProfitRatio: 0.05, maxDriftBps: 0, protectiveExit: true);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(59000.0);
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub: stop before real order placement'));

        $this->makeService($orders)->execute($config, $decision);

        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('net_profit_gate_failed_on_refresh', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    private function makeService(LiveOrderIntentService $orders): RatioBotExecutionService
    {
        return new RatioBotExecutionService(
            $orders,
            new RatioBotStateService(),
            Mockery::mock(FuturesOrderIntentService::class),
            Mockery::mock(FuturesConfigService::class),
            new RatioBotRiskService(),
            app(\App\Domain\RatioBot\StrategyManagerService::class),
            app(\App\Domain\Exchange\ExchangeClientFactory::class),
        );
    }

    private function makeSellFixture(
        float $anchorPrice,
        float $evaluatedPrice,
        float $minimumNetProfitRatio,
        float $maxDriftBps,
        bool $protectiveExit = false,
    ): array {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'live',
            'enabled' => true,
            'base_quote_amount' => 100,
            'sell_threshold_ratio' => 0.1,
            'buy_threshold_ratio' => 0.1,
            'connection_id' => (string) Str::uuid(),
            'max_entry_price_drift_bps' => $maxDriftBps,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => $anchorPrice,
            'last_buy_quantity' => 0.001,
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => $evaluatedPrice,
            'anchor_price' => $anchorPrice,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => [
                'minimum_net_profit_ratio' => $minimumNetProfitRatio,
                'protective_exit' => $protectiveExit,
            ],
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
