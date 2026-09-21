<?php

namespace Tests\Unit\Market;

use App\Domain\Market\RealtimeMarketFeedService;
use App\Models\FutureTradeBotConfig;
use App\Models\MarketFeedStatus;
use App\Models\RatioBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Regression + perf coverage for decoupling touchWorkspaceFeedStatuses() and
 * clearRecoveredWorkspaceBreakers()'s workspace-interest lookups
 * (RatioBotConfig/FutureTradeBotConfig/RobotSession) from the synchronous
 * per-frame websocket ingest hot path. Before the fix, EVERY throttled
 * side-effect pass re-ran all of these queries from scratch — including an
 * UNFILTERED "every enabled Future Trade bot in every workspace" query used
 * only to filter the result down in PHP afterward. After the fix, that
 * lookup is served from an in-process cache rebuilt at most once per
 * autofinx.runtime.live_feed_workspace_cache_ttl_ms, matching the existing
 * ~60s active-symbol re-resolution pattern in MarketWsConsumerCommand.
 */
class RealtimeMarketFeedServiceWorkspaceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach ([
            'autofinx:market:binance:BTCUSDT:quote',
            'autofinx:market:binance:BTCUSDT:health:QUOTE',
            'market:live:BTCUSDT',
            'live-feed:binance:BTCUSDT',
            'price:binance:BTCUSDT',
            'ts:binance:BTCUSDT',
        ] as $key) {
            try {
                Redis::del($key);
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function test_future_trade_bot_config_is_not_requeried_on_every_ingest_call_within_the_cache_ttl(): void
    {
        config(['autofinx.runtime.live_feed_side_effect_interval_ms' => 0]);
        config(['autofinx.runtime.live_feed_workspace_cache_ttl_ms' => 60000]);

        $workspace = Workspace::factory()->create();
        RatioBotConfig::create([
            'workspace_id' => $workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'name' => 'Spot Bot',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
        ]);
        // Deliberately relies on config('future_trade.defaults.symbols')
        // (which includes BTCUSDT), matching the real "no explicit
        // settings->symbols override" case the query-level filter can't
        // safely replicate in SQL.
        FutureTradeBotConfig::create([
            'workspace_id' => $workspace->id,
            'bot_name' => 'Futures Bot',
            'enabled' => true,
            'settings' => config('future_trade.defaults'),
        ]);

        $feeds = app(RealtimeMarketFeedService::class);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        for ($i = 0; $i < 5; $i++) {
            $feeds->ingest([
                'stream' => 'btcusdt@trade',
                'data' => [
                    'e' => 'trade',
                    's' => 'BTCUSDT',
                    'p' => (string) (65000 + $i),
                    'E' => now()->getTimestampMs(),
                ],
            ], 'spot', false);
        }

        $futureTradeQueries = collect($queries)
            ->filter(fn (string $sql) => str_contains($sql, 'future_trade_bot_configs'))
            ->count();

        $this->assertLessThanOrEqual(
            1,
            $futureTradeQueries,
            'FutureTradeBotConfig must be queried at most once per cache TTL window, not once per ingest() call.'
        );

        // Functional correctness is unaffected: the workspace still gets a
        // MarketFeedStatus row from the cached membership lookup.
        $this->assertDatabaseHas('market_feed_status', [
            'workspace_id' => $workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
        ]);
    }

    public function test_a_bot_created_after_the_cache_is_already_built_is_picked_up_once_the_cache_goes_stale(): void
    {
        // TTL of 0 effectively disables caching for this test so a
        // freshly-created bot is visible on the very next ingest() call —
        // proving the cache never permanently hides a real bot, only defers
        // discovery by at most the configured TTL.
        config(['autofinx.runtime.live_feed_side_effect_interval_ms' => 0]);
        config(['autofinx.runtime.live_feed_workspace_cache_ttl_ms' => 1000]);

        $feeds = app(RealtimeMarketFeedService::class);

        // First tick with no bots configured yet — builds an empty cache.
        $feeds->ingest([
            'stream' => 'btcusdt@trade',
            'data' => ['e' => 'trade', 's' => 'BTCUSDT', 'p' => '65000.10', 'E' => now()->getTimestampMs()],
        ], 'spot', false);

        $workspace = Workspace::factory()->create();
        RatioBotConfig::create([
            'workspace_id' => $workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'name' => 'Spot Bot',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
        ]);

        // Force the cache stale so the new bot is discovered immediately
        // instead of waiting out the full TTL in this fast test.
        usleep(1100 * 1000);

        $feeds->ingest([
            'stream' => 'btcusdt@trade',
            'data' => ['e' => 'trade', 's' => 'BTCUSDT', 'p' => '65010.10', 'E' => now()->getTimestampMs()],
        ], 'spot', false);

        $this->assertDatabaseHas('market_feed_status', [
            'workspace_id' => $workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
        ]);
    }
}
