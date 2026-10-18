<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\V5ResearchScannerRun;
use App\Models\V5ResearchSelection;
use App\Models\V5ShadowPrediction;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V5ResearchController extends Controller
{
    use AssertsWorkspaceMembership;

    public function overview(): JsonResponse
    {
        return response()->json(['status' => 'RESEARCH_ONLY', 'execution_authorized' => false, 'latest_run' => $this->displayedRun()]);
    }

    public function latest(): JsonResponse
    {
        return response()->json(['execution_authorized' => false, 'run' => $this->displayedRun()]);
    }

    public function candidates(): JsonResponse
    {
        $run = $this->displayedRun();

        return response()->json(['execution_authorized' => false, 'candidates' => $run?->candidates ?? []]);
    }

    public function status(): JsonResponse
    {
        $run = $this->latestRun();

        return response()->json([
            'execution_authorized' => false,
            'data_sources' => $run?->source_status ?? ['status' => 'NO_RUN'],
            'model_status' => 'UNCALIBRATED_RESEARCH_ONLY',
        ]);
    }

    public function history(): JsonResponse
    {
        return response()->json([
            'execution_authorized' => false,
            'predictions' => V5ShadowPrediction::query()->latest('forecast_at')->limit(100)->get(),
        ]);
    }

    public function selections(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->selectionList($request, $workspace);
    }

    public function select(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->createSelection($request, $workspace);
    }

    public function remove(Request $request, string $workspace, string $id): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $selection = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
        $selection->update(['status' => 'REMOVED_BY_USER']);

        return response()->json(['execution_authorized' => false, 'selection' => $selection->fresh()]);
    }

    public function selectionMetrics(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->metrics($request, $workspace);
    }

    /** Compatibility routes; new callers should use workspace-scoped endpoints. */
    public function legacySelections(Request $request): JsonResponse
    {
        return $this->selectionList($request, $this->legacyWorkspace($request));
    }

    public function legacySelect(Request $request): JsonResponse
    {
        return $this->createSelection($request, $this->legacyWorkspace($request));
    }

    public function legacyRemove(Request $request, string $id): JsonResponse
    {
        return $this->remove($request, $this->legacyWorkspace($request), $id);
    }

    public function legacySelectionMetrics(Request $request): JsonResponse
    {
        return $this->metrics($request, $this->legacyWorkspace($request));
    }

    public function rankingHistory(): JsonResponse
    {
        $runs = V5ResearchScannerRun::with('candidates')->latest('as_of')->limit(20)->get()->reverse()->values();
        $previous = [];
        $snapshots = [];
        foreach ($runs as $run) {
            $current = [];
            foreach ($run->candidates as $candidate) {
                $current[$candidate->symbol] = (int) $candidate->rank;
            }
            $rows = [];
            foreach ($current as $symbol => $rank) {
                $rows[] = [
                    'symbol' => $symbol,
                    'current_rank' => $rank,
                    'previous_rank' => $previous[$symbol] ?? null,
                    'rank_movement' => isset($previous[$symbol]) ? $previous[$symbol] - $rank : null,
                    'new_entrant' => ! isset($previous[$symbol]),
                    'execution_authorized' => false,
                ];
            }
            foreach (array_diff_key($previous, $current) as $symbol => $rank) {
                $rows[] = ['symbol' => $symbol, 'current_rank' => null, 'previous_rank' => $rank, 'rank_movement' => null, 'removed' => true, 'execution_authorized' => false];
            }
            $snapshots[] = ['as_of' => $run->as_of, 'rows' => $rows];
            $previous = $current;
        }

        return response()->json(['execution_authorized' => false, 'snapshots' => $snapshots]);
    }

    private function createSelection(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+USDT$/'],
            'side' => ['required', 'in:LONG,SHORT'],
            'horizon' => ['nullable', 'in:1h,4h,24h'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->latestRun();
        $candidate = $run?->candidates->firstWhere('symbol', $data['symbol']);
        $referencePrice = (float) data_get($candidate?->evidence, 'assessment.horizons.15m.reference_close', 0);

        if (! $candidate || $referencePrice <= 0) {
            return response()->json(['message' => 'A current server-observed reference price is required for selection.'], 422);
        }

        $selection = DB::transaction(function () use ($request, $workspace, $data, $run, $candidate, $referencePrice) {
            WorkspaceMember::query()
                ->where('workspace_id', $workspace)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeQuery = V5ResearchSelection::query()
                ->where('workspace_id', $workspace)
                ->where('user_id', $request->user()->id)
                ->where('status', 'TRACKING');

            if ((clone $activeQuery)->where('symbol', $data['symbol'])->exists()) {
                abort(422, 'This symbol is already being tracked in the workspace.');
            }
            if ($activeQuery->count() >= 3) {
                abort(422, 'Maximum three active research selections.');
            }

            $predictionId = V5ShadowPrediction::query()->where('symbol', $data['symbol'])->latest('forecast_at')->value('id');

            return V5ResearchSelection::create([
                'id' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace,
                'symbol' => $data['symbol'],
                'side' => $data['side'],
                'status' => 'TRACKING',
                'selected_at' => now(),
                'expires_at' => $data['expires_at'] ?? null,
                'selection_price' => $referencePrice,
                'prediction_snapshot_id' => $predictionId,
                'original_rank' => $candidate->rank,
                'current_rank' => $candidate->rank,
                'horizon' => $data['horizon'] ?? '24h',
                'notes' => $data['notes'] ?? null,
                'research_metrics' => ['scanner_run_id' => $run->id, 'reference_price_source' => 'server_closed_candle', 'cost_adjusted_return_status' => 'unavailable'],
            ]);
        });

        return response()->json(['execution_authorized' => false, 'selection' => $selection], 201);
    }

    private function selectionList(Request $request, string $workspace): JsonResponse
    {
        $selections = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['execution_authorized' => false, 'selections' => $selections]);
    }

    private function metrics(Request $request, string $workspace): JsonResponse
    {
        $selections = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->get();
        if ($selections->isEmpty()) {
            return response()->json(['execution_authorized' => false, 'metrics' => []]);
        }

        $symbols = $selections->pluck('symbol')->unique()->values();
        $runs = V5ResearchScannerRun::query()
            ->where('as_of', '>=', $selections->min('selected_at'))
            ->with(['candidates' => fn ($query) => $query->whereIn('symbol', $symbols)])
            ->orderBy('as_of')
            ->limit(500)
            ->get();

        $items = $selections->map(function (V5ResearchSelection $selection) use ($runs) {
            $history = [['at' => $selection->selected_at, 'return' => 0.0]];
            $currentPrice = null;
            $currentRank = null;
            foreach ($runs as $run) {
                if ($run->as_of->lt($selection->selected_at)) {
                    continue;
                }
                $candidate = $run->candidates->firstWhere('symbol', $selection->symbol);
                $price = (float) data_get($candidate?->evidence, 'assessment.horizons.15m.reference_close', 0);
                if ($price <= 0 || (float) $selection->selection_price <= 0) {
                    continue;
                }
                $gross = ($selection->side === 'LONG' ? 1 : -1) * (($price / (float) $selection->selection_price) - 1);
                $history[] = ['at' => $run->as_of, 'return' => $gross];
                $currentPrice = $price;
                $currentRank = (int) $candidate->rank;
            }
            if ($currentRank !== null && $selection->current_rank !== $currentRank) {
                $selection->update(['current_rank' => $currentRank]);
            }

            return [
                'selection_id' => $selection->id,
                'symbol' => $selection->symbol,
                'side' => $selection->side,
                'selection_price' => (float) $selection->selection_price,
                'current_reference_price' => $currentPrice,
                'return_since_selection' => count($history) > 1 ? end($history)['return'] : null,
                'estimated_after_cost_return' => null,
                'cost_status' => 'unavailable',
                'history' => $history,
                'status' => $selection->status,
                'execution_authorized' => false,
            ];
        });

        return response()->json(['execution_authorized' => false, 'metrics' => $items]);
    }

    private function legacyWorkspace(Request $request): string
    {
        $workspace = $request->validate(['workspace_id' => ['required', 'uuid']])['workspace_id'];
        $this->assertWorkspaceMembership($request, $workspace);

        return $workspace;
    }

    private function latestRun(): ?V5ResearchScannerRun
    {
        return V5ResearchScannerRun::query()->latest('as_of')->with('candidates')->first();
    }

    private function displayedRun(): ?V5ResearchScannerRun
    {
        $run = $this->latestRun();
        if ($run) {
            $run->setRelation('candidates', $run->candidates->sortBy('rank')->take(3)->values());
        }

        return $run;
    }
}
