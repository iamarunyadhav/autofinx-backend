<?php

namespace App\Http\Controllers\Api;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshModelCandidate;
use App\Models\PredictionFreshModelRegistry;
use Illuminate\Http\Request;

final class FreshPredictionDatasetDashboardController extends Controller
{
    use AssertsWorkspaceMembership;

    public function index(Request $request, FreshPredictionDatasetAuditService $audits)
    {
        $data = $request->validate(['workspace_id' => 'required|uuid']);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);

        // The canonical candle source is deliberately global/read-only market
        // research, never workspace trading state. Membership is still required
        // before returning its operational status to the UI.
        $datasets = PredictionFreshDataset::query()->latest('started_at')->limit(10)->get();
        $datasetPayload = $datasets->map(function (PredictionFreshDataset $dataset) use ($audits): array {
            $report = $audits->report($dataset);
            return [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'status' => $dataset->status,
                'started_at' => $dataset->started_at?->toIso8601String(),
                'sealed_at' => data_get($dataset->metadata, 'sealed_at'),
                'pairs' => $report['pairs'],
                'ready_pairs' => $report['ready_pairs'],
                'total_pairs' => $report['total_pairs'],
                'execution' => false,
                'influence' => false,
            ];
        })->values();
        $datasetIds = $datasets->pluck('id');
        $candidates = PredictionFreshModelCandidate::query()->whereIn('dataset_id', $datasetIds)->latest()->limit(50)->get();
        $registrations = PredictionFreshModelRegistry::query()->whereIn('candidate_id', $candidates->pluck('id'))->get()->keyBy('candidate_id');

        return response()->json([
            'datasets' => $datasetPayload,
            'candidates' => $candidates->map(static fn (PredictionFreshModelCandidate $candidate): array => [
                'id' => $candidate->id,
                'dataset_id' => $candidate->dataset_id,
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'provider' => $candidate->provider,
                'status' => $candidate->status,
                'metrics' => $candidate->metrics,
                'created_at' => $candidate->created_at?->toIso8601String(),
            ])->values(),
            'registry' => $registrations->map(static fn (PredictionFreshModelRegistry $entry): array => [
                'candidate_id' => $entry->candidate_id,
                'status' => $entry->status,
                'execution' => false,
                'influence' => false,
            ])->values(),
            'execution' => false,
            'influence' => false,
        ]);
    }
}
