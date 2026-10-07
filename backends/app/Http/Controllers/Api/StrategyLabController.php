<?php

namespace App\Http\Controllers\Api;

use App\Domain\StrategyLab\StrategyLabException;
use App\Domain\StrategyLab\StrategyLabService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\StrategyLabApproval;
use App\Models\StrategyLabCandidate;
use App\Models\StrategyLabRun;
use App\Models\StrategyLabVersion;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Strategy Lab API (SPEC §105). Research only: candidates are stored and audited as text, tested on
 * development data by the reviewed Python adapter, and moved between research stages ONLY by an owner/admin
 * decision. Nothing here can place, route or influence an order. Reads need workspace membership; every
 * mutation needs owner/admin. Logic lives in App\Domain\StrategyLab.
 */
final class StrategyLabController extends Controller
{
    use AssertsWorkspaceMembership;

    public function __construct(private readonly StrategyLabService $service) {}

    public function candidates(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $rows = StrategyLabCandidate::query()->where('workspace_id', $workspace)->orderByDesc('id')->limit(200)->get();
        $latestRuns = StrategyLabRun::query()->whereIn('candidate_id', $rows->pluck('id'))->orderByDesc('id')->get()->groupBy('candidate_id');
        $versions = StrategyLabVersion::query()->whereIn('candidate_id', $rows->pluck('id'))->orderByDesc('version')->get()->groupBy('candidate_id');

        return $this->read(['candidates' => $rows->map(function (StrategyLabCandidate $c) use ($latestRuns, $versions) {
            $runs = $latestRuns->get($c->id, collect());
            $v = $versions->get($c->id, collect())->first();

            return $this->candidateSummary($c) + [
                'latest_version' => $v?->version, 'audit_status' => $v?->audit_status, 'testable' => $v?->adapter !== null,
                'quick_test' => $this->runBrief($runs->firstWhere('kind', StrategyLabRun::QUICK)),
                'deep_test' => $this->runBrief($runs->firstWhere('kind', StrategyLabRun::DEEP)),
            ];
        })->values()]);
    }

    public function createCandidate(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000',
            'source_type' => ['required', Rule::in(StrategyLabCandidate::SOURCE_TYPES)],
            'intended_purpose' => ['required', Rule::in(StrategyLabCandidate::PURPOSES)],
            'original_timeframe' => 'nullable|string|max:16', 'notes' => 'nullable|string|max:2000',
            'source' => 'required|string|max:'.(int) config('strategy_lab.max_source_bytes', 120000),
        ]);

        return $this->guard(fn () => response()->json(
            ['candidate' => $this->candidateDetail($this->service->createCandidate($workspace, (string) $request->user()->id, $data))], 201));
    }

    public function candidate(Request $request, string $workspace, string $candidate): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->read(['candidate' => $this->candidateDetail($this->findCandidate($workspace, $candidate))]);
    }

    public function addVersion(Request $request, string $workspace, string $candidate): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate(['source' => 'required|string|max:'.(int) config('strategy_lab.max_source_bytes', 120000), 'notes' => 'nullable|string|max:2000']);
        $model = $this->findCandidate($workspace, $candidate);

        return $this->guard(fn () => response()->json(
            ['version' => $this->versionView($this->service->addVersion($model, (string) $request->user()->id, $data['source'], $data['notes'] ?? null), true)], 201));
    }

    public function archive(Request $request, string $workspace, string $candidate): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $model = $this->findCandidate($workspace, $candidate);

        return $this->guard(fn () => response()->json(['candidate' => $this->candidateSummary($this->service->archive($model))]));
    }

    public function quickTest(Request $request, string $workspace, string $version): JsonResponse
    {
        return $this->startRun($request, $workspace, $version, StrategyLabRun::QUICK);
    }

    public function deepTest(Request $request, string $workspace, string $version): JsonResponse
    {
        return $this->startRun($request, $workspace, $version, StrategyLabRun::DEEP);
    }

    public function run(Request $request, string $workspace, string $run): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->read(['run' => $this->runView($this->findRun($workspace, $run), true)]);
    }

    public function chart(Request $request, string $workspace, string $run): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $model = $this->findRun($workspace, $run);
        $path = $this->service->artifactDir($model).DIRECTORY_SEPARATOR.'chart.json';
        if ($model->status !== StrategyLabRun::COMPLETED || ! is_file($path)) {
            return response()->json(['message' => 'No chart for this run.', 'code' => 'NO_CHART'], 404);
        }

        return $this->read(['chart' => json_decode((string) file_get_contents($path), true)]);
    }

    public function cancel(Request $request, string $workspace, string $run): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);

        $model = $this->findRun($workspace, $run);

        return $this->guard(fn () => response()->json(['run' => $this->runView($this->service->cancel($model))]));
    }

    public function decide(Request $request, string $workspace, string $run): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate(['decision' => ['required', Rule::in(['APPROVE', 'REJECT'])], 'notes' => 'nullable|string|max:2000']);
        $model = $this->findRun($workspace, $run);

        return $this->guard(fn () => response()->json(['approval' => $this->service->decide($model, (string) $request->user()->id, $data['decision'], $data['notes'] ?? null)], 201));
    }

    private function startRun(Request $request, string $workspace, string $version, string $kind): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $request->validate(['idempotency_key' => 'nullable|string|max:80']);
        $model = StrategyLabVersion::query()->findOrFail($version);
        $this->findCandidate($workspace, $model->candidate_id);
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        return $this->guard(fn () => response()->json(
            ['run' => $this->runView($this->service->startRun($model, $kind, (string) $request->user()->id, $key ? (string) $key : null))], 202));
    }

    private function findCandidate(string $workspace, string $id): StrategyLabCandidate
    {
        return StrategyLabCandidate::query()->where('workspace_id', $workspace)->whereKey($id)->firstOrFail();
    }

    private function findRun(string $workspace, string $id): StrategyLabRun
    {
        return StrategyLabRun::query()->where('workspace_id', $workspace)->whereKey($id)->firstOrFail();
    }

    private function candidateSummary(StrategyLabCandidate $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'source_type' => $c->source_type,
            'intended_purpose' => $c->intended_purpose, 'original_timeframe' => $c->original_timeframe, 'status' => $c->status,
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String()];
    }

    private function candidateDetail(StrategyLabCandidate $c): array
    {
        return $this->candidateSummary($c) + [
            'versions' => StrategyLabVersion::query()->where('candidate_id', $c->id)->orderByDesc('version')->get()
                ->map(fn ($v) => $this->versionView($v, true))->values(),
            'runs' => StrategyLabRun::query()->where('candidate_id', $c->id)->orderByDesc('id')->get()->map(fn ($r) => $this->runView($r))->values(),
            'approvals' => StrategyLabApproval::query()->where('candidate_id', $c->id)->orderByDesc('id')->get()->values(),
        ];
    }

    private function versionView(StrategyLabVersion $v, bool $withSource = false): array
    {
        $adapters = (array) config('strategy_lab.adapters', []);

        return ['id' => $v->id, 'version' => $v->version, 'parent_version_id' => $v->parent_version_id, 'sha256' => $v->sha256,
            'adapter' => $v->adapter, 'adapter_label' => $v->adapter ? ($adapters[$v->adapter]['label'] ?? $v->adapter) : null,
            'testable' => $v->adapter !== null, 'audit_status' => $v->audit_status, 'audit' => $v->audit, 'notes' => $v->notes,
            'created_at' => $v->created_at?->toIso8601String()] + ($withSource ? ['source' => $v->source] : []);
    }

    private function runBrief(?StrategyLabRun $r): ?array
    {
        return $r ? ['id' => $r->id, 'status' => $r->status, 'verdict' => $r->verdict, 'progress_pct' => $r->progress_pct] : null;
    }

    private function runView(StrategyLabRun $r, bool $withSummary = false): array
    {
        $view = ['id' => $r->id, 'candidate_id' => $r->candidate_id, 'version_id' => $r->version_id, 'kind' => $r->kind, 'status' => $r->status,
            'phase' => $r->phase, 'progress_pct' => $r->progress_pct, 'adapter' => $r->adapter, 'sample_policy' => $r->sample_policy,
            'verdict' => $r->verdict, 'error_code' => $r->error_code, 'error_message' => $r->error_message,
            'queued_at' => $r->queued_at?->toIso8601String(), 'started_at' => $r->started_at?->toIso8601String(),
            'heartbeat_at' => $r->heartbeat_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
            'cancel_requested' => $r->cancel_requested_at !== null, 'result_sha256' => $r->result_sha256,
            'decision' => StrategyLabApproval::query()->where('run_id', $r->id)->first(['decision', 'scope', 'notes', 'created_at'])];
        if ($withSummary) {
            $view['summary'] = $r->summary;
            $view['log_tail'] = $r->log_tail;
        }

        return $view;
    }

    private function read(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    private function guard(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (StrategyLabException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        }
    }
}
