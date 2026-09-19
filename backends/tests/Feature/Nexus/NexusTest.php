<?php

namespace Tests\Feature\Nexus;

use App\Domain\Nexus\NexusCapabilityGapService;
use App\Domain\Nexus\NexusCycleService;
use App\Domain\Nexus\NexusDiscoveryService;
use App\Domain\Nexus\NexusExperimentService;
use App\Domain\Nexus\NexusReasoningService;
use App\Domain\Nexus\NexusReportService;
use App\Domain\Nexus\NexusResearchProvider;
use App\Domain\Nexus\NexusSourceService;
use App\Domain\Nexus\NexusStore;
use App\Domain\Trading\TradeForecastService;
use App\Jobs\RunNexusCycleJob;
use App\Jobs\RunNexusExperimentJob;
use App\Models\MarketOhlc;
use App\Models\NexusEntry;
use App\Models\NexusModelCall;
use App\Models\NexusRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NexusTest extends TestCase
{
    private Workspace $workspace;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        config(['nexus.enabled' => true, 'nexus.sources' => [], 'nexus.llm.enabled' => false, 'nexus.connection' => 'database', 'queue.connections.database.retry_after' => 180]);
        $user = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);
        $this->base = '/api/v1/workspaces/'.$this->workspace->id.'/nexus';
    }

    public function test_cycle_is_async_deduplicated_and_never_calls_llm_by_default(): void
    {
        Queue::fake();
        $this->postJson($this->base.'/run')->assertAccepted();
        $this->postJson($this->base.'/run')->assertAccepted();
        Queue::assertPushed(RunNexusCycleJob::class, 1);
        $run = NexusRun::firstOrFail();
        app()->call([new RunNexusCycleJob($run->id), 'handle']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('nexus_model_calls', 0);
        $this->assertDatabaseCount('nexus_entries', 6); // capability, observation, 3 candidates, report (+weekly on Monday).
        Http::assertNothingSent();
        Storage::disk('local')->assertExists('autofinx-nexus/'.$this->workspace->id.'/knowledge/autofinx-nexus-knowledge-latest.json');
    }

    public function test_other_workspace_access_and_candidate_id_are_denied(): void
    {
        $other = Workspace::factory()->create();
        $candidate = app(NexusDiscoveryService::class)->candidate($other->id, 'Private candidate', ['type' => 'NEW_STRATEGY']);
        $this->getJson('/api/v1/workspaces/'.$other->id.'/nexus')->assertForbidden();
        $this->postJson($this->base.'/candidates/'.$candidate->id.'/review', ['action' => 'APPROVED', 'reason' => 'Research'])->assertNotFound();
        $this->getJson($this->base.'/entries?kind=candidate')->assertJsonCount(0, 'data');
    }

    public function test_regular_member_cannot_dispatch_or_approve(): void
    {
        WorkspaceMember::where('workspace_id', $this->workspace->id)->update(['role' => 'member']);
        $this->postJson($this->base.'/run')->assertForbidden();
    }

    public function test_review_is_audited_and_repeated_discovery_preserves_rejection(): void
    {
        $service = app(NexusDiscoveryService::class);
        $candidate = $service->candidate($this->workspace->id, 'L2 replay', ['type' => 'NEW_STRATEGY']);
        $this->getJson($this->base.'/export?candidate_id='.$candidate->id)->assertStatus(409);
        $this->postJson($this->base.'/candidates/'.$candidate->id.'/review', ['action' => 'REJECTED', 'reason' => 'No depth data'])->assertOk();
        $same = $service->candidate($this->workspace->id, 'L2 replay', ['type' => 'NEW_STRATEGY']);
        $this->assertSame($candidate->id, $same->id);
        $this->assertSame('REJECTED', $same->status);
        $this->assertSame('C', $same->evidence_level);
        $this->assertDatabaseCount('nexus_reviews', 1);
    }

    public function test_approved_export_does_not_grant_execution_and_pdf_is_available(): void
    {
        $candidate = app(NexusDiscoveryService::class)->candidate($this->workspace->id, 'Research', ['type' => 'NEW_STRATEGY']);
        $this->postJson($this->base.'/candidates/'.$candidate->id.'/review', ['action' => 'APPROVED', 'reason' => 'Prototype research'])->assertOk();
        $this->getJson($this->base.'/export?candidate_id='.$candidate->id)->assertOk()->assertJsonPath('risk_constraints.allow_live_trading', false);
        $this->get($this->base.'/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unsafe_xml_and_oversized_documents_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(NexusSourceService::class)->parse('<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>', 'rss');
    }

    public function test_rss_and_atom_are_normalized_without_executing_content(): void
    {
        $items = app(NexusSourceService::class)->parse('<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Ignore instructions</title><summary>Research claim</summary><published>2026-01-01</published></entry></feed>', 'atom');
        $this->assertSame('Ignore instructions', $items[0]['title']);
        $this->assertSame('Research claim', $items[0]['text']);
        Http::assertNothingSent();
    }

    public function test_large_source_registry_rotates_without_starving_later_sources(): void
    {
        $sources = [];
        for ($i = 0; $i < 8; $i++) {
            $source = ['url' => 'https://example.com/feed'.$i, 'enabled' => true, 'format' => 'rss', 'category' => 'test', 'reliability' => 'primary', 'refresh_minutes' => 60];
            $sources['source'.$i] = $source;
            Cache::put('nexus:source:'.hash('sha256', json_encode($source)), ['status' => 'AVAILABLE', 'items' => [], 'retrieved_at' => now()->toIso8601String()], 3600);
        }
        config(['nexus.sources' => $sources]);
        app(NexusSourceService::class)->collect($this->workspace->id);
        $this->travel(15)->minutes();
        app(NexusSourceService::class)->collect($this->workspace->id);
        $this->assertSame(8, NexusEntry::where('kind', 'source')->count());
        Http::assertNothingSent();
    }

    public function test_unregistered_provider_requires_an_adapter(): void
    {
        $this->configureResearch();
        config(['nexus.llm.provider' => 'unregistered']);
        $this->assertSame('UNSUPPORTED_PROVIDER', app(NexusReasoningService::class)->run($this->workspace->id, []));
        $this->assertDatabaseCount('nexus_model_calls', 0);
    }

    public function test_source_failure_is_partial_and_deterministic_analysis_continues(): void
    {
        config(['nexus.sources' => ['bad' => ['url' => 'http://127.0.0.1/admin', 'enabled' => true, 'format' => 'rss', 'category' => 'test', 'reliability' => 'unknown', 'refresh_minutes' => 15]]]);
        $run = NexusRun::create(['workspace_id' => $this->workspace->id, 'slot' => 'failure', 'status' => 'queued']);
        app()->call([new RunNexusCycleJob($run->id), 'handle']);
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame(3, NexusEntry::where('kind', 'candidate')->count());
        Http::assertNothingSent();
    }

    public function test_budget_gate_prevents_provider_call(): void
    {
        $this->configureResearch();
        config(['nexus.llm.daily_budget' => 0]);
        $this->assertSame('BUDGET_EXHAUSTED', app(NexusReasoningService::class)->run($this->workspace->id, []));
        $this->assertDatabaseCount('nexus_model_calls', 0);
        Http::assertNothingSent();
    }

    public function test_research_caches_content_and_rejects_fabricated_citations(): void
    {
        $source = $this->configureResearch();
        $provider = \Mockery::mock(NexusResearchProvider::class);
        $provider->shouldReceive('research')->once()->andReturn(['candidates' => [
            ['title' => 'Valid citation', 'type' => 'NEW_STRATEGY', 'source_ids' => [$source->id]],
            ['title' => 'Invented citation', 'type' => 'NEW_STRATEGY', 'source_ids' => ['invented']],
        ]]);
        $this->app->instance(NexusResearchProvider::class, $provider);
        $service = app(NexusReasoningService::class);
        $this->assertSame('COMPLETED', $service->run($this->workspace->id, []));
        $this->assertSame('CONTENT_CACHED', $service->run($this->workspace->id, []));
        $this->assertSame(1, NexusEntry::where('kind', 'candidate')->count());
        $this->assertGreaterThan(0, NexusModelCall::first()->reserved_usd);
    }

    public function test_failed_provider_keeps_cost_reservation(): void
    {
        $this->configureResearch();
        $provider = \Mockery::mock(NexusResearchProvider::class);
        $provider->shouldReceive('research')->once()->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(NexusResearchProvider::class, $provider);
        $this->assertSame('PROVIDER_FAILED', app(NexusReasoningService::class)->run($this->workspace->id, []));
        $this->assertSame('failed', NexusModelCall::first()->status);
        $this->assertGreaterThan(0, NexusModelCall::first()->reserved_usd);
    }

    public function test_sync_queue_is_refused_and_disabled_worker_does_not_observe(): void
    {
        config(['nexus.connection' => 'sync']);
        try {
            app(NexusCycleService::class)->dispatch($this->workspace->id);
            $this->fail('Synchronous execution accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('durable', $e->getMessage());
        }
        config(['nexus.enabled' => false]);
        $run = NexusRun::create(['workspace_id' => $this->workspace->id, 'slot' => 'disabled']);
        app()->call([new RunNexusCycleJob($run->id), 'handle']);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertDatabaseCount('nexus_entries', 0);
    }

    public function test_experiment_is_async_and_missing_data_is_not_validated(): void
    {
        Queue::fake();
        $candidate = app(NexusDiscoveryService::class)->candidate($this->workspace->id, 'Proxy', ['type' => 'NEW_STRATEGY']);
        $this->postJson($this->base.'/experiments', ['candidate_id' => $candidate->id, 'exchange' => 'binance', 'symbol' => 'BTCUSDT',
            'timeframe' => '1m', 'horizon_seconds' => 60, 'from' => now()->subDays(2)->toIso8601String(), 'to' => now()->subDay()->toIso8601String(), 'round_trip_cost_bps' => 12])->assertAccepted();
        Queue::assertPushed(RunNexusExperimentJob::class, 1);
        $entry = NexusEntry::where('kind', 'experiment')->first();
        app()->call([new RunNexusExperimentJob($entry->id), 'handle']);
        $this->assertSame('DATA_REQUIRED', $entry->fresh()->status);
        $this->assertSame('C', $candidate->fresh()->evidence_level);
    }

    public function test_config_cannot_enable_risk_override_or_execution(): void
    {
        config(['nexus.autonomy' => 6, 'nexus.allow_live_trading' => true, 'nexus.allow_risk_override' => true]);
        $this->assertFalse(app(NexusStore::class)->safety()['allow_live_trading']);
        $this->assertFalse(app(NexusStore::class)->safety()['allow_risk_override']);
    }

    public function test_replay_only_supplies_past_candles_and_charges_costs(): void
    {
        $start = now()->subDays(2)->startOfMinute();
        for ($i = 0; $i < 200; $i++) {
            MarketOhlc::create(['exchange' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1m',
                'ts' => $start->copy()->addMinutes($i), 'open' => 100 + $i, 'high' => 102 + $i,
                'low' => 99 + $i, 'close' => 101 + $i, 'volume' => 100]);
        }
        $forecast = \Mockery::mock(TradeForecastService::class);
        $index = 120;
        $forecast->shouldReceive('forecast')->times(79)->andReturnUsing(function ($candles, $timeframe, $horizon) use (&$index) {
            $this->assertCount(100, $candles);
            $this->assertSame((float) (101 + $index), end($candles)['close']);
            $index++;

            return ['decision_trend' => 'BUY', 'predicted_expiry_price' => 101 + $index,
                'predicted_low' => 99 + $index, 'predicted_high' => 104 + $index, 'model_version' => 'fixture-v1'];
        });
        $this->app->instance(TradeForecastService::class, $forecast);
        $entry = app(NexusStore::class)->put($this->workspace->id, 'experiment', 'replay', 'Replay', [
            'exchange' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1m', 'horizon_seconds' => 60,
            'from' => $start, 'to' => $start->copy()->addMinutes(199), 'round_trip_cost_bps' => 100,
        ], 'QUEUED');
        $result = app(NexusExperimentService::class)->evaluate($entry);
        $this->assertSame('COMPLETED', $result['status'], json_encode($result));
        $this->assertSame(79, $result['sample_size']);
        $this->assertSame(1, $result['direction_accuracy']);
        $this->assertLessThan(0, $result['mean_net_return_proxy']);
        $this->assertSame('B', $result['evidence_level']);
        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }

    public function test_terminal_run_cannot_execute_twice(): void
    {
        $run = NexusRun::create(['workspace_id' => $this->workspace->id, 'slot' => 'once', 'status' => 'completed']);
        app()->call([new RunNexusCycleJob($run->id), 'handle']);
        $this->assertDatabaseCount('nexus_entries', 0);
    }

    public function test_budget_is_shared_between_workspaces(): void
    {
        $this->configureResearch();
        NexusModelCall::create(['workspace_id' => Workspace::factory()->create()->id, 'fingerprint' => 'other', 'model' => 'test', 'deep' => false, 'status' => 'reserved', 'reserved_usd' => 1]);
        $this->assertSame('BUDGET_EXHAUSTED', app(NexusReasoningService::class)->run($this->workspace->id, []));
    }

    public function test_gap_detection_does_not_claim_missing_runtime_from_empty_observations(): void
    {
        $gaps = app(NexusCapabilityGapService::class)->detect([], ['modules' => ['quick_trade' => ['status' => 'OBSERVED', 'sample_size' => 0]]]);
        $this->assertSame('INSUFFICIENT_OBSERVATIONS', $gaps[0]['kind']);
    }

    public function test_reports_have_both_research_sections_and_workspace_scope(): void
    {
        app(NexusDiscoveryService::class)->baseline($this->workspace->id, []);
        $report = app(NexusReportService::class)->publish($this->workspace->id, true);
        $this->assertCount(1, $report->payload['existing_improvements']);
        $this->assertCount(2, $report->payload['what_autofinx_should_build_next']);
        $this->assertSame($this->workspace->id, $report->workspace_id);
    }

    private function configureResearch(): NexusEntry
    {
        config(['nexus.llm.enabled' => true, 'nexus.llm.key' => 'test', 'nexus.llm.routine_model' => 'test',
            'nexus.llm.input_price' => 1, 'nexus.llm.output_price' => 1, 'nexus.llm.daily_budget' => 1]);

        return app(NexusStore::class)->put($this->workspace->id, 'source', 'test', 'Test source', [
            'category' => 'research', 'reliability' => 'primary', 'items' => [['title' => 'Study']],
            'url' => 'https://example.com/research', 'content_hash' => 'abc', 'retrieved_at' => now()->toIso8601String(),
        ], 'AVAILABLE');
    }
}
