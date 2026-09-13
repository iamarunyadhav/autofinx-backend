<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\MTFConfirmationEngine;
use App\Models\Instrument;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MTFConfirmationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(): Instrument
    {
        return Instrument::create([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);
    }

    /**
     * Seeds `count` CLOSED candles for one timeframe, strictly trending in
     * `direction` ('up' or 'down'), ending safely before "now" so none of
     * them are the still-forming candle.
     */
    private function seedTrendingCandles(string $symbol, string $timeframe, int $minutesPerCandle, int $count, string $direction): void
    {
        $price = 2000.0;
        $step = $direction === 'up' ? 1.0 : -1.0;

        for ($i = 0; $i < $count; $i++) {
            $price += $step;
            $ts = Carbon::now()->subMinutes($minutesPerCandle * ($count - $i + 1));

            MarketOhlc::create([
                'exchange' => 'pepperstone',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open' => $price - ($step * 0.2),
                'high' => $price + 0.5,
                'low' => $price - 0.5,
                'close' => $price,
                'volume' => 0,
                'ts' => $ts,
            ]);
        }
    }

    /** @test */
    public function reports_neutral_with_insufficient_data_when_no_candles_exist_for_any_requested_timeframe(): void
    {
        $engine = new MTFConfirmationEngine();
        $instrument = $this->instrument();

        $result = $engine->confirm($instrument, 'pepperstone', ['1h']);

        $this->assertSame('NEUTRAL', $result['direction']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame(0.0, $result['agreement_score']);
        $this->assertSame([], $result['conflicts']);
        $this->assertFalse($result['timeframes']['1h']['sufficient_data']);
    }

    /** @test */
    public function reports_buy_for_a_single_strongly_trending_up_timeframe(): void
    {
        $engine = new MTFConfirmationEngine();
        $instrument = $this->instrument();
        $this->seedTrendingCandles('XAUUSD', '1h', 60, 250, 'up');

        $result = $engine->confirm($instrument, 'pepperstone', ['1h']);

        $this->assertSame('BUY', $result['direction']);
        $this->assertGreaterThan(0, $result['confidence']);
        $this->assertTrue($result['timeframes']['1h']['sufficient_data']);
        $this->assertSame('bullish', $result['timeframes']['1h']['trend']['direction']);
        $this->assertEmpty($result['conflicts']);
    }

    /** @test */
    public function reports_sell_for_a_single_strongly_trending_down_timeframe(): void
    {
        $engine = new MTFConfirmationEngine();
        $instrument = $this->instrument();
        $this->seedTrendingCandles('XAUUSD', '1h', 60, 250, 'down');

        $result = $engine->confirm($instrument, 'pepperstone', ['1h']);

        $this->assertSame('SELL', $result['direction']);
        $this->assertSame('bearish', $result['timeframes']['1h']['trend']['direction']);
    }

    /** @test */
    public function flags_a_conflicting_timeframe_and_lets_the_higher_weighted_timeframe_win_direction(): void
    {
        $engine = new MTFConfirmationEngine();
        $instrument = $this->instrument();

        // 1h bullish (lower weight), 4h bearish (higher weight) -- overall
        // direction should follow 4h, and 1h should show up in conflicts.
        $this->seedTrendingCandles('XAUUSD', '1h', 60, 250, 'up');
        $this->seedTrendingCandles('XAUUSD', '4h', 240, 250, 'down');

        $result = $engine->confirm($instrument, 'pepperstone', ['1h', '4h']);

        $this->assertSame('SELL', $result['direction']);
        $this->assertContains('1h', $result['conflicts']);
    }
}
