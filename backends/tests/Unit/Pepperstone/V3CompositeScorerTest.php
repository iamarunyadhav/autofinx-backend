<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\V3CompositeScorer;
use App\Models\Instrument;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class V3CompositeScorerTest extends TestCase
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

    private function seedTrend(string $symbol, string $timeframe, int $minutesPerCandle, int $count, string $direction): void
    {
        $price = 2000.0;
        $step = $direction === 'up' ? 1.0 : -1.0;

        for ($i = 0; $i < $count; $i++) {
            $price += $step;
            MarketOhlc::create([
                'exchange' => 'pepperstone',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open' => $price - ($step * 0.2),
                'high' => $price + 0.5,
                'low' => $price - 0.5,
                'close' => $price,
                'volume' => 0,
                'ts' => Carbon::now()->subMinutes($minutesPerCandle * ($count - $i + 1)),
            ]);
        }
    }

    /** @test */
    public function reports_neutral_with_insufficient_data_when_no_candles_exist(): void
    {
        $scorer = new V3CompositeScorer();

        $result = $scorer->score($this->instrument());

        $this->assertSame('NEUTRAL', $result['direction']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertTrue($result['insufficient_data']);
    }

    /** @test */
    public function reports_buy_for_a_sustained_uptrend(): void
    {
        $scorer = new V3CompositeScorer();
        $this->seedTrend('XAUUSD', '1h', 60, 160, 'up');

        $result = $scorer->score($this->instrument(), '1h');

        $this->assertSame('BUY', $result['direction']);
        $this->assertFalse($result['insufficient_data']);
        $this->assertGreaterThan(0, $result['confidence']);
    }

    /** @test */
    public function reports_sell_for_a_sustained_downtrend(): void
    {
        $scorer = new V3CompositeScorer();
        $this->seedTrend('XAUUSD', '1h', 60, 160, 'down');

        $result = $scorer->score($this->instrument(), '1h');

        $this->assertSame('SELL', $result['direction']);
    }
}
