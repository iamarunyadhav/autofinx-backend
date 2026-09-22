<?php

namespace Tests\Unit\Market;

use App\Console\Commands\MarketWsConsumerCommand;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: lock keys and heartbeat file paths are the
 * mechanism that keeps the two futures transport lanes from colliding with
 * each other while leaving spot (and any legacy non-lane futures run)
 * completely unchanged.
 */
class MarketWsConsumerLaneKeysTest extends TestCase
{
    public function test_spot_lock_key_is_unchanged_by_the_lane_refactor(): void
    {
        $this->assertSame(
            'autofinx:lock:market-feed:binance:spot',
            MarketWsConsumerCommand::lockKeyFor('binance', 'spot')
        );
    }

    public function test_legacy_non_lane_futures_lock_key_is_unchanged(): void
    {
        $this->assertSame(
            'autofinx:lock:market-feed:binance:futures',
            MarketWsConsumerCommand::lockKeyFor('binance', 'futures')
        );
    }

    public function test_futures_lane_lock_keys_are_distinct_from_each_other_and_from_the_legacy_key(): void
    {
        $public = MarketWsConsumerCommand::lockKeyFor('binance', 'futures', 'public');
        $market = MarketWsConsumerCommand::lockKeyFor('binance', 'futures', 'market');
        $legacy = MarketWsConsumerCommand::lockKeyFor('binance', 'futures');

        $this->assertSame('autofinx:lock:market-feed:binance:futures:public', $public);
        $this->assertSame('autofinx:lock:market-feed:binance:futures:market', $market);
        $this->assertNotSame($public, $market);
        $this->assertNotSame($public, $legacy);
        $this->assertNotSame($market, $legacy);
    }

    public function test_spot_heartbeat_path_is_unchanged_by_the_lane_refactor(): void
    {
        $this->assertSame(
            storage_path('framework/autofinx-market-ws-heartbeat-spot.json'),
            MarketWsConsumerCommand::heartbeatPathFor('spot')
        );
    }

    public function test_legacy_non_lane_futures_heartbeat_path_is_unchanged(): void
    {
        $this->assertSame(
            storage_path('framework/autofinx-market-ws-heartbeat-futures.json'),
            MarketWsConsumerCommand::heartbeatPathFor('futures')
        );
    }

    public function test_futures_lane_heartbeat_paths_are_independent_files(): void
    {
        $public = MarketWsConsumerCommand::heartbeatPathFor('futures', 'public');
        $market = MarketWsConsumerCommand::heartbeatPathFor('futures', 'market');

        $this->assertSame(storage_path('framework/autofinx-market-ws-heartbeat-futures-public.json'), $public);
        $this->assertSame(storage_path('framework/autofinx-market-ws-heartbeat-futures-market.json'), $market);
        $this->assertNotSame($public, $market);
    }
}
