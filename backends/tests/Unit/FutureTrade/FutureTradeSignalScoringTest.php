<?php

namespace Tests\Unit\FutureTrade;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Domain\Signals\IndicatorIntelligenceService;
use App\Domain\Signals\StrategyResearchLabService;
use App\Domain\Trading\FuturesOrderSizingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FutureTradeSignalScoringTest extends TestCase
{
    public function test_sequence_only_bearish_snapshot_stays_conservative_without_structure_confirmation(): void
    {
        $service = $this->service();
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
            'signalProfile' => 'legacy_future_trade',
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('WAIT', $signal['signal']);
        $this->assertLessThan(0.75, $signal['confidence']);
    }

    public function test_conflicted_market_stays_wait_instead_of_forcing_buy_or_sell(): void
    {
        $service = $this->service();
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
            'signalProfile' => 'legacy_future_trade',
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('WAIT', $signal['signal']);
        $this->assertStringContainsString('sequence', strtolower($signal['reason']));
    }

    public function test_noisy_short_term_sequence_is_filtered_as_wait(): void
    {
        $service = $this->service();
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
            'signalProfile' => 'legacy_future_trade',
            'avoidChoppyMarket' => true,
            'timeframe' => '5m',
        ]]);

        $this->assertSame('WAIT', $signal['signal']);
        $this->assertLessThan(0.55, $signal['confidence']);
    }

    public function test_prime_crypto_profile_scores_hard_filtered_long_signal(): void
    {
        $service = $this->service();

        $signal = $this->invoke($service, 'scoreTicker', [[
            'symbol' => 'ETHUSDT',
            'lastPrice' => 110,
            'priceChangePercent' => 1.2,
            'quoteVolume' => 250000000,
            'highPrice' => 112,
            'lowPrice' => 100,
            'weightedAvgPrice' => 105,
            'openPrice' => 101,
            'sequence' => [
                'ema9' => 108,
                'ema21' => 106,
                'ema50' => 103,
                'ema200' => 95,
                'sessionVwap' => 104,
                'rsi14' => 61,
                'adx14' => 28,
                'diPlus14' => 31,
                'diMinus14' => 12,
                'atr14' => 1.1,
                'atrPercent' => 1.0,
                'relativeVolume' => 1.4,
                'choppiness14' => 48,
                'donchianBullCount' => 9,
                'donchianBearCount' => 0,
                'momentumLongOk' => true,
                'momentumShortOk' => false,
            ],
            'htf' => ['trend1h' => 1, 'trend4h' => 1],
        ], [
            'signalProfile' => 'autofinx_prime_v6_1_7_crypto',
            'marketProfile' => 'Crypto',
        ]]);

        $this->assertSame('BUY', $signal['signal']);
        $this->assertSame('autofinx_prime_v6_1_7_crypto', $signal['debug']['profile']);
        $this->assertGreaterThanOrEqual(0.72, $signal['confidence']);
        $this->assertTrue($signal['debug']['hardLongFilter']);
    }

    public function test_prime_crypto_profile_is_not_default_for_forex_market_profile(): void
    {
        $service = $this->service();

        $settings = $this->invoke($service, 'normalizeFutureTradeSettings', [[
            'signalProfile' => 'autofinx_prime_v6_1_7_crypto',
            'marketProfile' => 'Forex',
        ]]);

        $this->assertSame('Forex', $settings['marketProfile']);
        $this->assertSame('autofinx_prime_v6_1_7_crypto', $settings['signalProfile']);
    }

    private function invoke(FutureTradeBotService $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }

    private function service(): FutureTradeBotService
    {
        return new FutureTradeBotService(
            new LivePortfolioGuardService(),
            $this->createMock(OperationalHealthService::class),
            new FuturesOrderSizingService(),
            new IndicatorIntelligenceService(),
            new StrategyResearchLabService(),
        );
    }
}
