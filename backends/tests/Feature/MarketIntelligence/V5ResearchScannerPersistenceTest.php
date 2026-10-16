<?php
namespace Tests\Feature\MarketIntelligence;
use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class V5ResearchScannerPersistenceTest extends TestCase
{
 use RefreshDatabase;
 public function test_fixture_run_persists_idempotently(): void { $s=new ResearchScanner(new DeterministicMarketFixtureProvider()); $r=$s->scan(new \DateTimeImmutable('2026-05-01T00:00:00Z'),3); $one=$s->persist($r,'fixture',3); $two=$s->persist($r,'fixture',3); $this->assertSame($one->id,$two->id); $this->assertCount(1,$two->candidates); $this->assertFalse($two->candidates->first()->execution_authorized); }
}
