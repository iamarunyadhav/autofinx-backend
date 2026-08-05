<?php

namespace App\Console\Commands;

use App\Models\FutureTradeAiDecision;
use App\Models\FutureTradeAiReview;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily self-review: aggregates yesterday's AI gate decisions vs outcomes and
 * asks Claude for an advisory report. Suggestions are ADVISORY ONLY — nothing
 * is ever auto-applied to bot settings.
 */
class FutureTradeAiDailyReviewCommand extends Command
{
    protected $signature = 'future-trade:ai-daily-review {--date=} {--workspace=}';

    protected $description = 'Generate the daily AI gate performance review (advisory only).';

    private const REPORT_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['summary', 'what_worked', 'what_failed', 'suggested_threshold_changes', 'confidence_calibration_note'],
        'properties' => [
            'summary' => ['type' => 'string'],
            'what_worked' => ['type' => 'array', 'items' => ['type' => 'string']],
            'what_failed' => ['type' => 'array', 'items' => ['type' => 'string']],
            'suggested_threshold_changes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confidence_calibration_note' => ['type' => 'string'],
        ],
    ];

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $workspaceIds = $this->option('workspace')
            ? [(string) $this->option('workspace')]
            : FutureTradeAiDecision::query()
                ->whereBetween('created_at', [$date, $date->copy()->endOfDay()])
                ->distinct()
                ->pluck('workspace_id')
                ->all();

        if ($workspaceIds === []) {
            $this->info('No AI gate decisions found for ' . $date->toDateString() . ' — nothing to review.');

            return self::SUCCESS;
        }

        foreach ($workspaceIds as $workspaceId) {
            $this->reviewWorkspace((string) $workspaceId, $date);
        }

        return self::SUCCESS;
    }

    private function reviewWorkspace(string $workspaceId, Carbon $date): void
    {
        $decisions = FutureTradeAiDecision::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('created_at', [$date, $date->copy()->endOfDay()])
            ->get();

        if ($decisions->isEmpty()) {
            return;
        }

        $stats = $this->aggregate($decisions);
        $review = FutureTradeAiReview::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'review_date' => $date->toDateString()],
            ['stats_json' => $stats]
        );

        try {
            [$report, $usage, $model] = $this->generateReport($stats);
            $review->forceFill([
                'report_json' => $report,
                'ai_model' => $model,
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'error' => null,
            ])->save();

            Notification::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'type' => 'ai_gate_daily_review',
                'content' => [
                    'title' => 'AI Gate daily review — ' . $date->toDateString(),
                    'body' => (string) ($report['summary'] ?? 'Review generated.'),
                    'severity' => 'info',
                    'review_id' => $review->id,
                    'stats' => $stats,
                ],
            ]);
            $this->info(sprintf('[%s] Review generated for %s (%d decisions).', $workspaceId, $date->toDateString(), $decisions->count()));
        } catch (Throwable $e) {
            $review->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            $this->warn(sprintf('[%s] Review LLM step failed: %s', $workspaceId, $e->getMessage()));
        }
    }

    private function aggregate($decisions): array
    {
        $confirmed = $decisions->whereIn('decision', [FutureTradeAiDecision::DECISION_CONFIRM, FutureTradeAiDecision::DECISION_RESIZE]);
        $confirmedWithOutcome = $confirmed->whereNotNull('outcome');
        $wins = $confirmedWithOutcome->where('outcome', FutureTradeAiDecision::OUTCOME_WIN)->count();
        $outcomeCount = $confirmedWithOutcome->count();

        return [
            'total_decisions' => $decisions->count(),
            'confirmed' => $decisions->where('decision', FutureTradeAiDecision::DECISION_CONFIRM)->count(),
            'resized' => $decisions->where('decision', FutureTradeAiDecision::DECISION_RESIZE)->count(),
            'vetoed' => $decisions->where('decision', FutureTradeAiDecision::DECISION_VETO)->count(),
            'errors_fail_open' => $decisions->where('decision', FutureTradeAiDecision::DECISION_ERROR_FAIL_OPEN)->count(),
            'errors_fail_closed' => $decisions->where('decision', FutureTradeAiDecision::DECISION_ERROR_FAIL_CLOSED)->count(),
            'skipped_cap' => $decisions->where('decision', FutureTradeAiDecision::DECISION_SKIPPED_CAP)->count(),
            'executed' => $decisions->where('executed', true)->count(),
            'confirmed_with_outcome' => $outcomeCount,
            'confirmed_wins' => $wins,
            'confirmed_win_rate' => $outcomeCount > 0 ? round($wins / $outcomeCount, 4) : null,
            'confirmed_net_pnl_usdt' => round((float) $confirmedWithOutcome->sum(fn ($d) => (float) $d->outcome_pnl), 4),
            'avg_ai_confidence' => round((float) $decisions->whereNotNull('ai_confidence')->avg(fn ($d) => (float) $d->ai_confidence), 4),
            'avg_latency_ms' => (int) $decisions->whereNotNull('latency_ms')->avg('latency_ms'),
            'total_cost_usd' => round((float) $decisions->sum(fn ($d) => (float) $d->cost_usd), 4),
            'cache_hit_rate' => $this->cacheHitRate($decisions),
            'top_risk_flags' => $this->topRiskFlags($decisions),
            'by_symbol' => $decisions->groupBy('symbol')->map(fn ($group) => [
                'decisions' => $group->count(),
                'vetoed' => $group->where('decision', FutureTradeAiDecision::DECISION_VETO)->count(),
                'net_pnl_usdt' => round((float) $group->whereNotNull('outcome')->sum(fn ($d) => (float) $d->outcome_pnl), 4),
            ])->all(),
        ];
    }

    private function cacheHitRate($decisions): ?float
    {
        $calls = $decisions->filter(fn ($d) => (int) $d->input_tokens > 0 || (int) $d->cache_read_input_tokens > 0);
        if ($calls->isEmpty()) {
            return null;
        }

        $withHits = $calls->filter(fn ($d) => (int) $d->cache_read_input_tokens > 0)->count();

        return round($withHits / $calls->count(), 4);
    }

    private function topRiskFlags($decisions): array
    {
        $counts = [];
        foreach ($decisions as $decision) {
            foreach ((array) $decision->risk_flags as $flag) {
                $counts[(string) $flag] = ($counts[(string) $flag] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice($counts, 0, 10, true);
    }

    /**
     * One Claude call, no latency pressure — adaptive thinking + report schema.
     *
     * @return array{0: array, 1: array, 2: string}
     */
    private function generateReport(array $stats): array
    {
        $apiKey = (string) config('future_trade.ai_gate.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('AI gate API key is not configured.');
        }

        $model = (string) config('future_trade.ai_gate.model', 'claude-opus-4-8');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->connectTimeout(2)
            ->timeout(120)
            ->post((string) config('future_trade.ai_gate.api_url'), [
                'model' => $model,
                'max_tokens' => 2000,
                'thinking' => ['type' => 'adaptive'],
                'system' => 'You are a quantitative trading performance reviewer for an automated crypto futures bot '
                    . 'whose entries are filtered by an AI confirmation gate. You receive one day of aggregated gate '
                    . 'statistics. Produce a factual, advisory review. Suggestions are recommendations for a human '
                    . 'operator — they are never auto-applied. Be specific and numeric where the data allows.',
                'messages' => [[
                    'role' => 'user',
                    'content' => json_encode($stats, JSON_UNESCAPED_SLASHES),
                ]],
                'output_config' => [
                    'format' => ['type' => 'json_schema', 'schema' => self::REPORT_SCHEMA],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Daily review request failed (HTTP ' . $response->status() . '): ' . mb_substr((string) $response->body(), 0, 300));
        }

        $json = $response->json();
        $report = json_decode((string) data_get($json, 'content.0.text', ''), true);
        if (! is_array($report)) {
            // Adaptive thinking may emit a thinking block first — find the text block.
            foreach ((array) data_get($json, 'content', []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $report = json_decode((string) ($block['text'] ?? ''), true);
                    break;
                }
            }
        }
        if (! is_array($report)) {
            throw new \RuntimeException('Daily review returned an unparseable report.');
        }

        return [$report, (array) data_get($json, 'usage', []), (string) data_get($json, 'model', $model)];
    }
}
