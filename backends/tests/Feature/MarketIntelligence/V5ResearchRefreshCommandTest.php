<?php
namespace Tests\Feature\MarketIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class V5ResearchRefreshCommandTest extends TestCase
{
 use RefreshDatabase;
 public function test_manual_refresh_persists_one_idempotent_fixture_run(): void { $args=['--fixture'=>true,'--as-of'=>'2026-05-01T00:00:00Z','--limit'=>3]; $this->artisan('v5:research-refresh',$args)->assertSuccessful(); $this->artisan('v5:research-refresh',$args)->assertSuccessful(); $this->assertDatabaseCount('v5_research_scanner_runs',1); $this->assertDatabaseCount('v5_research_scanner_candidates',1); }
}
