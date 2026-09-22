<?php

namespace Tests\Unit\Market;

use App\Domain\Market\BinanceFuturesStreamRouter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: production evidence proved Binance's futures
 * ROOT combined-stream endpoint only reliably delivers depthUpdate frames,
 * not kline frames. This router encodes exactly (and only) the verified
 * Phase-1 mapping: depth* -> public lane, kline_* -> market lane.
 */
class BinanceFuturesStreamRouterTest extends TestCase
{
    public function test_depth_stream_routes_to_public_lane(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $this->assertSame(BinanceFuturesStreamRouter::LANE_PUBLIC, $router->laneFor('depth5@500ms'));
        $this->assertSame(BinanceFuturesStreamRouter::LANE_PUBLIC, $router->laneFor('depth20@100ms'));
    }

    public function test_kline_streams_route_to_market_lane(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $this->assertSame(BinanceFuturesStreamRouter::LANE_MARKET, $router->laneFor('kline_1m'));
        $this->assertSame(BinanceFuturesStreamRouter::LANE_MARKET, $router->laneFor('kline_5m'));
        $this->assertSame(BinanceFuturesStreamRouter::LANE_MARKET, $router->laneFor('kline_1h'));
    }

    public function test_classify_splits_the_documented_example_exactly(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $result = $router->classify(['depth5@500ms', 'kline_1m', 'kline_5m']);

        $this->assertSame([
            BinanceFuturesStreamRouter::LANE_PUBLIC => ['depth5@500ms'],
            BinanceFuturesStreamRouter::LANE_MARKET => ['kline_1m', 'kline_5m'],
        ], $result);
    }

    public function test_streams_for_lane_returns_only_the_requested_lane(): void
    {
        $router = new BinanceFuturesStreamRouter;
        $streams = ['depth5@500ms', 'kline_1m', 'kline_5m'];

        $this->assertSame(['depth5@500ms'], $router->streamsForLane($streams, BinanceFuturesStreamRouter::LANE_PUBLIC));
        $this->assertSame(['kline_1m', 'kline_5m'], $router->streamsForLane($streams, BinanceFuturesStreamRouter::LANE_MARKET));
    }

    public function test_unrecognized_stream_family_is_rejected_not_guessed(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bookTicker/');

        $router->classify(['depth5@500ms', 'bookTicker']);
    }

    public function test_trade_stream_family_is_also_rejected(): void
    {
        // 'trade' was never verified on either lane endpoint by the
        // 2026-09-22 production diagnostic -- it must fail closed, not be
        // silently assigned to a lane.
        $router = new BinanceFuturesStreamRouter;

        $this->expectException(InvalidArgumentException::class);

        $router->classify(['trade']);
    }

    public function test_classify_deduplicates_within_a_lane(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $result = $router->classify(['kline_1m', 'kline_1m', 'depth5@500ms']);

        $this->assertSame(['kline_1m'], $result[BinanceFuturesStreamRouter::LANE_MARKET]);
        $this->assertSame(['depth5@500ms'], $result[BinanceFuturesStreamRouter::LANE_PUBLIC]);
    }

    public function test_blank_entries_are_ignored(): void
    {
        $router = new BinanceFuturesStreamRouter;

        $result = $router->classify(['depth5@500ms', '', '  ', 'kline_1m']);

        $this->assertSame(['depth5@500ms'], $result[BinanceFuturesStreamRouter::LANE_PUBLIC]);
        $this->assertSame(['kline_1m'], $result[BinanceFuturesStreamRouter::LANE_MARKET]);
    }
}
