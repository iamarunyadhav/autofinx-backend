<?php

namespace Tests\Feature\Market;

use App\Console\Commands\MarketWsConsumerCommand;
use App\Domain\Market\LiveCandleCacheService;
use App\Domain\Market\RealtimeMarketFeedService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix, items 10-11: market:feed-diagnostics gets a
 * lanes.public/lanes.market breakdown, and per-symbol output distinguishes
 * QUOTE health from CANDLE health -- a fresh quote no longer implies a fresh
 * candle. This is reporting-only; no execution/prediction gating is touched
 * or asserted here.
 */
class MarketFeedDiagnosticsFuturesLaneTest extends TestCase
{
    protected function tearDown(): void
    {
        Redis::del('live-candles:binance_futures:DIAGUSDT:1m');
        @unlink(MarketWsConsumerCommand::heartbeatPathFor('futures', 'public'));
        @unlink(MarketWsConsumerCommand::heartbeatPathFor('futures', 'market'));

        parent::tearDown();
    }

    public function test_fresh_quote_with_a_stale_candle_is_reported_degraded_not_healthy(): void
    {
        // Simulate the real incident directly: the candle cache's last event
        // is old (as if kline frames stopped arriving), while the quote
        // Redis state is genuinely fresh right now (as if depth kept
        // flowing) -- exactly the divergence this whole hotfix exists for.
        $oldEventMs = (int) floor(microtime(true) * 1000) - 200_000; // 200s old > CRYPTO CANDLE stale_after_ms (180s)
        app(LiveCandleCacheService::class)->upsert('binance_futures', 'DIAGUSDT', '1m', [
            'timestamp' => $oldEventMs,
            'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 1,
            'opens_at' => now()->subMilliseconds(200_000)->toIso8601String(),
        ], false, $oldEventMs);

        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'diagusdt@depth5@500ms',
            'data' => [
                'e' => 'depthUpdate',
                'E' => (int) floor(microtime(true) * 1000),
                's' => 'DIAGUSDT',
                'b' => [['100.00', '1.0']],
                'a' => [['100.10', '1.0']],
            ],
        ], 'futures', false);

        Redis::set('autofinx:market-feed:binance:futures:worker', json_encode(['subscriptions' => ['DIAGUSDT']]));

        Artisan::call('market:feed-diagnostics', [
            '--market-type' => 'futures',
            '--timeframe' => '1m',
            '--json' => true,
        ]);

        $result = json_decode(Artisan::output(), true);
        $row = collect($result['symbols'])->firstWhere('symbol', 'DIAGUSDT');

        $this->assertNotNull($row);
        $this->assertTrue($row['quote_fresh'], 'Quote must report fresh -- it was just ingested.');
        $this->assertSame('STALE', $row['candle_health']);
        $this->assertSame('DEGRADED', $row['status'], 'Fresh quote + stale candle must never report globally healthy.');
        $this->assertGreaterThanOrEqual(180_000, $row['open_candle_age_ms']);
    }

    public function test_lanes_breakdown_reads_each_lanes_own_worker_and_heartbeat_state(): void
    {
        Redis::set('autofinx:market-feed:binance:futures:worker', json_encode(['subscriptions' => ['DIAGUSDT']]));
        Redis::set('autofinx:market-feed:binance:futures:public:worker', json_encode([
            'status' => 'LIVE',
            'streams' => ['depth5@500ms'],
        ]));
        Redis::set('autofinx:market-feed:binance:futures:market:worker', json_encode([
            'status' => 'RECOVERING',
            'streams' => ['kline_1m', 'kline_5m'],
        ]));

        file_put_contents(MarketWsConsumerCommand::heartbeatPathFor('futures', 'public'), json_encode([
            'last_message_epoch' => time(),
            'reconnect_count' => 0,
            'transport' => ['received' => 10, 'emitted' => 10],
        ]));
        file_put_contents(MarketWsConsumerCommand::heartbeatPathFor('futures', 'market'), json_encode([
            'last_message_epoch' => time() - 500,
            'reconnect_count' => 7,
            'transport' => ['received' => 0, 'emitted' => 0],
        ]));

        Artisan::call('market:feed-diagnostics', [
            '--market-type' => 'futures',
            '--timeframe' => '1m',
            '--json' => true,
        ]);

        $result = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('lanes', $result);
        $this->assertSame('LIVE', $result['lanes']['public']['worker']['status']);
        $this->assertSame(['depth5@500ms'], $result['lanes']['public']['streams']);
        $this->assertSame(0, $result['lanes']['public']['reconnect_count']);

        $this->assertSame('RECOVERING', $result['lanes']['market']['worker']['status']);
        $this->assertSame(['kline_1m', 'kline_5m'], $result['lanes']['market']['streams']);
        $this->assertSame(7, $result['lanes']['market']['reconnect_count']);
    }
}
