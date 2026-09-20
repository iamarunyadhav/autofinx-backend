<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\MarketData\MarketSnapshotService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Regression coverage for the confirmed real-money bug:
 * MarketSnapshotService::forRatioBot() always looked up the canonical
 * provider as strtolower($config->exchange ?: 'binance') -- i.e. always the
 * bare spot 'binance' provider -- regardless of $config->marketType(). A
 * futures RatioBotConfig's freshness check therefore silently fell back to
 * (and could "find") the SPOT canonical quote for the same symbol, meaning
 * a genuinely stale futures feed could be reported fresh, feeding directly
 * into whether the bot proceeds to evaluate/execute a real trade.
 *
 * This mirrors DetectStaleFeedsJobMarketTypeIsolationTest's collision shape
 * (same symbol, spot fresh / futures stale and vice versa) but exercises
 * MarketSnapshotService::forRatioBot() directly, which is the actual
 * execution-critical call site shared by RunRatioBotEvaluationJob,
 * RatioBotExecutionService, RatioBotFixActionService,
 * RatioBotRecoverySlotService, and CanonicalQuoteService::forRatioBot().
 */
class MarketSnapshotServiceForRatioBotMarketTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearMarketRedis('binance', 'BTCUSDT');
        $this->clearMarketRedis('binance_futures', 'BTCUSDT');

        parent::tearDown();
    }

    public function test_futures_bot_checks_freshness_against_the_futures_feed_not_the_spot_feed(): void
    {
        $workspace = Workspace::factory()->create();
        $futuresBot = $this->makeBot($workspace->id, 'BTCUSDT', 'futures');

        // Only the SPOT feed gets a fresh tick; the futures feed for the
        // exact same symbol never receives one and stays genuinely stale.
        $this->ingestTick('BTCUSDT', 65000.5, 'spot');

        $snapshot = app(MarketSnapshotService::class)->forRatioBot($futuresBot);

        $this->assertSame('binance_futures', $snapshot['provider']);
        $this->assertFalse(
            $snapshot['is_fresh'],
            'a futures bot must never be reported fresh off a fresh SPOT tick for the same symbol'
        );
    }

    public function test_futures_bot_reports_fresh_once_the_futures_feed_itself_ticks(): void
    {
        $workspace = Workspace::factory()->create();
        $futuresBot = $this->makeBot($workspace->id, 'BTCUSDT', 'futures');

        $this->ingestTick('BTCUSDT', 65000.5, 'futures');

        $snapshot = app(MarketSnapshotService::class)->forRatioBot($futuresBot);

        $this->assertSame('binance_futures', $snapshot['provider']);
        $this->assertTrue($snapshot['is_fresh']);
        $this->assertEqualsWithDelta(65000.5, $snapshot['price'], 0.0001);
    }

    public function test_spot_bot_behavior_is_unchanged_and_still_checks_the_spot_feed(): void
    {
        $workspace = Workspace::factory()->create();
        $spotBot = $this->makeBot($workspace->id, 'BTCUSDT', 'spot');

        // Only the FUTURES feed ticks; the spot feed stays stale, proving a
        // spot bot is not accidentally reading the futures provider either.
        $this->ingestTick('BTCUSDT', 65000.5, 'futures');

        $snapshotWhileFuturesFresh = app(MarketSnapshotService::class)->forRatioBot($spotBot);
        $this->assertSame('binance', $snapshotWhileFuturesFresh['provider']);
        $this->assertFalse($snapshotWhileFuturesFresh['is_fresh']);

        $this->ingestTick('BTCUSDT', 65010.0, 'spot');

        $snapshotWhileSpotFresh = app(MarketSnapshotService::class)->forRatioBot($spotBot);
        $this->assertSame('binance', $snapshotWhileSpotFresh['provider']);
        $this->assertTrue($snapshotWhileSpotFresh['is_fresh']);
        $this->assertEqualsWithDelta(65010.0, $snapshotWhileSpotFresh['price'], 0.0001);
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

    private function makeBot(string $workspaceId, string $symbol, string $marketType): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'market_type' => $marketType,
            'name' => ucfirst($marketType) . ' Bot ' . $symbol,
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
