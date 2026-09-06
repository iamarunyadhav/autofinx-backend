<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotExecutionService;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\RatioBot\StrategyManagerService;
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
 * Shock Detector (spec §63): reuses StrategyManagerService's existing
 * regime classifier as a hard pre-submit freeze when the market is
 * HIGH_VOLATILITY or LOW_LIQUIDITY, instead of building a second detector.
 */
class RatioBotShockDetectorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function order_is_frozen_when_regime_is_high_volatility(): void
    {
        [$config, $decision] = $this->makeFixture(v2Enabled: true);

        $strategyManager = Mockery::mock(StrategyManagerService::class);
        $strategyManager->shouldReceive('analyze')->once()->andReturn([
            'enabled' => true,
            'regime' => StrategyManagerService::REGIME_HIGH_VOLATILITY,
            'regime_reason' => 'volatility_level=EXTREME',
        ]);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldNotReceive('fetchReferencePrice');
        $orders->shouldNotReceive('place');

        $this->makeService($orders, $strategyManager)->execute($config, $decision);

        $decision->refresh();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->execution_status);
        $this->assertSame('market_shock_detected', $decision->metadata['risk_block']['code']);
        $this->assertTrue((bool) $config->fresh()->enabled, 'a shock freeze is transient — it must never disable the whole bot');
    }

    /** @test */
    public function order_proceeds_when_regime_is_normal(): void
    {
        [$config, $decision] = $this->makeFixture(v2Enabled: true);

        $strategyManager = Mockery::mock(StrategyManagerService::class);
        $strategyManager->shouldReceive('analyze')->once()->andReturn([
            'enabled' => true,
            'regime' => StrategyManagerService::REGIME_RANGE,
            'regime_reason' => 'market_mode=SIDEWAYS',
        ]);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1000.0);
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub: stop before real order placement'));

        $this->makeService($orders, $strategyManager)->execute($config, $decision);

        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('market_shock_detected', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    /** @test */
    public function shock_detector_is_skipped_entirely_when_v2_is_disabled(): void
    {
        [$config, $decision] = $this->makeFixture(v2Enabled: false);

        $strategyManager = Mockery::mock(StrategyManagerService::class);
        $strategyManager->shouldNotReceive('analyze');

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1000.0);
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub'));

        $this->makeService($orders, $strategyManager)->execute($config, $decision);

        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('market_shock_detected', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    private function makeService(LiveOrderIntentService $orders, StrategyManagerService $strategyManager): RatioBotExecutionService
    {
        return new RatioBotExecutionService(
            $orders,
            new RatioBotStateService(),
            Mockery::mock(FuturesOrderIntentService::class),
            Mockery::mock(FuturesConfigService::class),
            new RatioBotRiskService(),
            $strategyManager,
            app(\App\Domain\Exchange\ExchangeClientFactory::class),
            app(\App\Domain\MarketData\MarketSnapshotService::class),
            app(\App\Domain\RatioBot\RatioBotExchangeStopOrderService::class),
            app(\App\Domain\RatioBot\RatioBotFundingCostService::class),
        );
    }

    private function makeFixture(bool $v2Enabled): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'live',
            'enabled' => true,
            'v2_enabled' => $v2Enabled,
            'base_quote_amount' => 100,
            'sell_threshold_ratio' => 0.5,
            'buy_threshold_ratio' => 0.25,
            'connection_id' => (string) Str::uuid(),
            'max_entry_price_drift_bps' => 50,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 1000,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
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
