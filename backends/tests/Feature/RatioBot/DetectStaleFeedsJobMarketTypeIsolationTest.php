<?php

namespace Tests\Feature\RatioBot;

use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Trading\FuturesPolicyService;
use App\Jobs\DetectStaleFeedsJob;
use App\Models\FutureTradeBotConfig;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Regression coverage for the confirmed real-money bug: OperationalBreakerService's
 * stale-feed breaker key had no market-type discriminator
 * ('stale_feed_breaker:' . $symbol only), so DetectStaleFeedsJob merged spot
 * Ratio Bot symbols and futures Future Trade symbols into one undifferentiated
 * list and checked every one of them against the hardcoded spot ('binance')
 * feed -- meaning a genuinely stale FUTURES feed for a symbol could be
 * reported "fresh" (because the same symbol's SPOT feed happened to be
 * healthy), and FuturesPolicyService::evaluate() -- the real gate in front of
 * live futures order execution -- would read that same collided breaker and
 * wrongly allow the order through.
 *
 * This reproduces the exact collision shape: one workspace running BOTH a
 * spot Ratio Bot AND a futures Future Trade bot on the literal same symbol
 * (BTCUSDT), with only one market's feed actually stale at a time.
 */
class DetectStaleFeedsJobMarketTypeIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearMarketRedis('binance', 'BTCUSDT');
        $this->clearMarketRedis('binance_futures', 'BTCUSDT');

        parent::tearDown();
    }

    public function test_only_futures_feed_stale_blocks_futures_but_not_the_spot_bot_on_the_same_symbol(): void
    {
        $workspace = Workspace::factory()->create();
        $this->makeSpotBot($workspace->id, 'BTCUSDT');
        $this->makeFuturesBotConfig($workspace->id);

        // Only the SPOT feed gets a fresh tick; the futures feed for the
        // exact same symbol never receives one and stays genuinely stale.
        $this->ingestTick('BTCUSDT', 65000.5, 'spot');

        $this->runJob();

        $breakers = app(OperationalBreakerService::class);

        // The futures breaker for BTCUSDT must be latched active -- this is
        // the real-money gate FuturesPolicyService reads before allowing a
        // live futures order.
        $this->assertTrue(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT', 'futures'),
            'a genuinely stale futures feed must never be masked by a fresh spot feed for the same symbol'
        );

        // The spot breaker for the SAME symbol string must remain clear --
        // it has its own genuinely fresh feed and must never be gated by its
        // futures sibling's staleness.
        $this->assertFalse(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT', 'spot'),
            'a stale futures feed must never block a healthy spot bot on the same symbol'
        );

        // End-to-end: the actual real-money gate must block.
        $policy = app(FuturesPolicyService::class)->evaluate((string) $workspace->id, [
            'symbol' => 'BTCUSDT',
            'source' => 'manual_operator',
        ]);
        $this->assertContains('stale_data_breaker_active', $policy['reasons']);
        $this->assertFalse($policy['allow']);
    }

    public function test_only_spot_feed_stale_blocks_spot_but_not_the_futures_bot_on_the_same_symbol(): void
    {
        $workspace = Workspace::factory()->create();
        $this->makeSpotBot($workspace->id, 'BTCUSDT');
        $this->makeFuturesBotConfig($workspace->id);

        // Only the FUTURES feed gets a fresh tick this time; the spot feed
        // for the exact same symbol stays genuinely stale.
        $this->ingestTick('BTCUSDT', 65000.5, 'futures');

        $this->runJob();

        $breakers = app(OperationalBreakerService::class);

        $this->assertTrue(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT', 'spot'),
            'a genuinely stale spot feed must never be masked by a fresh futures feed for the same symbol'
        );
        $this->assertFalse(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT', 'futures'),
            'a stale spot feed must never block a healthy futures bot on the same symbol'
        );

        // End-to-end: the real-money futures gate must NOT be blocked by the
        // unrelated spot staleness for this same symbol.
        $policy = app(FuturesPolicyService::class)->evaluate((string) $workspace->id, [
            'symbol' => 'BTCUSDT',
            'source' => 'manual_operator',
        ]);
        $this->assertNotContains('stale_data_breaker_active', $policy['reasons']);
    }

    private function ingestTick(string $symbol, float $price, string $marketType): void
    {
        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => strtolower($symbol) . '@trade',
            'data' => [
                'e' => 'trade',
                's' => $symbol,
                'p' => (string) $price,
                'E' => now()->getTimestampMs(),
            ],
        ], $marketType, false);
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

    private function makeSpotBot(string $workspaceId, string $symbol): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'market_type' => 'spot',
            'name' => 'Spot Bot ' . $symbol,
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

    private function makeFuturesBotConfig(string $workspaceId): FutureTradeBotConfig
    {
        // Deliberately relies on config('future_trade.defaults.symbols')
        // (which includes BTCUSDT) rather than hand-listing symbols here, so
        // this test tracks the real default rather than a fixture that could
        // silently drift from it.
        return FutureTradeBotConfig::create([
            'workspace_id' => $workspaceId,
            'bot_name' => 'Futures Bot',
            'enabled' => true,
            'settings' => config('future_trade.defaults'),
        ]);
    }

    private function clearMarketRedis(string $exchange, string $symbol): void
    {
        foreach ([
            "autofinx:market:{$exchange}:{$symbol}:quote",
            "autofinx:market:{$exchange}:{$symbol}:health:QUOTE",
            "price:{$exchange}:{$symbol}",
            "ts:{$exchange}:{$symbol}",
            "live-feed:{$exchange}:{$symbol}",
        ] as $key) {
            try {
                Redis::del($key);
            } catch (\Throwable) {
            }
        }
    }
}
