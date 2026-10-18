<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ml\Shadow\LearningStatusService;
use App\Domain\Ml\Shadow\MlPaperAutopilotService;
use App\Domain\Ml\Shadow\ShadowEvaluationService;
use App\Domain\Ml\Shadow\ShadowPredictionService;
use App\Domain\Ml\Shadow\ShadowDecisionDiagnostics;
use App\Domain\Ml\Shadow\ShadowHypothesisService;
use App\Domain\Ml\Shadow\ShadowResearchAdapter;
use App\Models\NexusEntry;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Quick Trade "Python ML Shadow" endpoints. They only read stored
 * shadow rows — no python call, no exchange call, no order path — so they can
 * never block or slow a Quick Trade request. Membership is required before
 * any status is returned, matching the fresh-dataset dashboard.
 */
final class MlShadowController extends Controller
{
    use AssertsWorkspaceMembership;

    private const SHADOW = ['mode' => 'shadow_only', 'execution' => false, 'influence' => false];

    public function prediction(Request $request, ShadowPredictionService $predictions, ShadowEvaluationService $evaluation): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
            'duration_seconds' => 'required|integer|min:1|max:604800',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        if (! (bool) config('ml.enabled', false)) {
            return response()->json(['enabled' => false, 'status' => 'DISABLED'] + self::SHADOW);
        }
        $symbol = $this->symbol($data['symbol']);
        if ($symbol === null) {
            return response()->json(['enabled' => true, 'status' => 'UNSUPPORTED_SYMBOL', 'supported_symbols' => array_values((array) config('ml.symbols', []))] + self::SHADOW);
        }

        return response()->json(['enabled' => true] + $predictions->payload($symbol, (int) $data['duration_seconds'], $evaluation));
    }

    public function learning(Request $request, LearningStatusService $status): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        $symbol = $this->symbol($data['symbol']);
        if ($symbol === null) {
            return response()->json(['enabled' => (bool) config('ml.enabled', false), 'status' => 'UNSUPPORTED_SYMBOL', 'supported_symbols' => array_values((array) config('ml.symbols', []))] + self::SHADOW);
        }

        return response()->json($status->status($symbol));
    }

    public function holdoutReview(Request $request, LearningStatusService $status): JsonResponse
    {
        $data = $request->validate(['workspace_id' => 'required|uuid', 'symbol' => 'required|string|max:30', 'horizon_minutes' => 'sometimes|integer|min:1|max:240']);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        $symbol = $this->symbol($data['symbol']);
        abort_unless($symbol !== null, 422, 'Unsupported symbol.');
        $snapshot = $status->status($symbol);
        $horizon = (int) ($data['horizon_minutes'] ?? config('ml.horizon_minutes', 15));
        $row = collect($snapshot['horizons'] ?? [])->firstWhere('horizon_minutes', $horizon) ?? [];
        $candidate = (array) ($row['last_candidate'] ?? []);
        $champion = (array) ($row['champion'] ?? []);
        $comparison = (array) ($candidate['comparison'] ?? []);
        return response()->json([
            'symbol' => $symbol, 'horizon_minutes' => $horizon,
            'champion' => ['model_version' => $champion['model_version'] ?? null, 'holdout' => $champion['holdout'] ?? null],
            'challenger' => ['model_version' => $candidate['model_version'] ?? null, 'status' => $candidate['status'] ?? null, 'holdout' => $candidate['holdout'] ?? null],
            'comparison' => $comparison,
            'gate' => ['unseen_holdout_required' => true, 'net_cost_required' => true, 'human_approval_required' => true, 'automatic_promotion' => false, 'execution_authorized' => false],
        ]);
    }

    public function history(Request $request, ShadowDecisionDiagnostics $diagnostics): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
            'limit' => 'sometimes|integer|min:1|max:200',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        $symbol = $this->symbol($data['symbol']);
        if ($symbol === null) {
            return response()->json(['enabled' => (bool) config('ml.enabled', false), 'status' => 'UNSUPPORTED_SYMBOL', 'items' => []] + self::SHADOW);
        }

        return response()->json([
            'enabled' => (bool) config('ml.enabled', false),
            'symbol' => $symbol,
            'horizon_minutes' => (int) config('ml.horizon_minutes', 15),
            'items' => $diagnostics->history($symbol, (int) config('ml.horizon_minutes', 15), (int) ($data['limit'] ?? 40)),
        ] + self::SHADOW);
    }

    public function createResearchCase(Request $request, ShadowDecisionDiagnostics $diagnostics): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
            'note' => 'nullable|string|max:1000',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        abort_unless(Schema::hasTable('nexus_entries'), 503, 'Nexus database migration is pending.');
        $symbol = $this->symbol($data['symbol']);
        abort_unless($symbol !== null, 422, 'Unsupported symbol.');
        $horizon = (int) config('ml.horizon_minutes', 15);
        $summary = $diagnostics->forScope($symbol, $horizon);
        $fingerprint = hash('sha256', json_encode([$data['workspace_id'], $symbol, $horizon, $summary['counts']]));
        $entry = NexusEntry::firstOrCreate(
            ['workspace_id' => $data['workspace_id'], 'kind' => 'candidate', 'fingerprint' => $fingerprint],
            [
                'title' => 'ML shadow diagnosis: '.$symbol.' WAIT/cost review',
                'status' => 'PROPOSED',
                'evidence_level' => 'C',
                'payload' => [
                    'source' => 'ml_shadow_diagnostics',
                    'symbol' => $symbol,
                    'horizon_minutes' => $horizon,
                    'diagnostics' => $summary,
                    'user_note' => $data['note'] ?? null,
                    'research_only' => true,
                    'execution_authorized' => false,
                    'next_step' => 'Investigate data, feature/model, cost, and regime hypotheses before any retraining.',
                ],
            ]
        );

        return response()->json(['created' => $entry->wasRecentlyCreated, 'entry' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function createHypothesis(Request $request, ShadowDecisionDiagnostics $diagnostics, ShadowHypothesisService $hypotheses): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
            'note' => 'nullable|string|max:1000',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        abort_unless(Schema::hasTable('nexus_entries'), 503, 'Nexus database migration is pending.');
        $symbol = $this->symbol($data['symbol']);
        abort_unless($symbol !== null, 422, 'Unsupported symbol.');
        $summary = $diagnostics->forScope($symbol, (int) config('ml.horizon_minutes', 15));
        $entry = $hypotheses->create($data['workspace_id'], $symbol, $summary, $data['note'] ?? null);

        return response()->json(['created' => $entry->wasRecentlyCreated, 'entry' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function reviewHypothesis(Request $request, ShadowDecisionDiagnostics $diagnostics, ShadowResearchAdapter $adapter): JsonResponse
    {
        $data = $request->validate(['workspace_id' => 'required|uuid', 'symbol' => 'required|string|max:30']);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        $symbol = $this->symbol($data['symbol']);
        abort_unless($symbol !== null, 422, 'Unsupported symbol.');
        $summary = $diagnostics->forScope($symbol, (int) config('ml.horizon_minutes', 15));

        return response()->json($adapter->review($summary, $symbol) + ['execution_authorized' => false, 'research_only' => true]);
    }

    /** Queue an experiment proposal for human review only; does not dispatch a job. */
    public function queueExperimentReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid', 'candidate_id' => 'required|uuid',
            'symbol' => 'required|string|max:30', 'timeframe' => 'required|in:15m,1h',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        abort_unless(Schema::hasTable('nexus_entries'), 503, 'Nexus database migration is pending.');
        $candidate = NexusEntry::where('workspace_id', $data['workspace_id'])->where('kind', 'candidate')->findOrFail($data['candidate_id']);
        abort_unless($candidate->status === 'APPROVED', 409, 'Approve the research hypothesis before queueing an experiment review.');
        $payload = ['candidate_id' => $candidate->id, 'symbol' => $data['symbol'], 'timeframe' => $data['timeframe'], 'research_only' => true, 'execution_authorized' => false, 'requires_human_dispatch' => true];
        $entry = NexusEntry::firstOrCreate(['workspace_id' => $data['workspace_id'], 'kind' => 'experiment', 'fingerprint' => hash('sha256', json_encode($payload))], ['title' => 'Review-only shadow experiment: '.$data['symbol'].' '.$data['timeframe'], 'status' => 'REVIEW_REQUIRED', 'evidence_level' => 'C', 'payload' => $payload]);

        return response()->json(['created' => $entry->wasRecentlyCreated, 'entry' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    /** ML Paper Autopilot dashboard — simulated trades only, separate from user trades. */
    public function autopilot(Request $request, MlPaperAutopilotService $autopilot): JsonResponse
    {
        $data = $request->validate(['workspace_id' => 'required|uuid']);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);

        return response()->json($autopilot->summary());
    }

    private function symbol(string $raw): ?string
    {
        $symbol = strtoupper(str_replace(['/', '-', '_', ' '], '', $raw));

        return in_array($symbol, (array) config('ml.symbols', []), true) ? $symbol : null;
    }
}
