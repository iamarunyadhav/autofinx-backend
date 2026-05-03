<?php

namespace Tests\Unit\FutureTrade;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FutureTradeSignalScoringTest extends TestCase
{
    public function test_bearish_sequence_generates_sell_signal_with_strong_confidence(): void
    {
        $service = new FutureTradeBotService(new LivePortfolioGuardService());
        $sequence = $this->invoke($service, 'buildSequenceSnapshot', [[
            [0, '100', '100.2', '99.7', '99.8'],
            [0, '99.8', '99.9', '99.3', '99.4'],
            [0, '99.4', '99.5', '99.0', '99.1'],
            [0, '99.1', '99.2', '98.7', '98.8'],
            [0, '98.8', '98.9', '98.3', '98.4'],
            [0, '98.4', '98.6', '98.1', '98.2'],
        ]]);

        $signal = $this->invoke($service, 'scoreTicker', [[
            'symbol' => 'ETHUSDT',
            'lastPrice' => 98.2,
            'priceChangePercent' => -1.4,
            'quoteVolume' => 250000000,
            'highPrice' => 100.4,
            'lowPrice' => 98.1,
            'weightedAvgPrice' => 99.2,
            'openPrice' => 99.8,
            'sequence' => $sequence,
        ], [
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('SELL', $signal['signal']);
        $this->assertGreaterThan(0.55, $signal['confidence']);
    }

    public function test_conflicted_market_stays_wait_instead_of_forcing_buy_or_sell(): void
    {
        $service = new FutureTradeBotService(new LivePortfolioGuardService());
        $sequence = $this->invoke($service, 'buildSequenceSnapshot', [[
            [0, '100', '100.4', '99.8', '100.3'],
            [0, '100.3', '100.5', '99.9', '100.0'],
            [0, '100.0', '100.3', '99.7', '99.9'],
            [0, '99.9', '100.2', '99.6', '100.0'],
            [0, '100.0', '100.3', '99.8', '99.95'],
            [0, '99.95', '100.2', '99.7', '100.0'],
        ]]);

        $signal = $this->invoke($service, 'scoreTicker', [[
            'symbol' => 'BTCUSDT',
            'lastPrice' => 100.0,
            'priceChangePercent' => 0.15,
            'quoteVolume' => 180000000,
            'highPrice' => 100.5,
            'lowPrice' => 99.6,
            'weightedAvgPrice' => 99.95,
            'openPrice' => 99.98,
            'sequence' => $sequence,
        ], [
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('WAIT', $signal['signal']);
        $this->assertStringContainsString('10m sequence', $signal['reason']);
    }

    public function test_noisy_short_term_sequence_is_filtered_as_wait(): void
    {
        $service = new FutureTradeBotService(new LivePortfolioGuardService());
        $sequence = $this->invoke($service, 'buildSequenceSnapshot', [[
            [0, '100', '100.5', '99.7', '100.1'],
            [0, '100.1', '100.6', '99.6', '99.9'],
            [0, '99.9', '100.4', '99.5', '100.0'],
            [0, '100.0', '100.5', '99.4', '99.8'],
            [0, '99.8', '100.3', '99.4', '99.95'],
            [0, '99.95', '100.4', '99.5', '100.0'],
        ]]);

        $signal = $this->invoke($service, 'scoreTicker', [[
            'symbol' => 'SOLUSDT',
            'lastPrice' => 100.0,
            'priceChangePercent' => 0.05,
            'quoteVolume' => 90000000,
            'highPrice' => 100.6,
            'lowPrice' => 99.4,
            'weightedAvgPrice' => 100.0,
            'openPrice' => 100.0,
            'sequence' => $sequence,
        ], [
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('WAIT', $signal['signal']);
        $this->assertLessThan(0.55, $signal['confidence']);
    }

    private function invoke(FutureTradeBotService $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
