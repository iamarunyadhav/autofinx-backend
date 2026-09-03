<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotTransientFeedRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_feed_block_keeps_bot_enabled_and_does_not_require_operator(): void
    {
        [$config] = $this->makeBot();

        app(RatioBotStateService::class)->markTransientFeedBlock($config, 'feed_stale', 'feed is stale', [
            'age_seconds' => 45,
            'max_age_seconds' => 30,
        ]);

        $config->refresh();
        $state = $config->state()->first();

        $this->assertTrue((bool) $config->enabled);
        $this->assertSame('recovering', $state->feed_status);
        $this->assertSame('transient', $state->blocker_class);
        $this->assertFalse((bool) $state->operator_action_required);
        $this->assertFalse((bool) $state->recovery_action_required);
        $this->assertSame(45, $state->feed_age_seconds);
        $this->assertSame(1, $state->reconnect_attempts);
    }

    public function test_transient_feed_recovery_waits_for_configured_healthy_events(): void
    {
        config()->set('market_data.recovery.healthy_events_required', 2);
        [$config] = $this->makeBot();
        $service = app(RatioBotStateService::class);

        $service->markTransientFeedBlock($config, 'feed_stale', 'feed is stale');
        $service->clearTransientFeedBlock($config->fresh('state'), ['price_age_seconds' => 0]);

        $state = $config->fresh('state')->state;
        $this->assertSame('recovering', $state->feed_status);
        $this->assertSame('feed_stale', $state->block_reason_code);
        $this->assertSame(1, $state->consecutive_healthy_events);

        $service->clearTransientFeedBlock($config->fresh('state'), ['price_age_seconds' => 0]);

        $state = $config->fresh('state')->state;
        $this->assertSame('healthy', $state->feed_status);
        $this->assertNull($state->block_reason_code);
        $this->assertNull($state->blocker_class);
        $this->assertSame(2, $state->consecutive_healthy_events);
    }

    public function test_hard_block_still_disables_and_requires_operator(): void
    {
        [$config] = $this->makeBot();

        app(RatioBotStateService::class)->block($config, 'order_failure_streak', 'manual review required');

        $config->refresh();
        $state = $config->state()->first();

        $this->assertFalse((bool) $config->enabled);
        $this->assertSame(RatioBotState::STATE_BLOCKED, $state->position_state);
        $this->assertTrue((bool) $state->recovery_action_required);
    }

    public function test_ratio_evaluation_job_uses_short_per_bot_uniqueness(): void
    {
        $job = new RunRatioBotEvaluationJob('bot-1');

        $this->assertSame(5, $job->uniqueFor);
        $this->assertSame('ratio-bot-eval:bot-1', $job->uniqueId());
    }

    private function makeBot(): array
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
        ]);

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
            'feed_status' => 'healthy',
        ]);
        $config->setRelation('state', $state);

        return [$config, $state];
    }
}
