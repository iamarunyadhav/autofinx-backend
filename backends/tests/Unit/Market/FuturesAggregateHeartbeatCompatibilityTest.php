<?php

namespace Tests\Unit\Market;

use App\Console\Commands\MarketWsConsumerCommand;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: RobotSessionService::freshLiveFeedHeartbeat(),
 * FutureTradeRuntimeConnectivityService::marketHeartbeat(),
 * FutureTradeLiveFeedManager::loadHeartbeat(), and
 * TradingRuntimeHealthService::heartbeatFeed() all still read the single
 * combined autofinx-market-ws-heartbeat-futures.json file directly and were
 * deliberately left untouched by this hotfix. writeFuturesAggregateHeartbeat()
 * is what keeps that file alive and accurate now that two lane-specific
 * files are the real source of truth -- these tests exercise its merge
 * behavior directly via reflection (the same pattern MarketWsConsumerSymbolDiffTest
 * already uses for this class's other private helpers).
 */
class FuturesAggregateHeartbeatCompatibilityTest extends TestCase
{
    private string $aggregatePath;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolation (Phase 1 item 3): never unlink/overwrite the REAL
        // aggregate heartbeat a locally running daemon owns (and never race
        // it) -- work in a private, empty storage directory instead.
        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'afx-agg-heartbeat-'.\Illuminate\Support\Str::random(12);
        mkdir($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework', 0777, true);
        $this->app->useStoragePath($this->isolatedStorage);
        $this->aggregatePath = MarketWsConsumerCommand::heartbeatPathFor('futures', null);
        @unlink($this->aggregatePath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework');
        @rmdir($this->isolatedStorage);
        parent::tearDown();
    }

    private function invoke(string $lane, array $snapshot): void
    {
        $method = new ReflectionMethod(MarketWsConsumerCommand::class, 'writeFuturesAggregateHeartbeat');
        $method->setAccessible(true);
        $method->invoke(new MarketWsConsumerCommand, $lane, $snapshot);
    }

    public function test_public_lane_write_creates_the_aggregate_file(): void
    {
        $this->invoke('public', [
            'last_message_epoch' => time(),
            'last_message_at' => now()->toIso8601String(),
            'symbols' => ['BTCUSDT'],
            'streams' => ['depth5@500ms'],
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => time()]],
        ]);

        $this->assertFileExists($this->aggregatePath);
        $decoded = json_decode((string) file_get_contents($this->aggregatePath), true);
        $this->assertSame(['BTCUSDT'], $decoded['symbols']);
        $this->assertArrayHasKey('BTCUSDT', $decoded['per_symbol']);
        $this->assertArrayHasKey('public', $decoded['lanes']);
    }

    public function test_market_lane_write_merges_with_a_prior_public_lane_write_instead_of_overwriting_it(): void
    {
        $this->invoke('public', [
            'last_message_epoch' => time() - 1,
            'last_message_at' => now()->subSecond()->toIso8601String(),
            'symbols' => ['BTCUSDT'],
            'streams' => ['depth5@500ms'],
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => time() - 1, 'stream' => 'btcusdt@depth5@500ms']],
        ]);

        $this->invoke('market', [
            'last_message_epoch' => time(),
            'last_message_at' => now()->toIso8601String(),
            'symbols' => ['BTCUSDT'],
            'streams' => ['kline_1m', 'kline_5m'],
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => time(), 'stream' => 'btcusdt@kline_1m']],
        ]);

        $decoded = json_decode((string) file_get_contents($this->aggregatePath), true);

        // Union of both lanes' streams/symbols, not just the most recent write.
        $this->assertSame(['BTCUSDT'], $decoded['symbols']);
        $this->assertSame(['depth5@500ms', 'kline_1m', 'kline_5m'], $decoded['streams']);
        $this->assertArrayHasKey('public', $decoded['lanes']);
        $this->assertArrayHasKey('market', $decoded['lanes']);
        // Freshness reflects whichever lane ticked most recently (market, here).
        $this->assertSame('btcusdt@kline_1m', $decoded['per_symbol']['BTCUSDT']['stream']);
    }

    public function test_symbol_only_covered_by_public_lane_is_not_dropped_by_a_later_market_lane_write(): void
    {
        $this->invoke('public', [
            'last_message_epoch' => time(),
            'last_message_at' => now()->toIso8601String(),
            'symbols' => ['BTCUSDT', 'ETHUSDT'],
            'streams' => ['depth5@500ms'],
            'per_symbol' => [
                'BTCUSDT' => ['last_message_epoch' => time()],
                'ETHUSDT' => ['last_message_epoch' => time()],
            ],
        ]);

        $this->invoke('market', [
            'last_message_epoch' => time() + 1,
            'last_message_at' => now()->addSecond()->toIso8601String(),
            'symbols' => ['BTCUSDT'],
            'streams' => ['kline_1m'],
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => time() + 1]],
        ]);

        $decoded = json_decode((string) file_get_contents($this->aggregatePath), true);

        $this->assertContains('BTCUSDT', $decoded['symbols']);
        $this->assertContains('ETHUSDT', $decoded['symbols']);
        $this->assertArrayHasKey('ETHUSDT', $decoded['per_symbol']);
    }
}
