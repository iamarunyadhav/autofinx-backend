<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Before the fix, RealtimeMarketFeedService::maybeAutoRecoverStaleSandboxBot
 * only ever re-enabled sandbox bots blocked for a transient exchange/feed
 * reason. A LIVE bot that tripped EXCHANGE_UNHEALTHY or WEBSOCKET_UNHEALTHY
 * stayed disabled forever until an operator manually clicked Resume. This
 * proves live bots now self-heal the same way sandbox bots already did, but
 * only once the underlying breaker has actually cleared and a fresh price is
 * available — a bot whose provider breaker is still active must stay
 * disabled, not get re-enabled on a blind timer.
 */
class RatioBotLiveTransientRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearMarketRedis('BTCUSDT');
        parent::tearDown();
    }

    public function test_live_bot_blocked_for_exchange_unhealthy_self_recovers_once_breaker_clears(): void
    {
        $workspace = Workspace::factory()->create();
        $this->clearMarketRedis('BTCUSDT');

        $config = $this->makeBlockedLiveBot($workspace->id, 'exchange_unhealthy');

        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'btcusdt@trade',
            'data' => [
                'e' => 'trade',
                's' => 'BTCUSDT',
                'p' => '65000.10',
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);

        $this->assertTrue((bool) $config->fresh()->enabled);
    }

    public function test_live_bot_stays_disabled_while_provider_breaker_still_active(): void
    {
        $workspace = Workspace::factory()->create();
        $this->clearMarketRedis('BTCUSDT');

        $config = $this->makeBlockedLiveBot($workspace->id, 'exchange_unhealthy');

        app(OperationalBreakerService::class)->activateProviderOutage(
            (string) $workspace->id,
            'binance',
            'still_down'
        );

        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'btcusdt@trade',
            'data' => [
                'e' => 'trade',
                's' => 'BTCUSDT',
                'p' => '65000.10',
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);

        $this->assertFalse((bool) $config->fresh()->enabled);
    }

    private function makeBlockedLiveBot(string $workspaceId, string $blockReasonCode): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => 'BTCUSDT',
            'name' => 'Live BTC Bot',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'live',
            'enabled' => false,
        ]);

        RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_BLOCKED,
            'block_reason_code' => $blockReasonCode,
            'block_reason_detail' => 'test-induced block',
        ]);

        return $config->refresh()->load('state');
    }

    private function clearMarketRedis(string $symbol): void
    {
        foreach ([
            "autofinx:market:binance:{$symbol}:quote",
            "autofinx:market:binance:{$symbol}:health:QUOTE",
            "market:live:{$symbol}",
            "live-feed:binance:{$symbol}",
            "price:binance:{$symbol}",
            "ts:binance:{$symbol}",
        ] as $key) {
            try {
                Redis::del($key);
            } catch (\Throwable) {
            }
        }
    }
}
