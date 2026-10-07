<?php

namespace Tests\Feature\StrategyLab;

use App\Domain\StrategyLab\PineAuditor;
use App\Domain\StrategyLab\StrategyLabRunner;
use App\Domain\StrategyLab\StrategyLabService;
use App\Jobs\RunStrategyLabTestJob;
use App\Models\StrategyLabCandidate;
use App\Models\StrategyLabRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Strategy Lab (SPEC §105): auth, audit gates, lifecycle, idempotency, runner, and no order path. */
final class StrategyLabApiTest extends TestCase
{
    private Workspace $workspace;

    private User $owner;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->tmp = storage_path('framework/testing/strategy-lab-'.Str::random(6));
        File::ensureDirectoryExists($this->tmp.'/work');
        config(['strategy_lab.work_dir' => null, 'strategy_lab.artifacts_dir' => $this->tmp.'/artifacts', 'strategy_lab.poll_interval_ms' => 50]);
        [$this->workspace, $this->owner] = $this->workspaceWithRole('owner');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function workspaceWithRole(string $role): array
    {
        $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
        $user = User::factory()->create();
        WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return [$workspace, $user];
    }

    private function url(string $path): string
    {
        return '/api/v1/workspaces/'.$this->workspace->id.'/strategy-lab'.$path;
    }

    private function v13(): string
    {
        return (string) file_get_contents(base_path('docs/ml-engine-v1/strategy-lab/candidates/autofinx-v13/source.pine'));
    }

    private function create(string $source, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson($this->url('/candidates'), [
            'name' => 'Candidate', 'source_type' => 'PINE', 'intended_purpose' => 'FULL_STRATEGY', 'source' => $source,
        ]);
    }

    public function test_owner_creates_candidate_audited_and_matched_to_translation(): void
    {
        $res = $this->create($this->v13())->assertCreated();
        $version = $res->json('candidate.versions.0');
        $this->assertSame('AUDITED', $res->json('candidate.status'));
        $this->assertSame('autofinx-v13', $version['adapter']);
        $this->assertSame('CAUSAL', $version['audit_status']);
        $this->assertFalse($version['audit']['executed']);
        $this->assertSame('HIGH', $version['audit']['overfit_risk']);
        // CRLF / trailing-space copies still match the reviewed translation
        $this->assertSame('autofinx-v13', $this->create(str_replace("\n", "  \r\n", $this->v13()))->json('candidate.versions.0.adapter'));
    }

    public function test_reads_need_membership_and_mutations_need_owner_or_admin(): void
    {
        [, $stranger] = $this->workspaceWithRole('owner');
        $this->actingAs($stranger)->getJson($this->url('/candidates'))->assertForbidden();
        $viewer = User::factory()->create();
        WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer)->getJson($this->url('/candidates'))->assertOk();
        $this->create($this->v13(), $viewer)->assertForbidden();
        auth()->forgetGuards();
        $this->getJson($this->url('/candidates'))->assertUnauthorized();
    }

    public function test_quick_test_gates(): void
    {
        $version = $this->create($this->v13())->json('candidate.versions.0.id');
        $this->actingAs($this->owner)->postJson($this->url("/versions/{$version}/quick-test"))->assertStatus(422)->assertJson(['code' => 'RUNNER_UNAVAILABLE']);

        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $plain = "//@version=5\nstrategy(\"x\")\nf = ta.ema(close, 9)\nif ta.crossover(close, f)\n    strategy.entry(\"L\", strategy.long)\n";
        $other = $this->create($plain)->json('candidate.versions.0.id');
        $this->postJson($this->url("/versions/{$other}/quick-test"))->assertStatus(422)->assertJson(['code' => 'NEEDS_TRANSLATION']);

        $repaint = "//@version=5\nindicator(\"r\")\nh = request.security(syminfo.tickerid, \"60\", close, lookahead=barmerge.lookahead_on)\nplot(h)\n";
        $bad = $this->create($repaint);
        $this->assertSame('REPAINT_RISK', $bad->json('candidate.versions.0.audit_status'));
        $this->postJson($this->url('/versions/'.$bad->json('candidate.versions.0.id').'/quick-test'))->assertStatus(422)->assertJson(['code' => 'AUDIT_BLOCKED']);

        $first = $this->postJson($this->url("/versions/{$version}/quick-test"))->assertStatus(202);
        $this->assertSame('QUEUED', $first->json('run.status'));
        $again = $this->postJson($this->url("/versions/{$version}/quick-test"))->assertStatus(202);
        $this->assertSame($first->json('run.id'), $again->json('run.id'));          // no duplicate execution
        Queue::assertPushed(RunStrategyLabTestJob::class, 1);
    }

    public function test_idempotency_key_returns_the_same_run(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $version = $this->create($this->v13())->json('candidate.versions.0.id');
        $a = $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'k-1')->postJson($this->url("/versions/{$version}/quick-test"));
        StrategyLabRun::query()->whereKey($a->json('run.id'))->update(['status' => StrategyLabRun::FAILED]);
        $b = $this->withHeader('Idempotency-Key', 'k-1')->postJson($this->url("/versions/{$version}/quick-test"));
        $this->assertSame($a->json('run.id'), $b->json('run.id'));
    }

    public function test_deep_test_needs_owner_approval_of_a_completed_quick_test(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $candidate = $this->create($this->v13())->json('candidate');
        $version = $candidate['versions'][0]['id'];
        $this->actingAs($this->owner)->postJson($this->url("/versions/{$version}/deep-test"))->assertStatus(422)->assertJson(['code' => 'APPROVAL_REQUIRED']);
        $run = $this->postJson($this->url("/versions/{$version}/quick-test"))->json('run.id');
        $this->postJson($this->url("/runs/{$run}/decision"), ['decision' => 'APPROVE'])->assertStatus(422)->assertJson(['code' => 'RUN_NOT_COMPLETED']);

        StrategyLabRun::query()->whereKey($run)->update(['status' => StrategyLabRun::COMPLETED, 'verdict' => 'NO_VALUE']);
        $this->postJson($this->url("/runs/{$run}/decision"), ['decision' => 'APPROVE', 'notes' => 'check components'])->assertCreated();
        $this->postJson($this->url("/runs/{$run}/decision"), ['decision' => 'REJECT'])->assertStatus(422)->assertJson(['code' => 'ALREADY_DECIDED']);
        $this->assertSame('OWNER_APPROVED_DEEP_TEST', StrategyLabCandidate::query()->find($candidate['id'])->status);
        $this->postJson($this->url("/versions/{$version}/deep-test"))->assertStatus(202)->assertJson(['run' => ['kind' => 'DEEP']]);
    }

    public function test_reject_closes_the_candidate(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $version = $this->create($this->v13())->json('candidate.versions.0.id');
        $run = $this->actingAs($this->owner)->postJson($this->url("/versions/{$version}/quick-test"))->json('run.id');
        StrategyLabRun::query()->whereKey($run)->update(['status' => StrategyLabRun::COMPLETED]);
        $this->postJson($this->url("/runs/{$run}/decision"), ['decision' => 'REJECT'])->assertCreated();
        $this->postJson($this->url("/versions/{$version}/quick-test"))->assertStatus(422)->assertJson(['code' => 'CANDIDATE_CLOSED']);
    }

    public function test_cancel_a_queued_run(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $version = $this->create($this->v13())->json('candidate.versions.0.id');
        $run = $this->actingAs($this->owner)->postJson($this->url("/versions/{$version}/quick-test"))->json('run.id');
        $this->postJson($this->url("/runs/{$run}/cancel"))->assertOk()->assertJson(['run' => ['status' => 'CANCELLED']]);
        (new RunStrategyLabTestJob($run))->handle(app(StrategyLabRunner::class));      // skipped: no longer queued
        $this->assertSame('CANCELLED', StrategyLabRun::query()->find($run)->status);
    }

    public function test_runner_completes_a_run_and_serves_the_chart(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $candidate = $this->create($this->v13())->json('candidate');
        $runId = $this->actingAs($this->owner)->postJson($this->url('/versions/'.$candidate['versions'][0]['id'].'/quick-test'))->json('run.id');
        $this->fakeRunner(0)->execute(StrategyLabRun::query()->find($runId));

        $run = $this->getJson($this->url("/runs/{$runId}"))->assertOk()->json('run');
        $this->assertSame('COMPLETED', $run['status']);
        $this->assertSame('NO_VALUE', $run['verdict']);
        $this->assertSame(100.0, (float) $run['progress_pct']);
        $this->assertStringContainsString('PROGRESS 50 simulating BTCUSDT 15m', $run['log_tail']);
        $this->assertSame('QUICK_TESTED', StrategyLabCandidate::query()->find($candidate['id'])->status);
        $this->getJson($this->url("/runs/{$runId}/chart"))->assertOk()->assertJson(['chart' => ['symbol' => 'BTCUSDT']]);
    }

    public function test_runner_maps_missing_data_exit_code(): void
    {
        config(['strategy_lab.work_dir' => $this->tmp.'/work']);
        $version = $this->create($this->v13())->json('candidate.versions.0.id');
        $runId = $this->actingAs($this->owner)->postJson($this->url("/versions/{$version}/quick-test"))->json('run.id');
        $this->fakeRunner(3)->execute(StrategyLabRun::query()->find($runId));
        $run = StrategyLabRun::query()->find($runId);
        $this->assertSame(['FAILED', 'DATA_UNAVAILABLE'], [$run->status, $run->error_code]);
    }

    public function test_auditor_classifications(): void
    {
        $a = new PineAuditor;
        $this->assertSame('INVALID', $a->audit("strategy(\"x\")\n")['classification']);
        $this->assertSame('CAUSAL_IF_LAGGED', $a->audit("//@version=5\nindicator(\"p\")\nph = ta.pivothigh(high, 5, 5)\n")['classification']);
        $this->assertSame('UNSUPPORTED', $a->audit("//@version=5\nindicator(\"l\")\nx = request.security_lower_tf(syminfo.tickerid, \"1\", close)\n")['classification']);
        $this->assertSame('CAUSAL', $a->audit("//@version=5\nindicator(\"s\")\nx = request.security(syminfo.tickerid, \"60\", close[1], lookahead=barmerge.lookahead_on)\n")['classification']);
        $this->assertSame('REPAINT_RISK', $a->audit("//@version=5\nindicator(\"f\")\nx = request.security(syminfo.tickerid, \"60\", close)\n")['classification']);
        // a commented-out construct does not count
        $this->assertSame('CAUSAL', $a->audit("//@version=5\nindicator(\"c\")\n// x = request.security(syminfo.tickerid, \"60\", close)\n")['classification']);
    }

    public function test_strategy_lab_code_has_no_order_path(): void
    {
        $files = array_merge(glob(app_path('Domain/StrategyLab/*.php')), [app_path('Http/Controllers/Api/StrategyLabController.php'), app_path('Jobs/RunStrategyLabTestJob.php')]);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['Binance', 'Order', 'FutureTrade', 'Exchange', 'QuickTrade', 'RatioBot', 'placeOrder'] as $forbidden) {
                $this->assertDoesNotMatchRegularExpression('/\b(use|new)\s+[\\\\\w]*'.$forbidden.'/', $code, basename($file)." references {$forbidden}");
            }
        }
    }

    private function fakeRunner(int $exitCode): StrategyLabRunner
    {
        $script = $this->tmp.'/fake_runner.php';
        file_put_contents($script, <<<'PHP'
<?php
$out = $argv[1]; $code = (int) $argv[2];
echo "PROGRESS 5 loading BTCUSDT\n"; echo "PROGRESS 50 simulating BTCUSDT 15m\n";
if ($code !== 0) { fwrite(STDERR, "DATA_UNAVAILABLE no data\n"); exit($code); }
@mkdir($out, 0777, true);
$body = json_encode(['verdict' => 'NO_VALUE', 'sample_policy' => 'QUICK_V1', 'combined' => ['15m' => ['0.12' => ['trades' => 40]]]]);
file_put_contents($out.'/result.json', $body); file_put_contents($out.'/result.sha256', hash('sha256', $body));
file_put_contents($out.'/chart.json', json_encode(['symbol' => 'BTCUSDT', 'timeframe' => '15m', 'candles' => [], 'trades' => []]));
echo "PROGRESS 100 done\n";
PHP);

        return new class(app(StrategyLabService::class), $script, $exitCode) extends StrategyLabRunner
        {
            public function __construct(StrategyLabService $service, private string $script, private int $code)
            {
                parent::__construct($service);
            }

            protected function command(StrategyLabRun $run, string $mode, string $out): array
            {
                return [PHP_BINARY, $this->script, $out, (string) $this->code];
            }

            protected function makeProcess(array $command, int $timeoutSeconds): Process
            {
                return new Process($command, base_path(), null, null, $timeoutSeconds);
            }
        };
    }
}
