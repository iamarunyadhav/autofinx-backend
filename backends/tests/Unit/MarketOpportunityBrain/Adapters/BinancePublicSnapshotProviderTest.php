<?php

namespace Tests\Unit\MarketOpportunityBrain\Adapters;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicRestClient;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicSnapshotProvider;
use App\Domain\MarketOpportunityBrain\Adapters\MarketDataDisabledException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinancePublicSnapshotProviderTest extends TestCase
{
    private function enable(): void
    {
        config([
            'market_opportunity_brain.public_data_enabled' => true,
            'market_opportunity_brain.max_api_weight_per_minute' => 1200,
            'market_opportunity_brain.max_discovery_symbols' => 50,
        ]);
    }

    private function kline(int $openMs, float $open, float $close, int $closeMs): array
    {
        return [$openMs, (string) $open, (string) max($open, $close), (string) min($open, $close), (string) $close, '100.0', $closeMs, '5000.0', 10, '50.0', '2500.0', '0'];
    }

    /** @test */
    public function refuses_to_call_the_network_when_disabled(): void
    {
        $this->expectException(MarketDataDisabledException::class);
        (new BinancePublicSnapshotProvider())->closedCandles('BTCUSDT', '15m', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 10);
    }

    /** @test */
    public function excludes_an_in_progress_trailing_candle(): void
    {
        $this->enable();
        $asOf = new \DateTimeImmutable('2026-01-01T00:20:00Z');
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                $this->kline(1767225600000, 100, 101, 1767226499999), // 2026-01-01 00:00 -> closes 00:14:59.999 -- closed
                $this->kline(1767226500000, 101, 102, 1767227399999), // 2026-01-01 00:15 -> closes 00:29:59.999 -- NOT closed as of 00:20
            ]),
        ]);

        $candles = (new BinancePublicSnapshotProvider(new BinancePublicRestClient()))->closedCandles('BTCUSDT', '15m', $asOf, 10);

        $this->assertCount(1, $candles);
        $this->assertTrue($candles[0]->isClosed);
        $this->assertSame(101.0, $candles[0]->close);
    }

    /** @test */
    public function deduplicates_a_repeated_open_time(): void
    {
        $this->enable();
        $asOf = new \DateTimeImmutable('2026-01-01T01:00:00Z');
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                $this->kline(1735689600000, 100, 101, 1735690499999),
                $this->kline(1735689600000, 100, 101, 1735690499999), // exact duplicate
            ]),
        ]);

        $candles = (new BinancePublicSnapshotProvider(new BinancePublicRestClient()))->closedCandles('BTCUSDT', '15m', $asOf, 10);

        $this->assertCount(1, $candles);
    }

    /** @test */
    public function skips_a_malformed_row_rather_than_guessing(): void
    {
        $this->enable();
        $asOf = new \DateTimeImmutable('2026-01-01T01:00:00Z');
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                [1735689600000, '100'], // malformed: too few fields
                $this->kline(1735690500000, 101, 102, 1735691399999),
            ]),
        ]);

        $candles = (new BinancePublicSnapshotProvider(new BinancePublicRestClient()))->closedCandles('BTCUSDT', '15m', $asOf, 10);

        $this->assertCount(1, $candles);
        $this->assertSame(102.0, $candles[0]->close);
    }

    /** @test */
    public function rejects_an_unsupported_timeframe(): void
    {
        $this->enable();
        $this->expectException(\InvalidArgumentException::class);
        (new BinancePublicSnapshotProvider(new BinancePublicRestClient()))->closedCandles('BTCUSDT', '3m', new \DateTimeImmutable(), 10);
    }
}
