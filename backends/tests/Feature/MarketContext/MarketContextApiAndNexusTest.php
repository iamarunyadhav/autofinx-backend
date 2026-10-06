<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\MarketContextObservation;
use App\Domain\MarketContext\SnapshotBuilder;
use App\Domain\MarketContext\SnapshotStore;
use App\Domain\Nexus\NexusObservationService;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MarketContextApiAndNexusTest extends MarketContextTestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);
    }

    private function url(string $class = 'CRYPTO', string $instrument = 'BTC', ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->id.'/market-context/'.$class.'/'.$instrument;
    }

    public function test_member_gets_the_snapshot_contract_shape(): void
    {
        $this->event('BTC', '2026-10-06 03:00:00', severity: 'HIGH', title: 'Exchange hacked');
        $this->metric('ALL', 'fear_greed', 30, '2026-10-06 00:00:00');

        $json = $this->getJson($this->url('crypto', 'btc'))->assertOk()->json();

        $this->assertEqualsCanonicalizing(['instrument', 'asset_class', 'as_of', 'schema_version', 'freshness', 'sentiment', 'risk', 'metrics', 'drivers', 'provenance'], array_keys($json));
        $this->assertSame(['BTC', 'CRYPTO', 1], [$json['instrument'], $json['asset_class'], $json['schema_version']]);
        $this->assertSame('Exchange hacked', $json['drivers'][0]['title']);
        $this->assertEquals(30.0, $json['metrics']['fear_greed']);
        $this->assertEqualsCanonicalizing(['state', 'newest_item_age_s', 'sources'], array_keys($json['freshness']));
        $this->assertEqualsCanonicalizing(['level', 'flags', 'veto_hint', 'reasons'], array_keys($json['risk']));
    }

    public function test_non_member_is_forbidden(): void
    {
        $other = Workspace::factory()->create();

        $this->getJson($this->url(workspace: $other))->assertForbidden();
    }

    public function test_unknown_disabled_or_mismatched_instruments_are_404_and_flag_off_is_404(): void
    {
        $this->getJson($this->url('CRYPTO', 'DOGE'))->assertNotFound();
        $this->getJson($this->url('METALS', 'XAU'))->assertNotFound();      // disabled placeholder
        $this->getJson($this->url('FOREX', 'BTC'))->assertNotFound();       // wrong asset class

        config(['market_context.enabled' => false]);
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_route_is_read_only(): void
    {
        $this->postJson($this->url())->assertStatus(405);
        $this->deleteJson($this->url())->assertStatus(405);
    }

    public function test_nexus_observation_exposes_latest_snapshot_summaries_additively(): void
    {
        $this->event('BTC', '2026-10-06 03:00:00', severity: 'CRITICAL', title: 'Hack');
        app(SnapshotStore::class)->store(app(SnapshotBuilder::class)->build(new ContextQuery('CRYPTO', 'BTC', Carbon::parse('2026-10-06 03:15:00', 'UTC'))));

        $capture = app(NexusObservationService::class)->capture($this->workspace->id);

        $this->assertArrayHasKey('modules', $capture);          // existing keys untouched
        $this->assertArrayHasKey('limitations', $capture);
        $mc = $capture['market_context'];
        $this->assertSame('OBSERVED', $mc['status']);
        $this->assertSame('OBSERVED', $mc['instruments']['BTC']['status']);
        $this->assertSame('CRITICAL', $mc['instruments']['BTC']['risk_level']);
        $this->assertTrue($mc['instruments']['BTC']['veto_hint']);
        $this->assertSame('NO_SNAPSHOT', $mc['instruments']['ETH']['status']);
        $this->assertArrayNotHasKey('XAU', $mc['instruments']);
        $this->assertArrayNotHasKey('drivers', $mc['instruments']['BTC'], 'summary is scalar-only: no titles/urls');
    }

    public function test_nexus_hook_is_disabled_when_flag_off_and_fail_safe_when_tables_are_missing(): void
    {
        config(['market_context.enabled' => false]);
        $this->assertSame(['status' => 'DISABLED'], app(NexusObservationService::class)->capture($this->workspace->id)['market_context']);

        config(['market_context.enabled' => true]);
        Schema::drop('mctx_snapshots');
        $this->assertSame(['status' => 'ADAPTER_REQUIRED'], app(MarketContextObservation::class)->summary());
    }
}
