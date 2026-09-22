<?php

namespace Tests\Unit\Market;

use App\Domain\Market\BinanceFuturesStreamRouter;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\RealtimeMarketFeedService;
use Mockery;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: verifies buildStreamUrlForLane() routes
 * futures public/market lanes to their dedicated endpoints while leaving
 * spot's URL (and buildStreamUrl()'s original behavior) byte-for-byte
 * unchanged.
 */
class RealtimeMarketFeedServiceStreamUrlTest extends TestCase
{
    private function service(): RealtimeMarketFeedService
    {
        return new RealtimeMarketFeedService(Mockery::mock(MarketPriceCacheService::class));
    }

    public function test_futures_public_lane_uses_the_dedicated_public_endpoint(): void
    {
        config()->set('services.binance.futures_public_ws_url', 'wss://fstream.binance.com/public');

        $url = $this->service()->buildStreamUrlForLane(
            ['BTCUSDT'],
            'futures',
            ['depth5@500ms'],
            BinanceFuturesStreamRouter::LANE_PUBLIC
        );

        $this->assertSame('wss://fstream.binance.com/public/stream?streams=btcusdt@depth5@500ms', $url);
    }

    public function test_futures_market_lane_uses_the_dedicated_market_endpoint(): void
    {
        config()->set('services.binance.futures_market_ws_url', 'wss://fstream.binance.com/market');

        $url = $this->service()->buildStreamUrlForLane(
            ['BTCUSDT', 'ETHUSDT'],
            'futures',
            ['kline_1m', 'kline_5m'],
            BinanceFuturesStreamRouter::LANE_MARKET
        );

        $this->assertSame(
            'wss://fstream.binance.com/market/stream?streams=btcusdt@kline_1m/ethusdt@kline_1m/btcusdt@kline_5m/ethusdt@kline_5m',
            $url
        );
    }

    public function test_futures_without_a_lane_still_uses_the_original_combined_endpoint(): void
    {
        config()->set('services.binance.futures_ws_url', 'wss://fstream.binance.com');

        $url = $this->service()->buildStreamUrlForLane(['BTCUSDT'], 'futures', ['depth5@500ms', 'kline_1m'], null);

        $this->assertSame('wss://fstream.binance.com/stream?streams=btcusdt@depth5@500ms/btcusdt@kline_1m', $url);
    }

    public function test_spot_url_is_byte_for_byte_unchanged_by_the_lane_refactor(): void
    {
        config()->set('services.binance.ws_url', 'wss://stream.binance.com:9443');

        $service = $this->service();
        $legacyUrl = $service->buildStreamUrl(['BTCUSDT', 'ETHUSDT'], 'spot', ['depth5@500ms', 'kline_1m']);
        $laneAwareUrl = $service->buildStreamUrlForLane(['BTCUSDT', 'ETHUSDT'], 'spot', ['depth5@500ms', 'kline_1m'], null);

        $expected = 'wss://stream.binance.com:9443/stream?streams=btcusdt@depth5@500ms/ethusdt@depth5@500ms/btcusdt@kline_1m/ethusdt@kline_1m';
        $this->assertSame($expected, $legacyUrl);
        $this->assertSame($expected, $laneAwareUrl);
    }

    public function test_futures_testnet_ignores_lane_and_uses_the_single_combined_testnet_endpoint(): void
    {
        config()->set('services.binance.futures_testnet_ws_url', 'wss://stream.binancefuture.com');

        $publicUrl = $this->service()->buildStreamUrlForLane(['BTCUSDT'], 'futures', ['depth5@500ms'], BinanceFuturesStreamRouter::LANE_PUBLIC, true);
        $marketUrl = $this->service()->buildStreamUrlForLane(['BTCUSDT'], 'futures', ['kline_1m'], BinanceFuturesStreamRouter::LANE_MARKET, true);

        $this->assertSame('wss://stream.binancefuture.com/stream?streams=btcusdt@depth5@500ms', $publicUrl);
        $this->assertSame('wss://stream.binancefuture.com/stream?streams=btcusdt@kline_1m', $marketUrl);
    }

    public function test_empty_streams_fail_fast_instead_of_building_a_malformed_url(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service()->buildStreamUrlForLane(['BTCUSDT'], 'futures', [], BinanceFuturesStreamRouter::LANE_PUBLIC);
    }
}
