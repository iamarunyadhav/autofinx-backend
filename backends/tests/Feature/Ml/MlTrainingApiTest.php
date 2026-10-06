<?php

namespace Tests\Feature\Ml;

use App\Domain\Ml\Training\TrainingCounts;
use App\Domain\Ml\Training\TrainingSystemStats;
use App\Jobs\ResumeMlBackfillJob;
use App\Jobs\TrainMlShadowModelJob;
use App\Models\MlBackfillCheckpoint;
use App\Models\MlChampion;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use App\Models\SchedulerRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

final class MlTrainingApiTest extends TestCase
{
    use MlTrainingTestSupport;

    private string $originalTimezone;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->bootMlTraining();
        Queue::fake();
        [$this->workspace, $this->owner] = $this->workspaceWithRole('owner');
    }

    protected function tearDown(): void
    {
        $this->cleanMlTraining();
        date_default_timezone_set($this->originalTimezone);
        config(['app.timezone' => $this->originalTimezone]);
        parent::tearDown();
    }

    private function url(string $path = '', ?Workspace $workspace = null): string
    {
        return '/api/v1/workspaces/'.($workspace ?? $this->workspace)->id.'/ml-training'.$path;
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    // ---- auth ---------------------------------------------------------------

    public function test_a_non_member_is_forbidden_on_every_route(): void
    {
        $outsider = User::factory()->create();
        $run = $this->makeRun();
        $this->actingAs($outsider);

        foreach (['/overview', '/system', '/runs', '/runs/'.$run->id, '/candles/BTCUSDT', '/models'] as $path) {
            $this->getJson($this->url($path))->assertForbidden();
        }
        foreach (['/runs', '/batches', '/runs/'.$run->id.'/stop', '/runs/'.$run->id.'/retry', '/batches/'.Str::uuid().'/stop', '/backfill/BTCUSDT/resume'] as $path) {
            $this->postJson($this->url($path), [])->assertForbidden();
        }
        $this->assertSame(1, MlTrainingRun::query()->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson($this->url('/overview'))->assertUnauthorized();
        $this->postJson($this->url('/runs'), [])->assertUnauthorized();
    }

    public function test_a_plain_member_can_read_but_never_mutate_and_owner_or_admin_can(): void
    {
        $viewer = $this->member('viewer');
        $run = $this->makeRun();
        $this->actingAs($viewer);

        $this->getJson($this->url('/overview'))->assertOk();
        $this->getJson($this->url('/runs/'.$run->id))->assertOk();
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 120])->assertForbidden();
        $this->postJson($this->url('/batches'), ['preset' => 'long'])->assertForbidden();
        $this->postJson($this->url('/runs/'.$run->id.'/stop'))->assertForbidden();
        $this->postJson($this->url('/runs/'.$run->id.'/retry'))->assertForbidden();
        $this->postJson($this->url('/backfill/BTCUSDT/resume'))->assertForbidden();
        $this->assertSame(MlTrainingRun::QUEUED, $run->refresh()->status);

        $this->actingAs($this->member('admin'))->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 120])->assertStatus(202);
        $this->actingAs($this->owner)->postJson($this->url('/runs'), ['symbol' => 'ETHUSDT', 'horizon_minutes' => 120])->assertStatus(202);
    }

    public function test_membership_is_checked_against_the_workspace_in_the_url(): void
    {
        [$otherWorkspace] = $this->workspaceWithRole('owner');
        $this->actingAs($this->owner)->getJson($this->url('/overview', $otherWorkspace))->assertForbidden();
    }

    // ---- overview -----------------------------------------------------------

    public function test_overview_has_the_contracted_shape(): void
    {
        $this->seedCandles('BTCUSDT', $this->alignedOpen(20), 20);
        MlBackfillCheckpoint::query()->create([
            'provider' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'range_from_ms' => 1, 'range_to_ms' => 2, 'cursor_ms' => 1_700_000_000_000,
            'status' => 'COMPLETED', 'pages' => 7, 'rows_written' => 7000,
        ]);
        $model = MlModel::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'timeframe' => '5m', 'feature_schema_version' => 'v', 'model_type' => 'regression',
            'status' => 'CHAMPION', 'model_version' => 'qtml-abc', 'trained_at' => now('UTC')]);
        MlChampion::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'model_id' => $model->id]);
        $active = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'phase' => 'fitting', 'started_at' => $this->utcAgo(100), 'heartbeat_at' => $this->utcAgo(5)]);
        $this->makeRun(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::FINISHED, 'outcome' => 'REJECTED', 'outcome_reason' => 'too_few_trades', 'finished_at' => $this->utcAgo(60), 'started_at' => $this->utcAgo(900)]);

        $response = $this->actingAs($this->owner)->getJson($this->url('/overview'))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertJsonStructure([
            'generated_at', 'enabled', 'mode', 'counts_as_of', 'alerts',
            'symbols' => [['symbol', 'candles' => ['state', 'latest_close', 'age_seconds', 'collected', 'target', 'coverage_pct', 'rejected'],
                'backfill', 'features' => ['rows', 'state'], 'labels' => ['pending', 'mature'], 'funding' => ['latest', 'state'],
                'horizons' => [['horizon_minutes', 'tradable', 'champion', 'last_run', 'active_run', 'retrain' => ['eligible', 'reason', 'new_mature', 'required']]]]],
            'active_runs' => [['id', 'status', 'can_stop', 'can_retry']],
            'queue' => ['pending', 'reserved', 'failed'],
            'pipeline_jobs' => [['command', 'last_started_at', 'last_status', 'age_seconds', 'state']],
        ]);
        $json = $response->json();
        $this->assertTrue($json['enabled']);
        $this->assertSame('shadow_only', $json['mode']);
        $btc = collect($json['symbols'])->firstWhere('symbol', 'BTCUSDT');
        $eth = collect($json['symbols'])->firstWhere('symbol', 'ETHUSDT');
        $this->assertSame('live', $btc['candles']['state']);
        $this->assertSame(20, $btc['candles']['collected']);
        $this->assertSame('COMPLETED', $btc['backfill']['status']);
        $this->assertSame(7, $btc['backfill']['pages']);
        $this->assertSame('missing', $eth['candles']['state']);
        $this->assertNull($eth['backfill']);
        $this->assertSame('missing', $btc['funding']['state']);
        $h60 = collect($btc['horizons'])->firstWhere('horizon_minutes', 60);
        $this->assertSame('qtml-abc', $h60['champion']['model_version']);
        $this->assertSame($active->id, $h60['active_run']['id']);
        $this->assertSame('fitting', $h60['active_run']['phase']);
        $this->assertSame(5, $h60['active_run']['heartbeat_age_seconds']);
        $this->assertNull(collect($btc['horizons'])->firstWhere('horizon_minutes', 120)['champion']);
        $ethH60 = collect($eth['horizons'])->firstWhere('horizon_minutes', 60);
        $this->assertSame('REJECTED', $ethH60['last_run']['outcome']);
        $this->assertSame([$active->id], array_column($json['active_runs'], 'id'));
        $this->assertCount(count(config('ml.symbols')), $json['symbols']);
        $this->assertCount(5, $btc['horizons']);
    }

    public function test_overview_counts_are_cached_for_five_minutes_and_labelled(): void
    {
        $this->seedCandles('BTCUSDT', $this->alignedOpen(10), 10);
        $this->actingAs($this->owner);
        $first = $this->getJson($this->url('/overview'))->assertOk()->json();
        $this->seedCandles('BTCUSDT', $this->alignedOpen(500), 40);

        $second = $this->getJson($this->url('/overview'))->assertOk()->json();

        $this->assertSame($first['counts_as_of'], $second['counts_as_of']);
        $this->assertSame(10, collect($second['symbols'])->firstWhere('symbol', 'BTCUSDT')['candles']['collected']);
        Cache::forget(TrainingCounts::FRESH_KEY);
        $third = $this->getJson($this->url('/overview'))->json();
        $this->assertSame(50, collect($third['symbols'])->firstWhere('symbol', 'BTCUSDT')['candles']['collected']);
    }

    public function test_overview_alerts_cover_runs_candles_backfill_queue_and_scheduler(): void
    {
        $this->seedCandles('BTCUSDT', $this->alignedOpen(400), 10); // newest candle is ~33 min old -> stale
        MlBackfillCheckpoint::query()->create([
            'provider' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'range_from_ms' => 1, 'range_to_ms' => 2, 'cursor_ms' => 1,
            'status' => 'FAILED', 'last_error' => 'HTTP 418 Binance said no',
        ]);
        $stalled = MlBackfillCheckpoint::query()->create([
            'provider' => 'binance_futures', 'symbol' => 'ETHUSDT', 'timeframe' => '5m', 'range_from_ms' => 1, 'range_to_ms' => 2, 'cursor_ms' => 1, 'status' => 'RUNNING',
        ]);
        DB::table('ml_backfill_checkpoints')->where('id', $stalled->id)->update(['updated_at' => now()->subMinutes(30)->format('Y-m-d H:i:s')]);
        $stalledRun = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(900), 'heartbeat_at' => $this->utcAgo(400)]);
        $failed = $this->makeRun(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::FAILED, 'error_code' => 'PYTHON_EXIT', 'error_message' => 'boom', 'finished_at' => $this->utcAgo(60), 'started_at' => $this->utcAgo(300)]);
        for ($i = 0; $i < 12; $i++) {
            DB::table('jobs')->insert(['queue' => 'ml_training', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        }
        SchedulerRun::create(['id' => (string) Str::uuid(), 'command' => 'ml:candles:sync', 'started_at' => now()->subHour(), 'status' => 'completed']);

        $json = $this->actingAs($this->owner)->getJson($this->url('/overview'))->assertOk()->json();

        $alerts = collect($json['alerts'])->keyBy('code');
        $this->assertSame('critical', $alerts['run_stalled']['severity']);
        $this->assertSame(['type' => 'open_run', 'run_id' => $stalledRun->id], $alerts['run_stalled']['action']);
        $this->assertSame('warning', $alerts['run_failed']['severity']);
        $this->assertSame(['type' => 'retry_run', 'run_id' => $failed->id], $alerts['run_failed']['action']);
        $this->assertSame('ETHUSDT', $alerts['run_failed']['symbol']);
        $this->assertSame(60, $alerts['run_failed']['horizon_minutes']);
        $this->assertSame('warning', $alerts['candles_stale']['severity']);
        $this->assertSame('BTCUSDT', $alerts['candles_stale']['symbol']);
        $this->assertSame(['type' => 'resume_backfill', 'symbol' => 'BTCUSDT'], $alerts['backfill_failed']['action']);
        $this->assertSame(['type' => 'resume_backfill', 'symbol' => 'ETHUSDT'], $alerts['backfill_stalled']['action']);
        $this->assertArrayHasKey('queue_backlog', $alerts->all());
        $this->assertArrayHasKey('scheduler_late', $alerts->all());
        foreach ($json['alerts'] as $alert) {
            $this->assertNotEmpty($alert['id']);
            $this->assertContains($alert['severity'], ['critical', 'warning', 'info']);
            $this->assertNotEmpty($alert['since']);
        }
        $this->assertSame('critical', $json['alerts'][0]['severity']); // critical first
        $this->assertSame(12, $json['queue']['pending']);
        $this->assertSame(0, $json['queue']['reserved']);
        $this->assertSame('STALLED', collect($json['symbols'])->firstWhere('symbol', 'ETHUSDT')['backfill']['status']);
        $late = collect($json['pipeline_jobs'])->firstWhere('command', 'ml:candles:sync');
        $this->assertSame('late', $late['state']);
        $this->assertSame('never', collect($json['pipeline_jobs'])->firstWhere('command', 'ml:retrain:check')['state']);
    }

    // ---- runs ---------------------------------------------------------------

    public function test_runs_list_is_newest_first_filterable_and_paginates_with_a_cursor(): void
    {
        $ids = [];
        foreach ([60, 120, 240] as $h) {
            $ids[] = $this->makeRun(['horizon_minutes' => $h, 'status' => MlTrainingRun::FAILED, 'error_code' => 'OTHER', 'batch_id' => $h === 240 ? null : '11111111-1111-4111-8111-111111111111'])->id;
            usleep(3000);
        }
        $this->makeRun(['symbol' => 'ETHUSDT', 'horizon_minutes' => 60]);
        $this->actingAs($this->owner);

        $page = $this->getJson($this->url('/runs?limit=2'))->assertOk()->assertJsonStructure(['data' => [['id', 'symbol', 'horizon_minutes', 'model_type', 'status', 'outcome', 'outcome_reason', 'phase',
            'progress_pct', 'source', 'triggered_by', 'batch_id', 'attempt', 'max_attempts', 'retry_of', 'next_retry_at', 'queued_at', 'started_at', 'heartbeat_at',
            'heartbeat_age_seconds', 'finished_at', 'elapsed_seconds', 'eta_seconds', 'rows_exported', 'error_code', 'error_message', 'can_stop', 'can_retry', 'model_id']], 'next_cursor'])->json();
        $this->assertCount(2, $page['data']);
        $this->assertSame('ETHUSDT', $page['data'][0]['symbol']);
        $this->assertSame($ids[2], $page['data'][1]['id']);
        $this->assertNotNull($page['next_cursor']);

        $next = $this->getJson($this->url('/runs?limit=2&cursor='.$page['next_cursor']))->json();
        $this->assertSame([$ids[1], $ids[0]], array_column($next['data'], 'id'));
        $this->assertNull($next['next_cursor']);

        $this->assertCount(3, $this->getJson($this->url('/runs?status=FAILED'))->json('data'));
        $this->assertCount(1, $this->getJson($this->url('/runs?status=QUEUED&symbol=ETHUSDT'))->json('data'));
        $this->assertCount(1, $this->getJson($this->url('/runs?horizon_minutes=120'))->json('data'));
        $this->assertCount(2, $this->getJson($this->url('/runs?batch_id=11111111-1111-4111-8111-111111111111'))->json('data'));
    }

    public function test_run_detail_has_log_model_and_the_retry_chain(): void
    {
        $model = MlModel::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'timeframe' => '5m', 'feature_schema_version' => 'v', 'model_type' => 'side_classifier',
            'status' => 'REJECTED', 'model_version' => 'qtml-x', 'decision_reason' => 'too_few_trades', 'comparison' => ['a' => 1],
            'metrics' => ['holdout' => ['non_overlapping' => ['trades' => 12]], 'walk_forward' => ['folds' => [['n' => 1], ['n' => 2]]], 'cost_assumptions' => ['c' => 1]]]);
        $first = $this->makeRun(['status' => MlTrainingRun::FAILED, 'error_code' => 'PYTHON_EXIT', 'finished_at' => $this->utcAgo(300), 'started_at' => $this->utcAgo(600)]);
        $second = $this->makeRun(['status' => MlTrainingRun::FINISHED, 'outcome' => 'REJECTED', 'outcome_reason' => 'too_few_trades', 'attempt' => 2, 'retry_of' => $first->id,
            'model_id' => $model->id, 'log_tail' => "line one\nline two", 'started_at' => $this->utcAgo(200), 'finished_at' => $this->utcAgo(100)]);
        $this->actingAs($this->owner);

        $json = $this->getJson($this->url('/runs/'.$second->id))->assertOk()->assertJsonStructure(['run', 'log_tail', 'model' => ['model_version', 'status', 'decision_reason', 'metrics', 'holdout', 'folds', 'comparison'], 'attempts'])->json();

        $this->assertSame($second->id, $json['run']['id']);
        $this->assertSame(['line one', 'line two'], $json['log_tail']);
        $this->assertSame('qtml-x', $json['model']['model_version']);
        $this->assertSame([['n' => 1], ['n' => 2]], $json['model']['folds']);
        $this->assertSame(12, $json['model']['holdout']['non_overlapping']['trades']);
        $this->assertSame([$first->id, $second->id], array_column($json['attempts'], 'id'));
        $this->assertSame([1, 2], array_column($json['attempts'], 'attempt'));
        $this->assertFalse($json['run']['can_retry']);   // finished
        $this->assertFalse($json['attempts'][0]['can_retry']); // already retried

        $this->assertNull($this->getJson($this->url('/runs/'.$first->id))->json('model'));
        $this->getJson($this->url('/runs/'.Str::uuid()))->assertNotFound();
        $this->getJson($this->url('/runs/not-an-id'))->assertNotFound();
    }

    public function test_eta_comes_from_the_median_of_the_last_five_finished_runs_of_the_horizon(): void
    {
        foreach ([100, 200, 300, 400, 500, 9000] as $seconds) { // last 5 by id: 200..9000 -> median 400
            $this->makeRun(['status' => MlTrainingRun::FINISHED, 'outcome' => 'CHAMPION', 'started_at' => $this->utcAgo($seconds + 50), 'finished_at' => $this->utcAgo(50)]);
            usleep(3000);
        }
        $running = $this->makeRun(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(100), 'heartbeat_at' => $this->utcAgo(2)]);
        $noHistory = $this->makeRun(['symbol' => 'XRPUSDT', 'horizon_minutes' => 240, 'status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(100), 'heartbeat_at' => $this->utcAgo(2)]);
        $this->actingAs($this->owner);

        $row = $this->getJson($this->url('/runs/'.$running->id))->json('run');
        $this->assertEqualsWithDelta(300, $row['eta_seconds'], 3); // 400 - 100 elapsed
        $this->assertEqualsWithDelta(100, $row['elapsed_seconds'], 3);
        $this->assertSame(2, $row['heartbeat_age_seconds']);
        $this->assertNull($this->getJson($this->url('/runs/'.$noHistory->id))->json('run.eta_seconds'));
    }

    // ---- start / batch ------------------------------------------------------

    public function test_start_run_queues_a_forced_job_and_returns_202(): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->url('/runs'), ['symbol' => 'btc/usdt', 'horizon_minutes' => 240])->assertStatus(202);

        $run = $response->json('run');
        $this->assertSame('BTCUSDT', $run['symbol']);
        $this->assertSame('QUEUED', $run['status']);
        $this->assertSame('queued', $run['phase']);
        $this->assertSame('ui', $run['source']);
        $this->assertSame($this->owner->id, $run['triggered_by']);
        $this->assertSame(1, $run['attempt']);
        $this->assertSame('regression', $run['model_type']);
        $this->assertTrue($run['can_stop']);
        $this->assertFalse($run['can_retry']);
        Queue::assertPushed(TrainMlShadowModelJob::class, fn (TrainMlShadowModelJob $job) => $job->runId === $run['id'] && $job->force === true
            && $job->symbol === 'BTCUSDT' && $job->horizonMinutes === 240 && $job->connection === 'ml_training' && $job->queue === 'ml_training');
    }

    public function test_start_run_error_codes(): void
    {
        $this->actingAs($this->owner);
        $this->postJson($this->url('/runs'), ['symbol' => 'DOGEUSDT', 'horizon_minutes' => 60])->assertStatus(422)->assertJson(['code' => 'unknown_symbol'])->assertJsonStructure(['message', 'code']);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 7])->assertStatus(422)->assertJson(['code' => 'unknown_horizon']);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT'])->assertStatus(422);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'type' => 'nope'])->assertStatus(422);

        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->assertStatus(202);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->assertStatus(422)->assertJson(['code' => 'already_active']);
        $this->assertSame(1, MlTrainingRun::query()->count());

        config(['ml.enabled' => false]);
        $this->postJson($this->url('/runs'), ['symbol' => 'ETHUSDT', 'horizon_minutes' => 60])->assertStatus(422)->assertJson(['code' => 'ml_disabled']);
    }

    public function test_a_queued_unique_job_for_the_pair_blocks_a_second_run_even_without_a_run_row(): void
    {
        $this->actingAs($this->owner);
        // A scheduler-queued job (no run yet) holds the pair's unique lock.
        $job = new TrainMlShadowModelJob('BTCUSDT', 60);
        $this->assertTrue((new \Illuminate\Bus\UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class)))->acquire($job));

        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->assertStatus(422)->assertJson(['code' => 'already_active']);

        $this->assertSame(0, MlTrainingRun::query()->count());
    }

    public function test_batch_presets_expand_to_the_documented_horizons(): void
    {
        $this->actingAs($this->owner);

        $long = $this->postJson($this->url('/batches'), ['preset' => 'long'])->assertStatus(202)->json();
        $this->assertCount(4, $long['runs']); // BTC+ETH x 240,1440
        $this->assertSame([], $long['skipped']);
        $this->assertSame([240, 240, 1440, 1440], collect($long['runs'])->pluck('horizon_minutes')->sort()->values()->all());
        $this->assertCount(1, collect($long['runs'])->pluck('batch_id')->unique());
        $this->assertSame($long['batch_id'], $long['runs'][0]['batch_id']);

        $mid = $this->postJson($this->url('/batches'), ['preset' => 'mid', 'symbols' => ['BTCUSDT']])->assertStatus(202)->json();
        $this->assertSame([60, 120], collect($mid['runs'])->pluck('horizon_minutes')->sort()->values()->all());

        $short = $this->postJson($this->url('/batches'), ['preset' => 'short', 'symbols' => ['ETHUSDT']])->assertStatus(202)->json();
        $this->assertSame([15], collect($short['runs'])->pluck('horizon_minutes')->all());
        Queue::assertPushed(TrainMlShadowModelJob::class, 7);
    }

    public function test_batch_skips_pairs_that_would_duplicate_an_active_run(): void
    {
        $this->actingAs($this->owner);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 240])->assertStatus(202);

        $json = $this->postJson($this->url('/batches'), ['pairs' => [
            ['symbol' => 'BTCUSDT', 'horizon_minutes' => 240], ['symbol' => 'BTCUSDT', 'horizon_minutes' => 240], ['symbol' => 'ETHUSDT', 'horizon_minutes' => 240],
        ]])->assertStatus(202)->json();

        $this->assertCount(1, $json['runs']);
        $this->assertSame('ETHUSDT', $json['runs'][0]['symbol']);
        $this->assertSame([['symbol' => 'BTCUSDT', 'horizon_minutes' => 240, 'reason' => 'already_active']], $json['skipped']);
    }

    public function test_batch_with_an_unknown_pair_is_rejected_whole(): void
    {
        $this->actingAs($this->owner);

        $this->postJson($this->url('/batches'), ['pairs' => [['symbol' => 'BTCUSDT', 'horizon_minutes' => 60], ['symbol' => 'NOPEUSDT', 'horizon_minutes' => 60]]])
            ->assertStatus(422)->assertJson(['code' => 'unknown_symbol']);
        $this->postJson($this->url('/batches'), ['pairs' => [['symbol' => 'BTCUSDT', 'horizon_minutes' => 61]]])->assertStatus(422)->assertJson(['code' => 'unknown_horizon']);
        $this->postJson($this->url('/batches'), [])->assertStatus(422);
        $this->postJson($this->url('/batches'), ['preset' => 'huge'])->assertStatus(422);
        $this->assertSame(0, MlTrainingRun::query()->count());
    }

    // ---- stop / retry -------------------------------------------------------

    public function test_stopping_a_queued_run_cancels_it_at_once_and_frees_the_pair(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->json('run.id');

        $run = $this->postJson($this->url('/runs/'.$id.'/stop'))->assertStatus(202)->json('run');

        $this->assertSame('STOPPED', $run['status']);
        $this->assertSame('CANCELLED', $run['error_code']);
        $this->assertNotNull($run['finished_at']);
        $this->assertFalse($run['can_stop']);
        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->assertStatus(202); // pair is free again
    }

    public function test_stopping_a_running_run_sets_the_cooperative_flag_only(): void
    {
        $run = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(60), 'heartbeat_at' => $this->utcAgo(2), 'worker_pid' => 999999]);
        $this->actingAs($this->owner);

        $json = $this->postJson($this->url('/runs/'.$run->id.'/stop'))->assertStatus(202)->json('run');

        $this->assertSame('RUNNING', $json['status']); // ends within seconds, by the owning process
        $this->assertFalse($json['can_stop']);
        $this->assertNotNull($run->refresh()->cancel_requested_at);
        $this->postJson($this->url('/runs/'.$run->id.'/stop'))->assertStatus(422)->assertJson(['code' => 'not_stoppable']);
    }

    public function test_finished_runs_are_not_stoppable(): void
    {
        $run = $this->makeRun(['status' => MlTrainingRun::FINISHED, 'outcome' => 'CHAMPION']);

        $this->actingAs($this->owner)->postJson($this->url('/runs/'.$run->id.'/stop'))->assertStatus(422)->assertJson(['code' => 'not_stoppable']);
    }

    public function test_retry_creates_the_next_attempt_in_a_chain(): void
    {
        $failed = $this->makeRun(['status' => MlTrainingRun::FAILED, 'error_code' => 'TIMEOUT', 'finished_at' => $this->utcAgo(60), 'started_at' => $this->utcAgo(900), 'batch_id' => '22222222-2222-4222-8222-222222222222']);
        $this->actingAs($this->owner);
        $this->assertTrue($this->getJson($this->url('/runs/'.$failed->id))->json('run.can_retry'));

        $retry = $this->postJson($this->url('/runs/'.$failed->id.'/retry'))->assertStatus(202)->json('run');

        $this->assertSame(2, $retry['attempt']);
        $this->assertSame($failed->id, $retry['retry_of']);
        $this->assertSame('QUEUED', $retry['status']);
        $this->assertSame('ui', $retry['source']);
        $this->assertSame($failed->batch_id, $retry['batch_id']);
        $this->assertGreaterThanOrEqual(2, $retry['max_attempts']);
        Queue::assertPushed(TrainMlShadowModelJob::class, fn (TrainMlShadowModelJob $job) => $job->runId === $retry['id'] && $job->force);

        // Already retried, and the pair now has an active run: not retryable again.
        $this->postJson($this->url('/runs/'.$failed->id.'/retry'))->assertStatus(422)->assertJson(['code' => 'not_retryable']);
        $this->assertFalse($this->getJson($this->url('/runs/'.$failed->id))->json('run.can_retry'));

        // Chain of three: fail the retry too, retry again.
        $this->postJson($this->url('/runs/'.$retry['id'].'/stop'))->assertStatus(202);
        $third = $this->postJson($this->url('/runs/'.$retry['id'].'/retry'))->assertStatus(202)->json('run');
        $this->assertSame(3, $third['attempt']);
        $this->assertSame($retry['id'], $third['retry_of']);
        $chain = $this->getJson($this->url('/runs/'.$third['id']))->json('attempts');
        $this->assertSame([1, 2, 3], array_column($chain, 'attempt'));
    }

    public function test_only_failed_stopped_or_interrupted_runs_can_be_retried(): void
    {
        $this->actingAs($this->owner);
        foreach ([MlTrainingRun::QUEUED, MlTrainingRun::RUNNING, MlTrainingRun::FINISHED] as $i => $status) {
            $run = $this->makeRun(['status' => $status, 'symbol' => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT'][$i]]);
            $this->postJson($this->url('/runs/'.$run->id.'/retry'))->assertStatus(422)->assertJson(['code' => 'not_retryable']);
        }
        $interrupted = $this->makeRun(['horizon_minutes' => 120, 'status' => MlTrainingRun::INTERRUPTED, 'finished_at' => $this->utcAgo(10)]);
        $this->postJson($this->url('/runs/'.$interrupted->id.'/retry'))->assertStatus(202);
    }

    public function test_stopping_a_batch_cancels_queued_runs_and_flags_running_ones(): void
    {
        $batch = (string) Str::uuid();
        $queued = $this->makeRun(['batch_id' => $batch, 'symbol' => 'BTCUSDT']);
        $running = $this->makeRun(['batch_id' => $batch, 'symbol' => 'ETHUSDT', 'status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(30), 'heartbeat_at' => $this->utcAgo(1)]);
        $other = $this->makeRun(['symbol' => 'BNBUSDT']);
        $this->actingAs($this->owner);

        $this->postJson($this->url('/batches/'.$batch.'/stop'))->assertStatus(202)->assertExactJson(['stopped' => 1, 'cancelled' => 1]);

        $this->assertSame(MlTrainingRun::STOPPED, $queued->refresh()->status);
        $this->assertSame(MlTrainingRun::RUNNING, $running->refresh()->status);
        $this->assertNotNull($running->cancel_requested_at);
        $this->assertSame(MlTrainingRun::QUEUED, $other->refresh()->status);
        $this->postJson($this->url('/batches/'.$batch.'/stop'))->assertStatus(202)->assertExactJson(['stopped' => 0, 'cancelled' => 0]);
    }

    // ---- backfill -----------------------------------------------------------

    public function test_resume_backfill_queues_one_job_per_symbol(): void
    {
        $this->actingAs($this->owner);

        $job = $this->postJson($this->url('/backfill/btcusdt/resume'))->assertStatus(202)->assertJsonStructure(['job' => ['symbol', 'status', 'queue']])->json('job');

        $this->assertSame('BTCUSDT', $job['symbol']);
        $this->assertSame('queued', $job['status']);
        Queue::assertPushed(ResumeMlBackfillJob::class, fn (ResumeMlBackfillJob $j) => $j->symbol === 'BTCUSDT' && $j->connection === 'ml_training');
        $this->postJson($this->url('/backfill/BTCUSDT/resume'))->assertStatus(422)->assertJson(['code' => 'already_active']);
        $this->postJson($this->url('/backfill/ETHUSDT/resume'))->assertStatus(202);
        $this->postJson($this->url('/backfill/DOGEUSDT/resume'))->assertStatus(422)->assertJson(['code' => 'unknown_symbol']);
        Queue::assertPushed(ResumeMlBackfillJob::class, 2);
    }

    public function test_resume_backfill_refuses_while_a_backfill_is_visibly_running(): void
    {
        MlBackfillCheckpoint::query()->create(['provider' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'range_from_ms' => 1, 'range_to_ms' => 2, 'cursor_ms' => 1, 'status' => 'RUNNING']);

        $this->actingAs($this->owner)->postJson($this->url('/backfill/BTCUSDT/resume'))->assertStatus(422)->assertJson(['code' => 'already_active']);
        Queue::assertNothingPushed();
    }

    public function test_the_backfill_job_runs_the_existing_command_without_rebuilding_features(): void
    {
        Artisan::shouldReceive('call')->once()->with('ml:candles:backfill', ['--symbol' => ['BTCUSDT'], '--no-build' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('BTCUSDT: COMPLETED');

        (new ResumeMlBackfillJob('BTCUSDT'))->handle();
        $this->assertSame('ml:backfill:resume:BTCUSDT', (new ResumeMlBackfillJob('BTCUSDT'))->uniqueId());
    }

    // ---- candles / models / system -----------------------------------------

    public function test_candles_endpoint_returns_oldest_first_with_age_and_state(): void
    {
        $this->seedCandles('BTCUSDT', $this->alignedOpen(30), 30);
        $this->actingAs($this->owner);

        $json = $this->getJson($this->url('/candles/btcusdt?limit=10'))->assertOk()
            ->assertJsonStructure(['symbol', 'timeframe', 'generated_at', 'latest_close', 'age_seconds', 'state', 'candles' => [['t', 'o', 'h', 'l', 'c', 'v']]])->json();

        $this->assertSame('BTCUSDT', $json['symbol']);
        $this->assertSame('5m', $json['timeframe']);
        $this->assertSame('live', $json['state']);
        $this->assertCount(10, $json['candles']);
        $times = array_column($json['candles'], 't');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame(300_000, $times[1] - $times[0]);
        $this->assertIsFloat($json['candles'][0]['c']);
        $this->assertSame(gmdate('c', intdiv($times[9] + 300_000, 1000)), $json['latest_close']);

        $this->getJson($this->url('/candles/BTCUSDT?limit=501'))->assertStatus(422);
        $this->getJson($this->url('/candles/DOGEUSDT'))->assertStatus(422)->assertJson(['code' => 'unknown_symbol']);
        $this->assertSame('missing', $this->getJson($this->url('/candles/ETHUSDT'))->json('state'));
    }

    public function test_models_endpoint_lists_the_registry(): void
    {
        MlModel::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'timeframe' => '5m', 'feature_schema_version' => 'v', 'model_type' => 'side_classifier',
            'status' => 'REJECTED', 'model_version' => 'qtml-1', 'decision_reason' => 'too_few_trades', 'trained_at' => now('UTC'), 'total_samples' => 100, 'train_samples' => 80, 'holdout_samples' => 20,
            'metrics' => ['holdout' => ['non_overlapping' => ['trades' => 9, 'total_net_pct' => 1.5, 'average_net_pct' => 0.16, 't_stat' => 1.1, 'net_win_rate_pct' => 55]]],
            'comparison' => ['x' => 1]]);
        MlModel::query()->create(['symbol' => 'ETHUSDT', 'horizon_minutes' => 120, 'timeframe' => '5m', 'feature_schema_version' => 'v', 'model_type' => 'regression', 'status' => 'FAILED']);
        $this->actingAs($this->owner);

        $json = $this->getJson($this->url('/models?symbol=BTCUSDT'))->assertOk()->assertJsonStructure(['data' => [['model_version', 'symbol', 'horizon_minutes', 'model_type', 'status',
            'decision_reason', 'trained_at', 'promoted_at', 'samples' => ['total', 'train', 'holdout'], 'holdout_summary', 'comparison']]])->json();

        $this->assertCount(1, $json['data']);
        $this->assertSame(['total' => 100, 'train' => 80, 'holdout' => 20], $json['data'][0]['samples']);
        $this->assertSame(9, $json['data'][0]['holdout_summary']['trades']);
        $this->assertSame(['x' => 1], $json['data'][0]['comparison']);
        $this->assertCount(2, $this->getJson($this->url('/models'))->json('data'));
        $this->assertCount(1, $this->getJson($this->url('/models?horizon_minutes=120'))->json('data'));
        $this->assertStringContainsString('"holdout_summary":{}', $this->getJson($this->url('/models?horizon_minutes=120'))->getContent()); // an object, never []
    }

    public function test_system_endpoint_has_the_contracted_shape_and_queue_depth(): void
    {
        DB::table('jobs')->insert([
            ['queue' => 'ml_training', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'ml_training', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
        ]);
        $payload = json_encode(['displayName' => 'App\\Jobs\\TrainMlShadowModelJob', 'data' => ['command' => 'O:31:"App\\Jobs\\TrainMlShadowModelJob":2:{s:6:"symbol";s:7:"BTCUSDT";s:14:"horizonMinutes";i:240;}']]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'ml_training', 'queue' => 'ml_training', 'payload' => $payload,
            'exception' => "RuntimeException: ML shadow training failed: api_key=SECRETVALUE boom\n#0 trace", 'failed_at' => now()->format('Y-m-d H:i:s')]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'other', 'failed_at' => now()->format('Y-m-d H:i:s')]);

        $response = $this->actingAs($this->owner)->getJson($this->url('/system'))->assertOk()->assertJsonStructure([
            'generated_at', 'host' => ['cores', 'load', 'memory', 'swap', 'disk'], 'health' => ['level', 'reasons'],
            'queue' => ['connection', 'name', 'pending', 'reserved', 'failed_recent'],
            'training' => ['timeout_seconds', 'stall_seconds', 'auto_retry', 'max_attempts'],
            'thresholds' => ['load_per_core_warn', 'load_per_core_critical', 'swap_used_pct_warn', 'swap_used_pct_critical', 'disk_free_gb_warn'],
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $json = $response->json();
        $this->assertSame('ml_training', $json['queue']['connection']);
        $this->assertSame('ml_training', $json['queue']['name']);
        $this->assertSame(1, $json['queue']['pending']);
        $this->assertSame(1, $json['queue']['reserved']);
        $this->assertCount(1, $json['queue']['failed_recent']);
        $failed = $json['queue']['failed_recent'][0];
        $this->assertSame('BTCUSDT', $failed['symbol']);
        $this->assertSame(240, $failed['horizon_minutes']);
        $this->assertStringNotContainsString('SECRETVALUE', $failed['summary']);
        $this->assertStringContainsString('TrainMlShadowModelJob', $failed['summary']);
        $this->assertSame(['timeout_seconds' => 1800, 'stall_seconds' => 180, 'export_stall_seconds' => 1800, 'auto_retry' => true, 'max_attempts' => 3], $json['training']);
        $this->assertContains($json['health']['level'], ['ok', 'warning', 'critical']);
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertNull($json['host']['load']);
            $this->assertNull($json['host']['memory']);
            $this->assertSame('ok', $json['health']['level']);
            $this->assertNotEmpty($json['health']['reasons']);
        }
    }

    public function test_system_stats_read_proc_and_grade_health(): void
    {
        $proc = storage_path('framework/testing/fake-proc');
        File::deleteDirectory($proc);
        File::ensureDirectoryExists($proc);
        file_put_contents($proc.'/loadavg', "19.20 18.50 17.10 3/900 12345\n");
        file_put_contents($proc.'/cpuinfo', str_repeat("processor\t: 0\nmodel name\t: x\n\n", 1)."processor\t: 1\n\nprocessor\t: 2\n\nprocessor\t: 3\n");
        file_put_contents($proc.'/meminfo', "MemTotal:        7864320 kB\nMemFree:          100000 kB\nMemAvailable:     393216 kB\nSwapTotal:       2097152 kB\nSwapFree:         204800 kB\n");

        $stats = new TrainingSystemStats($proc, storage_path());
        $host = $stats->host();

        $this->assertSame(4, $host['cores']);
        $this->assertSame(['m1' => 19.2, 'm5' => 18.5, 'm15' => 17.1], $host['load']);
        $this->assertSame(['total_mb' => 7680, 'available_mb' => 384, 'used_pct' => 95.0], $host['memory']);
        $this->assertSame(['total_mb' => 2048, 'used_mb' => 1848, 'used_pct' => 90.2], $host['swap']);
        $health = $stats->health($host);
        $this->assertSame('critical', $health['level']);
        $this->assertGreaterThanOrEqual(3, count($health['reasons']));

        $healthy = $stats->health(['cores' => 4, 'load' => ['m1' => 0.5, 'm5' => 0.5, 'm15' => 0.5], 'memory' => ['total_mb' => 8000, 'available_mb' => 6000, 'used_pct' => 25.0],
            'swap' => ['total_mb' => 0, 'used_mb' => 0, 'used_pct' => 0.0], 'disk' => ['path' => '/', 'free_gb' => 50.0, 'used_pct' => 40.0]]);
        $this->assertSame(['level' => 'ok', 'reasons' => []], $healthy);

        $missing = new TrainingSystemStats($proc.'/nope', storage_path());
        $hostMissing = $missing->host();
        $this->assertNull($hostMissing['load']);
        $this->assertNull($hostMissing['memory']);
        $this->assertSame('ok', $missing->health($hostMissing)['level']);
        File::deleteDirectory($proc);
    }

    public function test_dashboard_polling_never_throttles_the_mutation_buttons(): void
    {
        $this->actingAs($this->owner);
        for ($i = 0; $i < 14; $i++) {
            $this->getJson($this->url('/overview'))->assertOk(); // more than the 10/min mutation budget
        }

        $this->postJson($this->url('/runs'), ['symbol' => 'BTCUSDT', 'horizon_minutes' => 60])->assertStatus(202);
        $this->getJson($this->url('/overview'))->assertOk()->assertHeader('X-RateLimit-Limit', 120);
    }

    // ---- timezone -----------------------------------------------------------

    public function test_api_instants_and_heartbeat_age_are_correct_under_asia_colombo(): void
    {
        config(['app.timezone' => 'Asia/Colombo']);
        date_default_timezone_set('Asia/Colombo');
        $run = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(120), 'heartbeat_at' => $this->utcAgo(45)]);

        $row = $this->actingAs($this->owner)->getJson($this->url('/runs/'.$run->id))->assertOk()->json('run');

        $this->assertEqualsWithDelta(45, $row['heartbeat_age_seconds'], 3);   // not 45 + 19800
        $this->assertEqualsWithDelta(120, $row['elapsed_seconds'], 3);
        $this->assertStringEndsWith('+00:00', $row['heartbeat_at']);
        $this->assertEqualsWithDelta(time() - 45, strtotime($row['heartbeat_at']), 3);
        $this->assertEqualsWithDelta(time() - 120, strtotime($row['started_at']), 3);
        $this->assertNotNull($row['queued_at']);

        $created = $this->postJson($this->url('/runs'), ['symbol' => 'ETHUSDT', 'horizon_minutes' => 60])->assertStatus(202)->json('run');
        $this->assertEqualsWithDelta(time(), strtotime($created['queued_at']), 5);
        $this->assertEqualsWithDelta(time(), strtotime(DB::table('ml_training_runs')->where('id', $created['id'])->value('queued_at').' UTC'), 5);
        $this->assertSame(0, (int) ($created['heartbeat_age_seconds'] ?? 0));

        $overview = $this->getJson($this->url('/overview'))->json();
        $this->assertStringEndsWith('+00:00', $overview['generated_at']);
        $this->assertEqualsWithDelta(time(), strtotime($overview['generated_at']), 5);
        $this->assertEqualsWithDelta(45, collect($overview['active_runs'])->firstWhere('id', $run->id)['heartbeat_age_seconds'], 3);
    }
}
