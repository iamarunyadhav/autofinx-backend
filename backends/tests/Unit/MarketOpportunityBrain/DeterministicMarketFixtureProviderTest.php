<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use Tests\TestCase;

/**
 * Covers PHASES_AND_TEST_GATES.md invariants:
 *  - test_unlisted_or_delisted_historical_symbol_excluded_at_asof
 *  - test_no_future_feature_known_at_after_decision (candles never extend past asOfUtc)
 *  - determinism (same asOfUtc => byte-identical output, no RNG/wall-clock dependency)
 */
class DeterministicMarketFixtureProviderTest extends TestCase
{
    /** @test */
    public function delisted_symbol_is_excluded_from_the_universe_after_its_delisting_time(): void
    {
        $provider = new DeterministicMarketFixtureProvider();

        $beforeDelisting = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $afterDelisting = new \DateTimeImmutable('2026-07-01T00:00:00Z');

        $symbolsBefore = array_map(fn ($r) => $r->symbol, iterator_to_array($provider->universeAsOf($beforeDelisting), false));
        $symbolsAfter = array_map(fn ($r) => $r->symbol, iterator_to_array($provider->universeAsOf($afterDelisting), false));

        $this->assertContains('FIXTUREBUSDT', $symbolsBefore);
        $this->assertNotContains('FIXTUREBUSDT', $symbolsAfter, 'A delisted symbol must never appear in a later as-of universe.');
        $this->assertContains('FIXTUREAUSDT', $symbolsAfter, 'A never-delisted symbol must remain present.');
    }

    /** @test */
    public function not_yet_listed_symbol_is_excluded(): void
    {
        $provider = new DeterministicMarketFixtureProvider();
        $beforeListing = new \DateTimeImmutable('2025-01-01T00:00:00Z');

        $records = iterator_to_array($provider->universeAsOf($beforeListing), false);

        $this->assertCount(0, $records);
    }

    /** @test */
    public function closed_candles_never_extend_past_the_as_of_timestamp(): void
    {
        $provider = new DeterministicMarketFixtureProvider();
        $asOf = new \DateTimeImmutable('2026-02-01T00:07:00Z'); // 7 minutes into an open 15m bar

        $candles = $provider->closedCandles('FIXTUREAUSDT', '15m', $asOf, 10);

        $this->assertNotEmpty($candles);
        foreach ($candles as $candle) {
            $closeTs = (new \DateTimeImmutable($candle->closeTimeUtc))->getTimestamp();
            $this->assertLessThanOrEqual($asOf->getTimestamp(), $closeTs, 'A returned candle must never close after as-of.');
            $this->assertTrue($candle->isClosed);
        }
    }

    /** @test */
    public function output_is_deterministic_across_repeated_calls(): void
    {
        $provider = new DeterministicMarketFixtureProvider();
        $asOf = new \DateTimeImmutable('2026-03-15T12:00:00Z');

        $first = array_map(fn ($c) => $c->toArray(), $provider->closedCandles('FIXTUREAUSDT', '15m', $asOf, 20));
        $second = array_map(fn ($c) => $c->toArray(), $provider->closedCandles('FIXTUREAUSDT', '15m', $asOf, 20));

        $this->assertSame($first, $second);

        $universe1 = array_map(fn ($r) => $r->toArray(), iterator_to_array($provider->universeAsOf($asOf), false));
        $universe2 = array_map(fn ($r) => $r->toArray(), iterator_to_array($provider->universeAsOf($asOf), false));
        $this->assertSame($universe1, $universe2);
    }

    /** @test */
    public function discovery_rank_is_never_a_direction_claim(): void
    {
        $provider = new DeterministicMarketFixtureProvider();
        $asOf = new \DateTimeImmutable('2026-03-15T12:00:00Z');

        foreach ($provider->universeAsOf($asOf) as $record) {
            $this->assertNull($record->direction, 'Discovery layer must never assign a direction (MASTER_SPEC.md Layer E).');
            $this->assertFalse($record->toArray()['execution_authorized']);
        }
    }

    /** @test */
    public function low_liquidity_symbol_is_marked_ineligible_with_a_reason_code_not_silently_dropped(): void
    {
        $provider = new DeterministicMarketFixtureProvider();
        $asOf = new \DateTimeImmutable('2026-03-15T12:00:00Z');

        $records = iterator_to_array($provider->universeAsOf($asOf), false);
        $lowLiquidity = array_values(array_filter($records, fn ($r) => $r->symbol === 'FIXTUREBUSDT'))[0] ?? null;

        $this->assertNotNull($lowLiquidity);
        $this->assertFalse($lowLiquidity->eligibleForAnalysis);
        $this->assertContains('INSUFFICIENT_LIQUIDITY_EVIDENCE', $lowLiquidity->reasonCodes);
        $this->assertNull($lowLiquidity->rank);
    }
}
