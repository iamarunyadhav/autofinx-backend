<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Market\OandaOhlcBackfillService;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OandaOhlcBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function candles_land_in_market_ohlc_with_the_correct_exchange_key_and_no_duplicates_on_the_existing_unique_constraint(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'time' => now()->subMinutes(5 - $i)->toIso8601String(),
                'mid' => ['o' => '1.0800', 'h' => '1.0810', 'l' => '1.0790', 'c' => '1.0805'],
                'volume' => 120,
            ];
        }

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($rows) {
            if (str_contains($request->url(), '/candles')) {
                return Http::response(['candles' => $rows, 'instrument' => 'EUR_USD', 'granularity' => 'M1'], 200);
            }

            return Http::response([], 200);
        });

        $service = app(OandaOhlcBackfillService::class);
        $written = $service->backfill('https://api-fxpractice.oanda.com', 'test-token', 'oanda', 'EURUSD', '1m', 500);

        $this->assertSame(5, $written);
        $this->assertSame(5, MarketOhlc::where('exchange', 'oanda')->where('symbol', 'EURUSD')->where('timeframe', '1m')->count());

        // Re-running the same backfill must upsert, never duplicate, on the
        // existing (exchange, symbol, timeframe, ts) unique constraint.
        $writtenAgain = $service->backfill('https://api-fxpractice.oanda.com', 'test-token', 'oanda', 'EURUSD', '1m', 500);
        $this->assertSame(5, $writtenAgain);
        $this->assertSame(5, MarketOhlc::where('exchange', 'oanda')->where('symbol', 'EURUSD')->where('timeframe', '1m')->count());
    }

    /** @test */
    public function the_request_uses_oandas_real_documented_candles_endpoint_with_the_correct_instrument_and_granularity(): void
    {
        $captured = null;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$captured) {
            if (str_contains($request->url(), '/candles')) {
                $captured = $request;

                return Http::response(['candles' => []], 200);
            }

            return Http::response([], 200);
        });

        app(OandaOhlcBackfillService::class)->backfill('https://api-fxpractice.oanda.com', 'test-token', 'oanda', 'XAUUSD', '15m', 100);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/v3/instruments/XAU_USD/candles', $captured->url());
        $this->assertSame('M15', $captured->data()['granularity']);
    }

    /** @test */
    public function needs_backfill_correctly_detects_insufficient_history(): void
    {
        $service = app(OandaOhlcBackfillService::class);

        $this->assertTrue($service->needsBackfill('oanda', 'EURUSD', '1m'));

        for ($i = 0; $i < 35; $i++) {
            MarketOhlc::create([
                'exchange' => 'oanda',
                'symbol' => 'EURUSD',
                'timeframe' => '1m',
                'ts' => now()->subMinutes(35 - $i),
                'open' => 1.08,
                'high' => 1.081,
                'low' => 1.079,
                'close' => 1.0805,
                'volume' => 100,
            ]);
        }

        $this->assertFalse($service->needsBackfill('oanda', 'EURUSD', '1m'));
    }
}
