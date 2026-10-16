<?php
namespace Tests\Unit\MarketIntelligence\V5;
use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use Tests\TestCase;
final class ResearchScannerTest extends TestCase
{
 public function test_fixture_scan_is_bounded_and_never_authorizes_execution(): void { $r=(new ResearchScanner(new DeterministicMarketFixtureProvider()))->scan(new \DateTimeImmutable('2026-05-01T00:00:00Z'),3); $this->assertFalse($r['execution_authorized']); $this->assertCount(1,$r['candidates']); $this->assertSame('FIXTUREAUSDT',$r['candidates'][0]['symbol']); $this->assertSame('UNVALIDATED_RESEARCH_CANDIDATES',$r['candidates'][0]['research_status']); $this->assertFalse($r['candidates'][0]['execution_authorized']); }
 public function test_no_candidates_are_forced(): void { $r=(new ResearchScanner(new DeterministicMarketFixtureProvider()))->scan(new \DateTimeImmutable('2025-01-01T00:00:00Z'),3); $this->assertSame([], $r['candidates']); }
}
