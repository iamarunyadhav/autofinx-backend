<?php

namespace Tests\Feature\RatioBot;

use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\Ops\OperationalBreakerService;
use App\Jobs\DetectStaleFeedsJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Reproduces the exact scenario reported: 3 ratio bots in one workspace, two
 * of them (BTCUSDT) sharing a stale feed and one (ETHUSDT) healthy. Before
 * the fix, OperationalBreakerService::activateStaleFeedBreaker() only ever
 * wrote a single workspace-wide key, so one stale symbol would trip the
 * breaker for every OTHER symbol's bots too. This proves: bots on the SAME
 * stale symbol correctly share the block (that's the same real feed), while
 * a bot on a different, healthy symbol in the same workspace is unaffected.
 */
class RatioBotStaleFeedSymbolIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // ingest() below writes to Redis, which RefreshDatabase does not
        // reset between tests — without this, the ETHUSDT tick this test
        // writes survives into whichever test runs next in the same
        // process and can desync its market-feed expectations.
        $this->clearMarketRedis('BTCUSDT');
        $this->clearMarketRedis('ETHUSDT');

        // The RealtimeMarketFeedService static-cache reset that this test
        // also needs (Symbol id cache surviving past RefreshDatabase) is
        // handled centrally in Tests\TestCase::tearDown() for every test.
        parent::tearDown();
    }

    public function test_stale_feed_on_one_symbol_does_not_block_bots_on_a_different_healthy_symbol(): void
    {
        $workspace = Workspace::factory()->create();
        $this->clearMarketRedis('BTCUSDT');
        $this->clearMarketRedis('ETHUSDT');

        $btcBotOne = $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot One');
        $btcBotTwo = $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot Two');
        $ethBot = $this->makeBot($workspace->id, 'ETHUSDT', 'ETH Bot');

        // ETHUSDT has a fresh tick; BTCUSDT has never received one (stale by
        // definition) — exactly the reported "3 bots, one symbol stale"
        // shape.
        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'ethusdt@trade',
            'data' => [
                'e' => 'trade',
                's' => 'ETHUSDT',
                'p' => '3200.25',
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);

        (new DetectStaleFeedsJob(30))->handle(
            app(OperationalBreakerService::class),
            app(MarketFreshnessService::class),
            app(MarketRecoveryManager::class),
            app(MarketSnapshotService::class),
        );

        $breakers = app(OperationalBreakerService::class);

        // Both BTCUSDT bots correctly share the same block — they consume
        // the same real feed for the same symbol.
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, $btcBotOne->normalizedSymbol()));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, $btcBotTwo->normalizedSymbol()));

        // The ETHUSDT bot in the SAME workspace must not be affected by
        // BTCUSDT's staleness.
        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'ETHUSDT'));
        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, $ethBot->normalizedSymbol()));
    }

    public function test_a_stale_feed_blocked_bot_recovers_when_its_own_symbol_is_fresh_even_if_another_is_stale(): void
    {
        config(['autofinx.runtime.live_feed_side_effect_interval_ms' => 250]);
        $workspace = Workspace::factory()->create();
        $this->clearMarketRedis('BTCUSDT');
        $this->clearMarketRedis('ETHUSDT');
        $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot');
        $ethBot = $this->makeBot($workspace->id, 'ETHUSDT', 'ETH Bot');
        $ethTick = fn () => app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'ethusdt@trade',
            'data' => ['e' => 'trade', 's' => 'ETHUSDT', 'p' => '3200.25', 'E' => now()->getTimestampMs()],
        ], 'spot', false);

        // ETHUSDT is live; BTCUSDT has no ticks, so the detector trips only its breaker.
        $ethTick();
        (new DetectStaleFeedsJob(30))->handle(
            app(OperationalBreakerService::class),
            app(MarketFreshnessService::class),
            app(MarketRecoveryManager::class),
            app(MarketSnapshotService::class),
        );
        app(\App\Domain\RatioBot\RatioBotStateService::class)->block($ethBot, 'stale_feed', 'Latest market price is stale.');
        $this->assertFalse((bool) $ethBot->fresh()->enabled);

        usleep(300_000);   // past the per-symbol side-effect throttle
        $ethTick();

        $this->assertTrue((bool) $ethBot->fresh()->enabled);
        $this->assertNull($ethBot->fresh('state')->state->block_reason_code);
    }

    private function makeBot(string $workspaceId, string $symbol, string $name): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'name' => $name,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
        ]);

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

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
