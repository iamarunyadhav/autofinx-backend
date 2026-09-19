<?php

namespace Tests\Unit\Market;

use App\Domain\Market\MarketOhlcBackfillService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketOhlcBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketOhlcBackfillService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketOhlcBackfillService();
    }

    /** @test */
    public function needs_backfill_is_true_below_threshold_and_false_at_or_above_it(): void
    {
        $this->assertTrue($this->service->needsBackfill('binance', 'BTCUSDT', '1h'));

        $this->seedRows('binance', 'BTCUSDT', '1h', 30);

        $this->assertFalse($this->service->needsBackfill('binance', 'BTCUSDT', '1h'));
    }

    /** @test */
    public function backfill_writes_correct_rows_from_binance_klines_shape(): void
    {
        Http::fake([
            '*/api/v3/klines*' => Http::response($this->fakeKlines(40, startPrice: 100.0)),
        ]);

        $written = $this->service->backfill('binance', 'BTCUSDT', '1h', 40);

        $this->assertSame(40, $written);
        $this->assertSame(40, MarketOhlc::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT')
            ->where('timeframe', '1h')
            ->count());

        $row = MarketOhlc::query()->where('symbol', 'BTCUSDT')->orderBy('ts')->first();
        $this->assertEqualsWithDelta(100.0, (float) $row->open, 0.01);
    }

    /** @test */
    public function rerunning_backfill_with_overlapping_candles_does_not_duplicate(): void
    {
        Http::fake([
            '*/api/v3/klines*' => Http::response($this->fakeKlines(40, startPrice: 100.0)),
        ]);

        $this->service->backfill('binance', 'ETHUSDT', '1h', 40);
        $countAfterFirst = MarketOhlc::where('symbol', 'ETHUSDT')->count();

        // Same exact candle set fetched again (e.g. an operator re-running the command).
        $this->service->backfill('binance', 'ETHUSDT', '1h', 40);
        $countAfterSecond = MarketOhlc::where('symbol', 'ETHUSDT')->count();

        $this->assertSame(40, $countAfterFirst);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    /** @test */
    public function backfill_for_config_selects_futures_endpoint_for_futures_market_type(): void
    {
        Http::fake([
            '*/fapi/v1/klines*' => Http::response($this->fakeKlines(35, startPrice: 50.0)),
            '*/api/v3/klines*' => Http::response([]), // should NOT be hit
        ]);

        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'timeframe' => '1h',
            'base_quote_amount' => 100,
        ]);

        $written = $this->service->backfillForConfig($config, 35);

        $this->assertSame(35, $written);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/klines'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v3/klines'));
    }

    /** @test */
    public function detect_gaps_returns_empty_for_a_contiguous_sequence(): void
    {
        $this->seedRows('binance', 'BTCUSDT', '1h', 30);

        $gaps = $this->service->detectGaps('binance', 'BTCUSDT', '1h');

        $this->assertSame([], $gaps);
    }

    /** @test */
    public function detect_gaps_flags_a_missing_window_in_the_candle_sequence(): void
    {
        $base = now()->startOfHour();
        // Contiguous 1h candles for hours 0..4, then a jump straight to
        // hour 10 -- a 5-hour hole (hours 5..9 missing).
        foreach ([0, 1, 2, 3, 4, 10] as $hourOffset) {
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1h',
                'ts' => $base->copy()->addHours($hourOffset),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
            ]);
        }

        $gaps = $this->service->detectGaps('binance', 'BTCUSDT', '1h');

        $this->assertCount(1, $gaps);
        $this->assertSame(5, $gaps[0]['missing_candles']);
    }

    /** @test */
    public function backfill_logs_a_warning_when_the_fetched_candles_contain_a_gap(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $rows = $this->fakeKlines(5, startPrice: 100.0);
        // Remove the middle candle to create a real gap in what Binance
        // "returned" (simulating a genuine exchange-side data hole).
        unset($rows[2]);
        $rows = array_values($rows);

        Http::fake([
            '*/api/v3/klines*' => Http::response($rows),
        ]);

        $this->service->backfill('binance', 'GAPUSDT', '1h', 40);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'gap detected'))
            ->once();
    }

    private function seedRows(string $exchange, string $symbol, string $timeframe, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            MarketOhlc::create([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'ts' => now()->subHours($count - $i),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
            ]);
        }
    }

    private function fakeKlines(int $count, float $startPrice): array
    {
        $rows = [];
        $price = $startPrice;
        $openTime = now()->subHours($count)->valueOf();

        for ($i = 0; $i < $count; $i++) {
            $close = $price + 1;
            $rows[] = [
                $openTime + ($i * 3600_000),
                (string) $price,
                (string) ($price + 1.5),
                (string) ($price - 1.5),
                (string) $close,
                '123.45',
                $openTime + (($i + 1) * 3600_000) - 1,
                '0', 0, '0', '0', '0',
            ];
            $price = $close;
        }

        return $rows;
    }
}
