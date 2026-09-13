<?php

namespace Tests\Feature\Pepperstone;

use App\Domain\Pepperstone\PepperstoneOhlcBackfillService;
use App\Domain\Venue\PepperstoneBrokerAdapter;
use App\Models\Instrument;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PepperstoneOhlcBackfillServiceTest extends TestCase
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

    /** @test */
    public function sandbox_fetchCandles_is_deterministic_across_calls(): void
    {
        $venue = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $first = $venue->fetchCandles('XAUUSD', '1h', 50);
        $second = $venue->fetchCandles('XAUUSD', '1h', 50);

        $this->assertCount(50, $first);
        $this->assertSame($first, $second);
    }

    /** @test */
    public function live_mode_throws_the_established_not_implemented_exception(): void
    {
        $venue = new PepperstoneBrokerAdapter('https://example.invalid', 'key', 'account', 'live');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not yet implemented/');

        $venue->fetchCandles('XAUUSD', '1h', 50);
    }

    /** @test */
    public function backfill_writes_market_ohlc_rows_for_the_requested_timeframe(): void
    {
        $venue = new PepperstoneBrokerAdapter('', '', '', 'sandbox');
        $service = new PepperstoneOhlcBackfillService();

        $written = $service->backfill($venue, $this->instrument(), '1h', 50);

        $this->assertSame(50, $written);
        $this->assertSame(50, MarketOhlc::query()
            ->where('exchange', 'pepperstone')
            ->where('symbol', 'XAUUSD')
            ->where('timeframe', '1h')
            ->count());
    }

    /** @test */
    public function backfill_is_idempotent_on_repeated_runs(): void
    {
        $venue = new PepperstoneBrokerAdapter('', '', '', 'sandbox');
        $service = new PepperstoneOhlcBackfillService();
        $instrument = $this->instrument();

        $service->backfill($venue, $instrument, '1h', 50);
        $service->backfill($venue, $instrument, '1h', 50);

        $this->assertSame(50, MarketOhlc::query()
            ->where('exchange', 'pepperstone')
            ->where('symbol', 'XAUUSD')
            ->where('timeframe', '1h')
            ->count());
    }

    /** @test */
    public function backfillAllTimeframes_writes_every_default_timeframe(): void
    {
        $venue = new PepperstoneBrokerAdapter('', '', '', 'sandbox');
        $service = new PepperstoneOhlcBackfillService();

        $results = $service->backfillAllTimeframes($venue, $this->instrument(), ['5m', '1h'], 20);

        $this->assertSame(['5m' => 20, '1h' => 20], $results);
    }
}
