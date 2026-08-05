<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FutureTradeAiDecision;
use App\Models\FutureTradeAiReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureTradeAiController extends Controller
{
    /**
     * Paginated AI gate decision log.
     */
    public function decisions(Request $request, string $workspace): JsonResponse
    {
        $query = FutureTradeAiDecision::query()
            ->where('workspace_id', $workspace)
            ->orderByDesc('created_at');

        if ($decision = $request->query('decision')) {
            $query->where('decision', strtoupper((string) $decision));
        }
        if ($symbol = $request->query('symbol')) {
            $query->where('symbol', strtoupper((string) $symbol));
        }
        if ($outcome = $request->query('outcome')) {
            $query->where('outcome', strtolower((string) $outcome));
        }

        $page = $query->paginate(
            perPage: min(100, max(5, (int) $request->query('per_page', 25))),
        )->through(fn (FutureTradeAiDecision $row) => [
            'id' => $row->id,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'symbol' => $row->symbol,
            'side' => $row->side,
            'mode' => $row->mode,
            'decision' => $row->decision,
            'signal_confidence' => (float) $row->signal_confidence,
            'pine_score' => (float) $row->pine_score,
            'ai_confidence' => $row->ai_confidence !== null ? (float) $row->ai_confidence : null,
            'size_multiplier' => $row->size_multiplier !== null ? (float) $row->size_multiplier : null,
            'reasoning' => $row->reasoning,
            'risk_flags' => $row->risk_flags ?? [],
            'ai_model' => $row->ai_model,
            'latency_ms' => $row->latency_ms,
            'cost_usd' => $row->cost_usd !== null ? (float) $row->cost_usd : null,
            'cache_hit' => (int) $row->cache_read_input_tokens > 0,
            'executed' => (bool) $row->executed,
            'block_reason' => $row->block_reason,
            'logical_trade_id' => $row->logical_trade_id,
            'outcome' => $row->outcome,
            'outcome_pnl' => $row->outcome_pnl !== null ? (float) $row->outcome_pnl : null,
            'error' => $row->error,
        ]);

        return response()->json($page);
    }

    /**
     * Accuracy + cost summary for the AI gate.
     */
    public function stats(Request $request, string $workspace): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        $decisions = FutureTradeAiDecision::query()
            ->where('workspace_id', $workspace)
            ->where('created_at', '>=', $since)
            ->get();

        $confirmed = $decisions->whereIn('decision', [FutureTradeAiDecision::DECISION_CONFIRM, FutureTradeAiDecision::DECISION_RESIZE]);
        $confirmedWithOutcome = $confirmed->whereNotNull('outcome');
        $wins = $confirmedWithOutcome->where('outcome', FutureTradeAiDecision::OUTCOME_WIN)->count();
        $apiCalls = $decisions->filter(fn ($d) => $d->decision !== FutureTradeAiDecision::DECISION_SKIPPED_CAP && $d->error === null);
        $cacheHits = $apiCalls->filter(fn ($d) => (int) $d->cache_read_input_tokens > 0)->count();
        $todayCost = $decisions->filter(fn ($d) => $d->created_at?->isToday())->sum(fn ($d) => (float) $d->cost_usd);

        return response()->json([
            'window_days' => $days,
            'total_decisions' => $decisions->count(),
            'confirmed' => $decisions->where('decision', FutureTradeAiDecision::DECISION_CONFIRM)->count(),
            'resized' => $decisions->where('decision', FutureTradeAiDecision::DECISION_RESIZE)->count(),
            'vetoed' => $decisions->where('decision', FutureTradeAiDecision::DECISION_VETO)->count(),
            'errors' => $decisions->whereIn('decision', [FutureTradeAiDecision::DECISION_ERROR_FAIL_OPEN, FutureTradeAiDecision::DECISION_ERROR_FAIL_CLOSED])->count(),
            'skipped_cap' => $decisions->where('decision', FutureTradeAiDecision::DECISION_SKIPPED_CAP)->count(),
            'executed' => $decisions->where('executed', true)->count(),
            'confirmed_with_outcome' => $confirmedWithOutcome->count(),
            'confirmed_win_rate' => $confirmedWithOutcome->count() > 0 ? round($wins / $confirmedWithOutcome->count(), 4) : null,
            'confirmed_net_pnl_usdt' => round((float) $confirmedWithOutcome->sum(fn ($d) => (float) $d->outcome_pnl), 4),
            'avg_ai_confidence' => $decisions->whereNotNull('ai_confidence')->isEmpty()
                ? null
                : round((float) $decisions->whereNotNull('ai_confidence')->avg(fn ($d) => (float) $d->ai_confidence), 4),
            'avg_latency_ms' => $apiCalls->isEmpty() ? null : (int) $apiCalls->avg('latency_ms'),
            'cache_hit_rate' => $apiCalls->isEmpty() ? null : round($cacheHits / $apiCalls->count(), 4),
            'total_cost_usd' => round((float) $decisions->sum(fn ($d) => (float) $d->cost_usd), 4),
            'today_cost_usd' => round((float) $todayCost, 4),
            'calls_today' => $decisions->filter(fn ($d) => $d->created_at?->isToday() && $d->decision !== FutureTradeAiDecision::DECISION_SKIPPED_CAP)->count(),
            'daily_call_cap' => (int) config('future_trade.ai_gate.max_calls_per_day', 100),
        ]);
    }

    /**
     * Daily review reports (advisory only).
     */
    public function reviews(Request $request, string $workspace): JsonResponse
    {
        $reviews = FutureTradeAiReview::query()
            ->where('workspace_id', $workspace)
            ->orderByDesc('review_date')
            ->limit(min(60, max(1, (int) $request->query('limit', 14))))
            ->get()
            ->map(fn (FutureTradeAiReview $review) => [
                'id' => $review->id,
                'review_date' => optional($review->review_date)->toDateString(),
                'stats' => $review->stats_json,
                'report' => $review->report_json,
                'ai_model' => $review->ai_model,
                'cost_usd' => $review->cost_usd !== null ? (float) $review->cost_usd : null,
                'error' => $review->error,
                'created_at' => optional($review->created_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $reviews]);
    }
}
