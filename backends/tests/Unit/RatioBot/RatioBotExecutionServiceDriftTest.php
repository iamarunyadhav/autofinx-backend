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

class RatioBotExecutionServiceDriftTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function entry_is_blocked_before_submission_when_price_drift_exceeds_threshold(): void
    {
        [$config, $decision] = $this->makeLiveBuyFixture(decisionPrice: 1000.0, maxDriftBps: 50);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1200.0); // ~1818 bps drift
        $orders->shouldNotReceive('place');

        $this->makeService($orders)->execute($config, $decision);

        $decision->refresh();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->execution_status);
        $this->assertSame('price_drift_exceeded', $decision->metadata['risk_block']['code']);
        $this->assertSame('price_drift_exceeded', $config->fresh('state')->state->block_reason_code);
        // A transient drift block must never disable the whole bot.
        $this->assertTrue((bool) $config->fresh()->enabled);
    }

    /** @test */
    public function entry_proceeds_when_drift_is_within_threshold(): void
    {
        [$config, $decision] = $this->makeLiveBuyFixture(decisionPrice: 1000.0, maxDriftBps: 500);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andReturn(1002.0); // 20 bps drift, within 500
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub: stop before real order placement'));

        $this->makeService($orders)->execute($config, $decision);

        // We don't assert a real fill here (that's a deeper integration
        // concern) — only that the drift guard did NOT block, i.e. the flow
        // reached LiveOrderIntentService::place() (which our stub then
        // throws from, surfacing as an order-failure, not a drift block).
        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('price_drift_exceeded', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    /** @test */
    public function drift_check_is_disabled_when_max_entry_price_drift_bps_is_zero(): void
    {
        [$config, $decision] = $this->makeLiveBuyFixture(decisionPrice: 1000.0, maxDriftBps: 0);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldNotReceive('fetchReferencePrice');
        $orders->shouldReceive('place')->once()->andThrow(new RuntimeException('stub'));

        $this->makeService($orders)->execute($config, $decision);

        $orders->shouldHaveReceived('place')->once();
        $decision->refresh();
        $this->assertNotSame('price_drift_exceeded', (string) data_get($decision->metadata, 'risk_block.code'));
    }

    /** @test */
    public function fails_closed_when_the_fresh_quote_fetch_itself_throws(): void
    {
        [$config, $decision] = $this->makeLiveBuyFixture(decisionPrice: 1000.0, maxDriftBps: 50);

        $orders = Mockery::mock(LiveOrderIntentService::class);
        $orders->shouldReceive('fetchReferencePrice')->once()->andThrow(new RuntimeException('venue unreachable'));
        $orders->shouldNotReceive('place');

        $this->makeService($orders)->execute($config, $decision);

        $decision->refresh();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->execution_status);
        $this->assertSame('price_drift_check_failed', $decision->metadata['risk_block']['code']);
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
            app(\App\Domain\MarketData\MarketSnapshotService::class),
        );
    }

    private function makeLiveBuyFixture(float $decisionPrice, float $maxDriftBps): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'live',
            'enabled' => true,
            'base_quote_amount' => 100,
            'sell_threshold_ratio' => 0.5,
            'buy_threshold_ratio' => 0.25,
            'connection_id' => (string) Str::uuid(),
            'max_entry_price_drift_bps' => $maxDriftBps,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => $decisionPrice,
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
