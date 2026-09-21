<?php

namespace Tests\Unit\Ops;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\TradingRuntimeHealthService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for the confirmed real-money UI bug: the heartbeat-file
 * fallback branch of TradingRuntimeHealthService::marketFeed() used to read
 * age_seconds/symbol/last_tick_at from the heartbeat file's TOP-LEVEL
 * aggregate (whichever symbol happened to tick most recently across every
 * stream the daemon subscribes to — including symbols this workspace never
 * requires), instead of the specific required symbol actually being
 * evaluated. matchedRequiredSymbolHeartbeat() is the new, pure (no file I/O)
 * matching logic that fixes this — unit tested directly here so this
 * coverage is deterministic and never races against a real, locally-running
 * market:ws-consume daemon that continuously rewrites the real heartbeat
 * files on disk (a genuine hazard confirmed in this dev environment; see the
 * file-based FutureTradeRuntimeStatusLiveFeedPerSymbolTest coverage of the
 * same underlying bug in the command that reads only a single heartbeat
 * file, where that specific race does not apply).
 */
class TradingRuntimeHealthServiceHeartbeatPerSymbolTest extends TestCase
{
    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function invokeMatch(mixed $data, string $scope, array $requiredFeeds): ?array
    {
        $service = new TradingRuntimeHealthService(\Mockery::mock(MarketPriceCacheService::class));
        $method = new ReflectionMethod(TradingRuntimeHealthService::class, 'matchedRequiredSymbolHeartbeat');
        $method->setAccessible(true);

        return $method->invoke($service, $data, $scope, $requiredFeeds);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    public function test_matches_the_required_symbols_own_per_symbol_entry_over_a_fresher_unrelated_symbol(): void
    {
        $now = time();
        $data = [
            'market_type' => 'futures',
            'last_message_epoch' => $now,
            'symbol' => 'ETHUSDT',
            'per_symbol' => [
                'BTCUSDT' => ['last_message_epoch' => $now - 500, 'last_message_at' => date('c', $now - 500)],
                'ETHUSDT' => ['last_message_epoch' => $now, 'last_message_at' => date('c', $now)],
            ],
        ];
        $requiredFeeds = [
            ['symbol' => 'BTCUSDT', 'market_type' => 'futures'],
        ];

        [$symbol, $entry] = $this->invokeMatch($data, 'all', $requiredFeeds);

        $this->assertSame('BTCUSDT', $symbol);
        $this->assertSame($now - 500, $entry['last_message_epoch']);
    }

    public function test_matches_the_required_symbols_own_fresh_entry_even_when_an_unrelated_symbol_is_the_stale_aggregate(): void
    {
        $now = time();
        $data = [
            'market_type' => 'futures',
            'last_message_epoch' => $now - 500,
            'symbol' => 'ETHUSDT',
            'per_symbol' => [
                'BTCUSDT' => ['last_message_epoch' => $now, 'last_message_at' => date('c', $now)],
                'ETHUSDT' => ['last_message_epoch' => $now - 500, 'last_message_at' => date('c', $now - 500)],
            ],
        ];
        $requiredFeeds = [
            ['symbol' => 'BTCUSDT', 'market_type' => 'futures'],
        ];

        [$symbol, $entry] = $this->invokeMatch($data, 'all', $requiredFeeds);

        $this->assertSame('BTCUSDT', $symbol);
        $this->assertSame($now, $entry['last_message_epoch']);
    }

    public function test_returns_null_when_the_heartbeat_files_market_type_does_not_match_any_required_feed_in_all_scope(): void
    {
        // freshestHeartbeat() for scope='all' can pick either the spot or
        // futures file depending on which ticked more recently -- a
        // futures-required symbol must never be satisfied by the spot
        // daemon's per_symbol map just because that file happened to win.
        $now = time();
        $data = [
            'market_type' => 'spot',
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => $now]],
        ];
        $requiredFeeds = [['symbol' => 'BTCUSDT', 'market_type' => 'futures']];

        $this->assertNull($this->invokeMatch($data, 'all', $requiredFeeds));
    }

    public function test_returns_null_when_the_heartbeat_file_has_no_per_symbol_data(): void
    {
        // Backward compatibility: an older heartbeat file (or one from
        // before this fix shipped) has no per_symbol key at all.
        $requiredFeeds = [['symbol' => 'BTCUSDT', 'market_type' => 'futures']];

        $this->assertNull($this->invokeMatch(['market_type' => 'futures'], 'all', $requiredFeeds));
        $this->assertNull($this->invokeMatch(null, 'all', $requiredFeeds));
    }

    public function test_returns_null_when_no_symbols_are_required(): void
    {
        $data = ['market_type' => 'futures', 'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => time()]]];

        $this->assertNull($this->invokeMatch($data, 'all', []));
    }

    public function test_picks_the_freshest_among_multiple_required_symbols(): void
    {
        $now = time();
        $data = [
            'market_type' => 'futures',
            'per_symbol' => [
                'BTCUSDT' => ['last_message_epoch' => $now - 100],
                'ETHUSDT' => ['last_message_epoch' => $now - 10],
            ],
        ];
        $requiredFeeds = [
            ['symbol' => 'BTCUSDT', 'market_type' => 'futures'],
            ['symbol' => 'ETHUSDT', 'market_type' => 'futures'],
        ];

        [$symbol] = $this->invokeMatch($data, 'all', $requiredFeeds);

        $this->assertSame('ETHUSDT', $symbol);
    }

    public function test_required_symbol_missing_from_per_symbol_data_falls_through_to_null(): void
    {
        // The required symbol has never ticked on this daemon at all (no
        // entry in per_symbol) -- must not silently match some other symbol.
        $data = [
            'market_type' => 'futures',
            'per_symbol' => ['ETHUSDT' => ['last_message_epoch' => time()]],
        ];
        $requiredFeeds = [['symbol' => 'BTCUSDT', 'market_type' => 'futures']];

        $this->assertNull($this->invokeMatch($data, 'all', $requiredFeeds));
    }

    public function test_scope_spot_or_futures_skips_the_file_market_type_guard_since_required_feeds_are_already_prefiltered(): void
    {
        // marketFeed() pre-filters $requiredFeeds to a single market type
        // before calling heartbeatFeed() when $scope is 'spot' or 'futures'
        // (only 'all' mixes both), so the guard is deliberately only applied
        // when $scope === 'all'.
        $now = time();
        $data = [
            'market_type' => 'futures',
            'per_symbol' => ['BTCUSDT' => ['last_message_epoch' => $now]],
        ];
        $requiredFeeds = [['symbol' => 'BTCUSDT', 'market_type' => 'futures']];

        [$symbol] = $this->invokeMatch($data, 'futures', $requiredFeeds);

        $this->assertSame('BTCUSDT', $symbol);
    }
}
