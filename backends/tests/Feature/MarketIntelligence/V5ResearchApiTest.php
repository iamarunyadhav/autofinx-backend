<?php
namespace Tests\Feature\MarketIntelligence;
use App\Models\User;
use App\Domain\MarketIntelligence\V5\Application\ResearchScanner;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class V5ResearchApiTest extends TestCase
{
 use RefreshDatabase;
 public function test_research_api_requires_authentication(): void { $this->getJson('/api/v5/research/overview')->assertUnauthorized(); }
 public function test_authenticated_api_is_read_only_and_disables_execution(): void { $u=User::factory()->create(); $this->actingAs($u)->getJson('/api/v5/research/overview')->assertOk()->assertJsonPath('execution_authorized',false); $this->actingAs($u)->getJson('/api/v5/research/predictions')->assertOk()->assertJsonPath('execution_authorized',false); }
 public function test_authenticated_api_reads_persisted_research_candidate(): void { $provider=new DeterministicMarketFixtureProvider(); $scanner=new ResearchScanner($provider,$provider); $result=$scanner->scan(new \DateTimeImmutable('2026-05-01T00:00:00Z'),3); $scanner->persist($result,'fixture',3); $u=User::factory()->create(); $this->actingAs($u)->getJson('/api/v5/research/latest')->assertOk()->assertJsonPath('execution_authorized',false)->assertJsonPath('run.candidates.0.execution_authorized',false); }
}
