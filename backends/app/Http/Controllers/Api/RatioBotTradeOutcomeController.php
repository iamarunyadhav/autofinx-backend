<?php

namespace App\Http\Controllers\Api;

use App\Domain\RatioBot\RatioBotTradeOutcomeService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\RatioBotConfig;
use App\Models\RatioBotTradeOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * READ-ONLY Ratio Bot trade-level accuracy reporting. Kept in its own
 * controller (rather than growing RatioBotController) so the canonical
 * RatioBotController stays untouched; authorization is the shared
 * AssertsWorkspaceMembership trait, a verbatim copy of RatioBotController's
 * own membership check.
 */
class RatioBotTradeOutcomeController extends Controller
{
    use AssertsWorkspaceMembership;

    public function __construct(
        private readonly RatioBotTradeOutcomeService $outcomes,
    ) {
    }

    /**
     * GET /ratio-bots/{ratioBot}/trade-accuracy?mode=live|sandbox&limit=50
     */
    public function forBot(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $ratioBot->workspace_id);
        $filters = $this->filters($request);

        $scoped = fn (): Builder => $this->applyMode(
            RatioBotTradeOutcome::query()->where('ratio_bot_config_id', $ratioBot->id),
            $filters['mode'],
        );

        $recent = $scoped()
            ->orderByDesc('executed_at')
            ->limit($filters['limit'])
            ->get()
            ->map(fn (RatioBotTradeOutcome $row) => $this->outcomes->presentRow($row))
            ->values();

        return response()->json([
            'bot_id' => (string) $ratioBot->id,
            'symbol' => $ratioBot->normalizedSymbol(),
            'mode' => $filters['mode'],
            'summary' => $this->outcomes->summary($scoped()),
            'recent' => $recent,
        ]);
    }

    /**
     * GET /workspaces/{workspace}/ratio-bots/trade-accuracy?mode=live|sandbox
     */
    public function forWorkspace(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);
        $filters = $this->filters($request);

        $bots = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->get(['id', 'name', 'symbol', 'execution_mode']);
        $botIds = $bots->pluck('id')->all();

        $scoped = fn (): Builder => $this->applyMode(
            RatioBotTradeOutcome::query()->whereIn('ratio_bot_config_id', $botIds),
            $filters['mode'],
        );

        return response()->json([
            'workspace_id' => $workspaceId,
            'mode' => $filters['mode'],
            'summary' => $this->outcomes->summary($scoped()),
            'per_bot' => $bots->map(fn (RatioBotConfig $bot) => [
                'bot_id' => (string) $bot->id,
                'name' => $bot->name,
                'symbol' => $bot->normalizedSymbol(),
                'execution_mode' => $bot->execution_mode,
                'summary' => $this->outcomes->summary(
                    $this->applyMode(RatioBotTradeOutcome::query()->where('ratio_bot_config_id', $bot->id), $filters['mode'])
                ),
            ])->values(),
        ]);
    }

    /**
     * @return array{mode: string|null, limit: int}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'mode' => 'nullable|string|in:live,sandbox',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        return [
            'mode' => $data['mode'] ?? null,
            'limit' => (int) ($data['limit'] ?? 50),
        ];
    }

    private function applyMode(Builder $query, ?string $mode): Builder
    {
        return $mode === null ? $query : $query->where('execution_mode', $mode);
    }
}
