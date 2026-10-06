<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Training\TrainingCatalogService;
use App\Domain\Ml\Training\TrainingOverviewService;
use App\Domain\Ml\Training\TrainingPipelineHeartbeats;
use App\Domain\Ml\Training\TrainingQueueStats;
use App\Domain\Ml\Training\TrainingRunException;
use App\Domain\Ml\Training\TrainingRunPresenter;
use App\Domain\Ml\Training\TrainingRunQuery;
use App\Domain\Ml\Training\TrainingRunService;
use App\Domain\Ml\Training\TrainingSystemStats;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\MlTrainingRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ML Training dashboard API (docs/ML_TRAINING_DASHBOARD_PLAN.md section 3).
 * Shadow-only control plane: it queues, stops and inspects training runs and
 * can never place an order. ML data is global; the workspace only gates
 * access. Reads need workspace membership; every mutation needs owner/admin.
 * Logic lives in App\Domain\Ml\Training; this class only authorizes,
 * validates and shapes responses.
 */
final class MlTrainingController extends Controller
{
    use AssertsWorkspaceMembership;

    public function overview(Request $request, string $workspace, TrainingOverviewService $overview): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->read($overview->overview());
    }

    public function system(Request $request, string $workspace, TrainingSystemStats $system, TrainingQueueStats $queue, TrainingPipelineHeartbeats $pipeline): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $host = $system->host();
        $summary = $queue->summary();

        return $this->read([
            'generated_at' => now('UTC')->toIso8601String(),
            'host' => $host,
            'health' => $system->health($host),
            'queue' => [
                'connection' => $queue->connection(), 'name' => $queue->queueName(),
                'pending' => $summary['pending'], 'reserved' => $summary['reserved'], 'failed_recent' => $queue->failedRecent(10),
            ],
            'training' => [
                'timeout_seconds' => (int) config('ml.training.timeout_seconds', 1800), 'stall_seconds' => (int) config('ml.training.stall_seconds', 180),
                'auto_retry' => (bool) config('ml.training.auto_retry', true), 'max_attempts' => (int) config('ml.training.max_attempts', 3),
            ],
            'thresholds' => $system->thresholds(),
            'pipeline_jobs' => $pipeline->all(),
        ]);
    }

    public function runs(Request $request, string $workspace, TrainingRunQuery $query): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate([
            'status' => 'nullable|string|max:80', 'symbol' => 'nullable|string|max:30', 'horizon_minutes' => 'nullable|integer|min:1',
            'batch_id' => 'nullable|string|max:64', 'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string|max:64',
        ]);

        return $this->read($query->list($data, (int) ($data['limit'] ?? 50), $data['cursor'] ?? null));
    }

    public function run(Request $request, string $workspace, string $run, TrainingRunQuery $query): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->read($query->detail($this->findRun($run)));
    }

    public function startRun(Request $request, string $workspace, TrainingRunService $service, TrainingRunPresenter $presenter): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate([
            'symbol' => 'required|string|max:30', 'horizon_minutes' => 'required|integer|min:1', 'type' => ['nullable', Rule::in(MlShadowTrainer::TYPES)],
        ]);

        return $this->guard(fn () => response()->json(['run' => $presenter->summary(
            $service->start((string) $data['symbol'], (int) $data['horizon_minutes'], $data['type'] ?? null, MlTrainingRun::SOURCE_UI, (string) $request->user()->id),
        )], 202));
    }

    public function startBatch(Request $request, string $workspace, TrainingRunService $service, TrainingRunPresenter $presenter): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate([
            'pairs' => 'required_without:preset|array|min:1|max:60', 'pairs.*.symbol' => 'required|string|max:30', 'pairs.*.horizon_minutes' => 'required|integer|min:1',
            'preset' => ['required_without:pairs', 'nullable', Rule::in(array_keys(TrainingRunService::PRESETS))],
            'symbols' => 'nullable|array|max:60', 'symbols.*' => 'string|max:30',
            'type' => ['nullable', Rule::in(MlShadowTrainer::TYPES)],
        ]);

        return $this->guard(function () use ($data, $request, $service, $presenter) {
            $pairs = isset($data['pairs']) ? $data['pairs'] : $service->presetPairs((string) $data['preset'], $data['symbols'] ?? null);
            $result = $service->startBatch($pairs, $data['type'] ?? null, (string) $request->user()->id);

            return response()->json(['batch_id' => $result['batch_id'], 'runs' => $presenter->summaries($result['runs']), 'skipped' => $result['skipped']], 202);
        });
    }

    public function stopRun(Request $request, string $workspace, string $run, TrainingRunService $service, TrainingRunPresenter $presenter): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $model = $this->findRun($run);

        return $this->guard(fn () => response()->json(['run' => $presenter->summary($service->stop($model))], 202));
    }

    public function retryRun(Request $request, string $workspace, string $run, TrainingRunService $service, TrainingRunPresenter $presenter): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $model = $this->findRun($run);

        return $this->guard(fn () => response()->json(['run' => $presenter->summary($service->retry($model, (string) $request->user()->id))], 202));
    }

    public function stopBatch(Request $request, string $workspace, string $batch, TrainingRunService $service): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);

        return $this->guard(fn () => response()->json($service->stopBatch($batch), 202));
    }

    public function resumeBackfill(Request $request, string $workspace, string $symbol, TrainingRunService $service): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);

        return $this->guard(fn () => response()->json(['job' => $service->resumeBackfill($symbol)], 202));
    }

    public function candles(Request $request, string $workspace, string $symbol, TrainingRunService $service, TrainingCatalogService $catalog): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:500']);

        return $this->guard(fn () => $this->read($catalog->candles($service->normalizeSymbol($symbol), (int) ($data['limit'] ?? 120))));
    }

    public function models(Request $request, string $workspace, TrainingCatalogService $catalog): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate(['symbol' => 'nullable|string|max:30', 'horizon_minutes' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:200']);

        return $this->read($catalog->models($data, (int) ($data['limit'] ?? 50)));
    }

    private function findRun(string $id): MlTrainingRun
    {
        $run = preg_match('/^[0-9a-f-]{32,36}$/i', $id) ? MlTrainingRun::query()->find($id) : null;
        abort_if($run === null, 404, 'Training run not found.');

        return $run;
    }

    /** @param array<string, mixed> $payload */
    private function read(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /** Refused control-plane requests are HTTP 422 {message, code}. */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (TrainingRunException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], 422);
        }
    }
}
