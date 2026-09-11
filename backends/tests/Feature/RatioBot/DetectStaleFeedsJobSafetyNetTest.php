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
use App\Models\Setting;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Covers the conservative safety-net self-heal added to
 * DetectStaleFeedsJob::handle(): when THIS job's own freshness check
 * confirms a symbol is genuinely fresh (never off elapsed time, never off
 * the mere absence of a stale detection, and never off a market-closed
 * skip), and that symbol's (or the workspace-wide) stale-feed breaker is
 * latched active, it self-clears with source
 * 'stale_feed_detector_safety_net' — distinct from both the manual
 * 'monitoring' activation source and the websocket-driven
 * 'market_feed_ingest' clear source.
 */
class DetectStaleFeedsJobSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // ingest() below writes to Redis, which RefreshDatabase does not
        // reset between tests — without this, ticks written by one test
        // survive into whichever test runs next in the same process and
        // can desync its market-feed expectations.
        $this->clearMarketRedis('BTCUSDT');
        $this->clearMarketRedis('ETHUSDT');
        $this->clearMarketRedis('EURUSD');

        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_confirmed_fresh_symbol_clears_its_own_latched_breaker(): void
    {
        $workspace = Workspace::factory()->create();
        $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot');

        // Ingest the fresh tick FIRST (feed is genuinely healthy from this
        // point on), THEN latch the breaker active — this reproduces the
        // real bug's shape: a transient hiccup activates the breaker
        // *after* the feed already recovered, so ingest's own
        // clearRecoveredWorkspaceBreakers() fast path (which only runs as a
        // side effect of a NEW tick arriving) never gets a chance to fire
        // again. Only this job's own safety net, on its next scheduled run,
        // can un-latch it.
        $this->ingestTick('BTCUSDT', 65000.5);

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'BTCUSDT');

        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));

        $this->runJob();

        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));

        $state = $breakers->staleFeedState((string) $workspace->id, 'BTCUSDT');
        $this->assertSame('stale_feed_detector_safety_net', $state['source']);
        $this->assertSame('scheduled_detector_confirmed_fresh_safety_net', $state['context']['reason'] ?? null);
    }

    public function test_still_stale_symbol_never_auto_clears(): void
    {
        $workspace = Workspace::factory()->create();
        $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot');

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'BTCUSDT');

        // Deliberately no ingest() — the symbol stays genuinely stale.
        $this->runJob();

        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));
        $state = $breakers->staleFeedState((string) $workspace->id, 'BTCUSDT');
        $this->assertSame('monitoring', $state['source']);
    }

    public function test_not_active_breaker_with_fresh_snapshot_is_a_true_noop(): void
    {
        $workspace = Workspace::factory()->create();
        $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot');

        $breakers = app(OperationalBreakerService::class);
        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));

        $this->ingestTick('BTCUSDT', 65000.5);

        $this->runJob();

        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));

        // No safety-net clear should ever have been persisted for a breaker
        // that was never active — assert no Setting row was written at all.
        $this->assertSame(
            0,
            Setting::query()
                ->where('group', 'ops')
                ->where('key', 'stale_feed_breaker:BTCUSDT')
                ->where('workspace_id', (string) $workspace->id)
                ->count()
        );
    }

    public function test_workspace_wide_breaker_only_clears_when_every_selected_symbol_is_fresh(): void
    {
        $workspace = Workspace::factory()->create();
        $btcBotOne = $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot One');
        $btcBotTwo = $this->makeBot($workspace->id, 'BTCUSDT', 'BTC Bot Two');
        $ethBot = $this->makeBot($workspace->id, 'ETHUSDT', 'ETH Bot');

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'reason' => 'simulated_workspace_wide_latch',
        ]);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'symbol' => 'ETHUSDT',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'ETHUSDT');

        // Only ETHUSDT gets a fresh tick; BTCUSDT (shared by two bots in
        // this workspace) never receives one and stays genuinely stale.
        $this->ingestTick('ETHUSDT', 3200.25);

        $this->runJob();

        // One still-stale symbol (BTCUSDT) must block the workspace-wide
        // clear entirely.
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id));

        // But per-symbol clearing still isolates correctly: the genuinely
        // fresh ETHUSDT symbol's own breaker self-heals regardless.
        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'ETHUSDT'));
        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspace->id, $ethBot->normalizedSymbol()));

        // BTCUSDT itself (still stale) was never latched here, but confirm
        // the job actually (re)activated it rather than silently ignoring
        // it, and that the two BTC bots share that same symbol-scoped
        // state.
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, $btcBotOne->normalizedSymbol()));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, $btcBotTwo->normalizedSymbol()));
    }

    public function test_multi_workspace_isolation(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        // Only workspace A has an active bot — the job only ever iterates
        // over workspaces with enabled bots, so workspace B's manually
        // pre-activated breaker (for the SAME symbol) is never even visited
        // by this run. If clearing leaked across workspaces, workspace B's
        // breaker would flip too; it must not.
        $this->makeBot($workspaceA->id, 'BTCUSDT', 'BTC Bot A');

        $this->ingestTick('BTCUSDT', 65000.5);

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspaceA->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'BTCUSDT');
        $breakers->activateStaleFeedBreaker((string) $workspaceB->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'BTCUSDT');

        $this->runJob();

        $this->assertFalse($breakers->isStaleFeedBreakerActive((string) $workspaceA->id, 'BTCUSDT'));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspaceB->id, 'BTCUSDT'));
    }

    public function test_market_closed_snapshot_never_triggers_a_clear(): void
    {
        // EURUSD is configured as FOREX with a session_based schedule (see
        // config/market_data.php), which is closed on weekends regardless
        // of provider — pin "now" to a Saturday so this is deterministic.
        Carbon::setTestNow(Carbon::parse('2024-01-06 12:00:00', 'UTC'));
        $this->assertSame(6, Carbon::now()->dayOfWeekIso, 'sanity check: pinned date must be a Saturday');

        $workspace = Workspace::factory()->create();
        $this->makeBot($workspace->id, 'EURUSD', 'EURUSD Bot');

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'symbol' => 'EURUSD',
            'reason' => 'simulated_stuck_latch',
        ], 'monitoring', 'EURUSD');
        $breakers->activateStaleFeedBreaker((string) $workspace->id, [
            'reason' => 'simulated_workspace_wide_latch',
        ]);

        // Deliberately no ingest() — the market-closed branch is reached
        // before is_fresh is ever consulted, so this proves the skip alone
        // (not a genuine confirmation) never clears anything.
        $this->runJob();

        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'EURUSD'));
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id));
    }

    private function ingestTick(string $symbol, float $price): void
    {
        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => strtolower($symbol) . '@trade',
            'data' => [
                'e' => 'trade',
                's' => $symbol,
                'p' => (string) $price,
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);
    }

    private function runJob(): void
    {
        (new DetectStaleFeedsJob(30))->handle(
            app(OperationalBreakerService::class),
            app(MarketFreshnessService::class),
            app(MarketRecoveryManager::class),
            app(MarketSnapshotService::class),
        );
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
