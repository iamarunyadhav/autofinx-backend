<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotRiskService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotRiskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RatioBotRiskService();
    }

    /** @test */
    public function it_blocks_trade_when_indicator_confirmation_fails(): void
    {
        [$config, $decision] = $this->makeTradeDecision();

        $block = $this->service->preTradeBlockReason($config, $decision, [
            'indicator_confirmation' => [
                'enabled' => true,
                'ok' => false,
                'reasons' => ['volume_too_low', 'market_too_choppy'],
            ],
            'market_health' => [
                'latency_ms' => 45,
                'price_age_seconds' => 0,
            ],
        ]);

        $this->assertNotNull($block);
        $this->assertSame('indicator_confirmation_failed', $block['code']);
    }

    /** @test */
    public function protective_exit_bypasses_indicator_confirmation_failure(): void
    {
        [$config, $decision] = $this->makeTradeDecision([
            'trigger' => 'stop_loss',
            'protective_exit' => true,
        ]);

        $block = $this->service->preTradeBlockReason($config, $decision, [
            'indicator_confirmation' => [
                'enabled' => true,
                'ok' => false,
                'reasons' => ['volume_too_low'],
            ],
            'market_health' => [
                'latency_ms' => 45,
                'price_age_seconds' => 0,
            ],
        ]);

        $this->assertNull($block);
    }

    /** @test */
    public function it_blocks_trade_when_api_latency_limit_is_exceeded(): void
    {
        [$config, $decision] = $this->makeTradeDecision();
        $config->update([
            'strategy_params' => [
                'max_api_latency_ms' => 100,
            ],
        ]);

        $block = $this->service->preTradeBlockReason($config->fresh('state'), $decision, [
            'indicator_confirmation' => [
                'enabled' => false,
                'ok' => true,
            ],
            'market_health' => [
                'latency_ms' => 220,
                'price_age_seconds' => 0,
            ],
        ]);

        $this->assertNotNull($block);
        $this->assertSame('api_latency_high', $block['code']);
    }

    private function makeTradeDecision(array $metadata = []): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'strategy_params' => [
                'use_indicator_confirmation' => true,
            ],
        ]);

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 100,
            'last_buy_quantity' => 1,
            'active_anchor_price' => 100,
            'last_price_timestamp' => now(),
            'feed_status' => 'healthy',
        ]);
        $config->setRelation('state', $state);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 103,
            'anchor_price' => 100,
            'computed_ratio' => 0.03,
            'threshold_used' => 0.02,
            'position_state_before' => RatioBotState::STATE_HOLDING,
            'position_state_after' => RatioBotState::STATE_HOLDING,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata,
            'decided_at' => now(),
        ]);

        return [$config, $decision];
    }
}
