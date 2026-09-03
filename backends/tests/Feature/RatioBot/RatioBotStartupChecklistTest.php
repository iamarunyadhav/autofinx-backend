<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Ops\OperationalBreakerService;
use App\Jobs\DetectStaleFeedsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RatioBotStartupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
        $this->clearMarketRedis('BTCUSDT');
        $this->clearMarketRedis('ETHUSDT');
    }

    /** @test */
    public function sandbox_bot_checklist_shows_all_six_items_with_correct_shape(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(6, $items);
        $keys = collect($items)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['exchange_connection', 'wallet_balance', 'market_feed', 'symbol_valid', 'exchange_filters', 'risk_state'],
            $keys,
        );

        // Sandbox mode: no live connection/balance/filters to check, and no
        // starting_entry configured — these should skip cleanly, not fail.
        $this->assertSame('skip', collect($items)->firstWhere('key', 'exchange_connection')['status']);
        $this->assertSame('skip', collect($items)->firstWhere('key', 'wallet_balance')['status']);
        $this->assertSame('skip', collect($items)->firstWhere('key', 'exchange_filters')['status']);
        $this->assertSame('pass', collect($items)->firstWhere('key', 'risk_state')['status']);
        // A brand-new bot has never observed a price yet -> market feed
        // legitimately isn't fresh; this is correct, not a bug.
        $this->assertSame('fail', collect($items)->firstWhere('key', 'market_feed')['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function checklist_fails_risk_state_when_kill_switch_is_active(): void
    {
        $config = $this->createConfig(['kill_switch' => true]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $riskItem = collect($response->json('items'))->firstWhere('key', 'risk_state');
        $this->assertSame('fail', $riskItem['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function checklist_fails_exchange_connection_for_live_bot_with_no_connection(): void
    {
        $config = $this->createConfig(['execution_mode' => 'live', 'connection_id' => null]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'exchange_connection');
        $this->assertSame('fail', $item['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function wallet_balance_item_falls_back_to_a_real_check_when_no_starting_entry_is_configured(): void
    {
        // Live-mode bot, no starting_entry declared -> startingEntryReadiness()
        // can't say which single asset matters and used to make this item
        // always show "skip" for every such bot. It should now still run a
        // meaningful fallback check (here: workspace trading mode isn't
        // live in this test environment) instead of the old blanket
        // "no starting_entry configured" skip reason.
        $config = $this->createConfig(['execution_mode' => 'live', 'starting_entry' => null]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'wallet_balance');
        $this->assertSame('skip', $item['status']);
        $this->assertStringNotContainsString('no starting_entry configured', $item['detail']);
    }

    /** @test */
    public function fresh_selected_symbol_snapshot_clears_stale_breaker_and_passes_market_feed(): void
    {
        // evaluation_mode must be a realtime mode here: a polling-mode bot's
        // market_feed checklist item is judged purely from its own
        // last_price_timestamp (see effectiveFeedStatus's early return for
        // !usesRealtimeFeed()) and never touches the stale-feed breaker at
        // all — that's correct for polling mode, but it would make this
        // specific test (which is about the breaker-clearing path) exercise
        // the wrong branch and pass for the wrong reason.
        $config = $this->createConfig(['stale_feed_timeout_seconds' => 30, 'evaluation_mode' => 'websocket']);
        // Activated on the symbol-scoped key, matching how DetectStaleFeedsJob
        // and the evaluation/readiness paths actually key this breaker post-fix
        // (workspace_id + symbol, not workspace_id alone) — see
        // AUTOFINX_PHASE1B_ARCHITECTURE_AUDIT_2026-09-02.md §4/§16 item 3.
        app(OperationalBreakerService::class)->activateStaleFeedBreaker((string) $this->workspace->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'old_stale_state',
        ], 'monitoring', 'BTCUSDT');

        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'btcusdt@trade',
            'data' => [
                'e' => 'trade',
                's' => 'BTCUSDT',
                'p' => '65000.50',
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'market_feed');
        $this->assertSame('pass', $item['status']);
        $this->assertFalse(app(OperationalBreakerService::class)->isStaleFeedBreakerActive((string) $this->workspace->id, 'BTCUSDT'));
    }

    /** @test */
    public function scheduled_detector_keeps_btc_stale_when_eth_is_fresh(): void
    {
        $btc = $this->createConfig([
            'symbol' => 'BTCUSDT',
            'enabled' => true,
            'stale_feed_timeout_seconds' => 30,
        ]);
        $this->createConfig([
            'symbol' => 'ETHUSDT',
            'enabled' => true,
            'stale_feed_timeout_seconds' => 30,
        ]);

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
            app(\App\Domain\MarketData\MarketFreshnessService::class),
            app(\App\Domain\MarketData\MarketRecoveryManager::class),
            app(\App\Domain\MarketData\MarketSnapshotService::class),
        );

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$btc->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'market_feed');
        $this->assertSame('fail', $item['status']);
        $this->assertStringContainsString('STALE_FEED', $item['detail']);
    }

    /** @test */
    public function scheduled_detector_does_not_clear_stale_breaker_without_new_ingest_tick(): void
    {
        $this->createConfig(['enabled' => true, 'stale_feed_timeout_seconds' => 30]);
        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'btcusdt@trade',
            'data' => [
                'e' => 'trade',
                's' => 'BTCUSDT',
                'p' => '65000.50',
                'E' => now()->getTimestampMs(),
            ],
        ], 'spot', false);

        app(OperationalBreakerService::class)->activateStaleFeedBreaker((string) $this->workspace->id, [
            'symbol' => 'BTCUSDT',
            'reason' => 'must_wait_for_ingest_owner',
        ]);

        (new DetectStaleFeedsJob(30))->handle(
            app(OperationalBreakerService::class),
            app(\App\Domain\MarketData\MarketFreshnessService::class),
            app(\App\Domain\MarketData\MarketRecoveryManager::class),
            app(\App\Domain\MarketData\MarketSnapshotService::class),
        );

        $this->assertTrue(app(OperationalBreakerService::class)->isStaleFeedBreakerActive((string) $this->workspace->id));
    }

    /** @test */
    public function market_snapshot_prefers_canonical_market_live_key_for_execution_freshness(): void
    {
        $nowMs = now()->getTimestampMs();
        Redis::setex('market:live:BTCUSDT', 120, json_encode([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'price' => 65123.45,
            'event_timestamp_ms' => $nowMs,
            'received_at_ms' => $nowMs,
            'received_at' => now()->toIso8601String(),
            'source' => 'binance_websocket',
            'stream' => 'btcusdt@trade',
            'event_type' => 'trade',
            'market_type' => 'spot',
            'worker_id' => 'worker-a',
            'connection_id' => 'connection-a',
        ]));

        $snapshot = app(MarketSnapshotService::class)->getLiveSnapshot('binance', 'BTCUSDT', 'binance', 30);

        $this->assertSame('canonical_market_live_redis', $snapshot['source']);
        $this->assertSame(65123.45, $snapshot['price']);
        $this->assertTrue($snapshot['is_fresh']);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
        ], $overrides));

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
