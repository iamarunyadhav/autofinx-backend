<?php

namespace Tests\Feature\MarketIntelligence;

use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use App\Models\V5MarketObservation;
use App\Models\V5ShadowPrediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V5ResearchScannerPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_run_persists_idempotently(): void
    {
        $provider = new DeterministicMarketFixtureProvider;
        $s = new ResearchScanner($provider, $provider);
        $r = $s->scan(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 3);
        $one = $s->persist($r, 'fixture', 3);
        $observationCount = V5MarketObservation::count();
        $two = $s->persist($r, 'fixture', 3);
        $this->assertSame($one->id, $two->id);
        $this->assertCount(1, $two->candidates);
        $this->assertFalse($two->candidates->first()->execution_authorized);
        $this->assertGreaterThan(0, $observationCount);
        $this->assertSame($observationCount, V5MarketObservation::count());
        $this->assertNotNull(V5ShadowPrediction::first()?->reference_price);
    }
}
