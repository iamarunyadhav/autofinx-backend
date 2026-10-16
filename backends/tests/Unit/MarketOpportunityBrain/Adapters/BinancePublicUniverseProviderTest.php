<?php

namespace Tests\Unit\MarketOpportunityBrain\Adapters;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicRestClient;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicUniverseProvider;
use App\Domain\MarketOpportunityBrain\Adapters\MarketDataDisabledException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinancePublicUniverseProviderTest extends TestCase
{
    private function enable(int $topK = 50, float $minVolume = 1_000_000): void
    {
        config([
            'market_opportunity_brain.public_data_enabled' => true,
            'market_opportunity_brain.max_api_weight_per_minute' => 1200,
            'market_opportunity_brain.max_discovery_symbols' => $topK,
            'market_opportunity_brain.min_quote_volume_24h' => $minVolume,
        ]);
    }

    private function fakeExchangeInfoAndTickers(array $symbols, array $volumes): void
    {
        Http::fake([
            '*/fapi/v1/exchangeInfo*' => Http::response(['symbols' => $symbols]),
            '*/fapi/v1/ticker/24hr*' => Http::response(array_map(
                static fn ($symbol, $vol) => ['symbol' => $symbol, 'quoteVolume' => (string) $vol],
                array_keys($volumes),
                array_values($volumes)
            )),
        ]);
    }

    /** @test */
    public function refuses_to_call_the_network_when_disabled(): void
    {
        $this->expectException(MarketDataDisabledException::class);
        (new BinancePublicUniverseProvider())->universeAsOf(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** @test */
    public function rejects_a_materially_historical_as_of_request(): void
    {
        $this->enable();
        $this->expectException(\DomainException::class);
        $old = new \DateTimeImmutable('2020-01-01T00:00:00Z');
        (new BinancePublicUniverseProvider(new BinancePublicRestClient()))->universeAsOf($old);
    }

    /** @test */
    public function excludes_non_usdt_non_perpetual_and_non_trading_symbols_outright(): void
    {
        $this->enable();
        $this->fakeExchangeInfoAndTickers([
            ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
            ['symbol' => 'BTCUSD_PERP', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USD'],
            ['symbol' => 'BTCUSDT_250627', 'status' => 'TRADING', 'contractType' => 'CURRENT_QUARTER', 'quoteAsset' => 'USDT'],
            ['symbol' => 'DELISTEDUSDT', 'status' => 'BREAK', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
        ], ['BTCUSDT' => 50_000_000]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $records = iterator_to_array((new BinancePublicUniverseProvider(new BinancePublicRestClient()))->universeAsOf($now), false);
        $symbols = array_map(fn ($r) => $r->symbol, $records);

        $this->assertSame(['BTCUSDT'], $symbols);
    }

    /** @test */
    public function marks_missing_and_insufficient_volume_separately_without_dropping_the_record(): void
    {
        $this->enable(minVolume: 1_000_000);
        $this->fakeExchangeInfoAndTickers([
            ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
            ['symbol' => 'LOWVOLUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
            ['symbol' => 'NOVOLUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
        ], ['BTCUSDT' => 50_000_000, 'LOWVOLUSDT' => 500]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $records = iterator_to_array((new BinancePublicUniverseProvider(new BinancePublicRestClient()))->universeAsOf($now), false);
        $bySymbol = [];
        foreach ($records as $r) {
            $bySymbol[$r->symbol] = $r;
        }

        $this->assertTrue($bySymbol['BTCUSDT']->eligibleForAnalysis);
        $this->assertFalse($bySymbol['LOWVOLUSDT']->eligibleForAnalysis);
        $this->assertContains('INSUFFICIENT_LIQUIDITY_EVIDENCE', $bySymbol['LOWVOLUSDT']->reasonCodes);
        $this->assertFalse($bySymbol['NOVOLUSDT']->eligibleForAnalysis);
        $this->assertContains('MISSING_VOLUME_EVIDENCE', $bySymbol['NOVOLUSDT']->reasonCodes);
    }

    /** @test */
    public function caps_ranked_eligible_candidates_at_top_k(): void
    {
        $this->enable(topK: 1);
        $this->fakeExchangeInfoAndTickers([
            ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
            ['symbol' => 'ETHUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
        ], ['BTCUSDT' => 50_000_000, 'ETHUSDT' => 40_000_000]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $records = iterator_to_array((new BinancePublicUniverseProvider(new BinancePublicRestClient()))->universeAsOf($now), false);
        $bySymbol = [];
        foreach ($records as $r) {
            $bySymbol[$r->symbol] = $r;
        }

        $this->assertTrue($bySymbol['BTCUSDT']->eligibleForAnalysis);
        $this->assertSame(1, $bySymbol['BTCUSDT']->rank);
        $this->assertFalse($bySymbol['ETHUSDT']->eligibleForAnalysis);
        $this->assertContains('OUTSIDE_TOP_K_CAP', $bySymbol['ETHUSDT']->reasonCodes);
        $this->assertNull($bySymbol['ETHUSDT']->rank);
    }

    /** @test */
    public function every_record_is_execution_authorized_false(): void
    {
        $this->enable();
        $this->fakeExchangeInfoAndTickers([
            ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT'],
        ], ['BTCUSDT' => 50_000_000]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ((new BinancePublicUniverseProvider(new BinancePublicRestClient()))->universeAsOf($now) as $record) {
            $this->assertFalse($record->toArray()['execution_authorized']);
            $this->assertNull($record->direction);
        }
    }
}
