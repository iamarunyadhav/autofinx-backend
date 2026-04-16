<?php

namespace App\Http\Controllers\Api;

use App\Domain\Portfolio\PortfolioRecommendationService;
use App\Http\Controllers\Controller;
use App\Models\PortfolioIntelligenceConfig;
use App\Models\PortfolioIntelligenceRun;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortfolioIntelligenceController extends Controller
{
    public function showConfig(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json([
            'config' => $this->configForWorkspace($workspaceId)->fresh(),
            'effective_config' => $this->configForWorkspace($workspaceId)->toArray(),
        ]);
    }

    public function updateConfig(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'enabled' => 'boolean',
            'ranking_mode' => 'required|string|in:heuristic,score_based,rule_based',
            'allocation_mode' => 'required|string|in:equal_weight,score_weighted,capped_score_weighted,conservative_risk_weighted',
            'opportunity_score_threshold' => 'nullable|numeric|min:0|max:100',
            'max_total_allocated_pct' => 'nullable|numeric|min:0.1|max:100',
            'max_symbol_allocated_pct' => 'nullable|numeric|min:0.1|max:100',
            'max_correlated_group_allocated_pct' => 'nullable|numeric|min:0.1|max:100',
            'max_simultaneous_new_trades' => 'nullable|integer|min:0|max:50',
            'reserve_cash_buffer_pct' => 'nullable|numeric|min:0|max:99.9',
            'max_open_positions' => 'nullable|integer|min:0|max:200',
            'max_open_decision_count' => 'nullable|integer|min:0|max:200',
            'max_total_notional' => 'nullable|numeric|min:0',
            'max_daily_loss_pct' => 'nullable|numeric|min:0|max:100',
            'max_drawdown_pct' => 'nullable|numeric|min:0|max:100',
            'prefer_hold_cash_under_high_uncertainty' => 'boolean',
            'reduce_size_under_external_risk' => 'boolean',
            'require_approval_for_rotation' => 'boolean',
            'enable_reduce_recommendations' => 'boolean',
            'enable_rotation_recommendations' => 'boolean',
            'score_weights_json' => 'nullable|array',
            'score_weights_json.*' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $maxTotalPct = (float) ($data['max_total_allocated_pct'] ?? $this->configForWorkspace($workspaceId)->max_total_allocated_pct ?? 35);
        $maxSymbolPct = (float) ($data['max_symbol_allocated_pct'] ?? $this->configForWorkspace($workspaceId)->max_symbol_allocated_pct ?? 8);
        $reserveCashBufferPct = (float) ($data['reserve_cash_buffer_pct'] ?? $this->configForWorkspace($workspaceId)->reserve_cash_buffer_pct ?? 15);

        if ($maxSymbolPct > $maxTotalPct && $maxTotalPct > 0) {
            return response()->json(['message' => 'max_symbol_allocated_pct cannot exceed max_total_allocated_pct.'], 422);
        }

        if ($reserveCashBufferPct >= 100) {
            return response()->json(['message' => 'reserve_cash_buffer_pct must be below 100.'], 422);
        }

        $existing = $this->configForWorkspace($workspaceId);
        $config = PortfolioIntelligenceConfig::updateOrCreate(
            ['workspace_id' => $workspaceId],
            array_merge($data, [
                'id' => $existing->id,
                'workspace_id' => $workspaceId,
            ])
        );

        return response()->json([
            'config' => $config->fresh(),
            'effective_config' => $config->toArray(),
        ]);
    }

    public function context(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        return response()->json($service->preview($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '')));
    }

    public function rankedOpportunities(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $snapshot = $service->preview($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        return response()->json([
            'opportunities' => array_slice((array) data_get($snapshot, 'ranked_opportunities', []), 0, (int) ($data['limit'] ?? 50)),
            'summary' => data_get($snapshot, 'summary', []),
        ]);
    }

    public function symbolScore(Request $request, string $workspaceId, string $symbol, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $score = $service->symbolScore($workspaceId, strtoupper($symbol), array_filter([
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        abort_if($score === [], 404, 'Symbol opportunity not found.');

        return response()->json($score);
    }

    public function allocation(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $snapshot = $service->preview($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        return response()->json([
            'allocation' => data_get($snapshot, 'allocation', []),
            'summary' => data_get($snapshot, 'summary', []),
        ]);
    }

    public function riskBudget(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $snapshot = $service->preview($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        return response()->json(data_get($snapshot, 'risk_budget', []));
    }

    public function recommendations(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $snapshot = $service->preview($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        return response()->json(data_get($snapshot, 'recommendations', []));
    }

    public function run(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
            'trigger_type' => 'nullable|string|max:32',
        ]);

        $result = $service->run($workspaceId, array_filter([
            'symbol' => $data['symbol'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? null,
            'trigger_type' => $data['trigger_type'] ?? 'manual',
            'correlation_id' => (string) Str::uuid(),
        ], static fn ($value) => $value !== null && $value !== ''));

        return response()->json($result, 201);
    }

    public function runs(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));

        return response()->json(
            PortfolioIntelligenceRun::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('started_at')
                ->limit($limit)
                ->get()
        );
    }

    public function latest(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json($service->latestSnapshot($workspaceId));
    }

    private function configForWorkspace(string $workspaceId): PortfolioIntelligenceConfig
    {
        return PortfolioIntelligenceConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                ...PortfolioIntelligenceConfig::defaults(),
            ]
        );
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }
}
