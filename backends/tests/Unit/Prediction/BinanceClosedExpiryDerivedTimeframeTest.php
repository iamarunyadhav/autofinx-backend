<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\BinanceClosedExpiryPriceService;
use App\Domain\Trading\Providers\BinanceTimedFuturesProvider;
use App\Models\Integration;
use App\Models\PredictionObservation;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class BinanceClosedExpiryDerivedTimeframeTest extends TestCase
{
    private function closedCandles(int $intervalSeconds): array
    {
        $intervalMs = $intervalSeconds * 1000;
        $currentOpen = intdiv(now()->getTimestampMs(), $intervalMs) * $intervalMs;

        return array_map(fn (int $i) => [
            'timestamp' => $currentOpen - (2 - $i) * $intervalMs,
            'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0 + $i, 'volume' => 1.0,
        ], [0, 1, 2]); // index 2 is the forming bar
    }

    private function settle(string $timeframe, string $expectedInterval, int $intervalSeconds): float
    {
        $integration = new Integration(['provider_key' => 'binance']);
        $observation = new PredictionObservation(['symbol' => 'BTCUSDT', 'timeframe' => $timeframe]);
        $this->mock(BinanceTimedFuturesProvider::class, function ($mock) use ($expectedInterval, $intervalSeconds): void {
            $mock->shouldReceive('candles')->once()
                ->with(Mockery::any(), 'BTCUSDT', $expectedInterval, 3)
                ->andReturn($this->closedCandles($intervalSeconds));
        });

        return app(BinanceClosedExpiryPriceService::class)->resolve($observation, $integration);
    }

    public function test_derived_45m_settles_on_the_closed_15m_source_candle(): void
    {
        // Previously threw "Unsupported timeframe [45m]" and re-failed every minute.
        $this->assertSame(101.0, $this->settle('45m', '15m', 900));
    }

    public function test_native_timeframes_are_unchanged(): void
    {
        $this->assertSame(101.0, $this->settle('15m', '15m', 900));
    }

    public function test_unknown_non_derived_timeframe_still_fails_loudly(): void
    {
        $this->mock(BinanceTimedFuturesProvider::class)->shouldNotReceive('candles');
        $this->expectException(InvalidArgumentException::class);
        app(BinanceClosedExpiryPriceService::class)->resolve(
            new PredictionObservation(['symbol' => 'BTCUSDT', 'timeframe' => '7m']),
            new Integration(['provider_key' => 'binance']),
        );
    }
}
