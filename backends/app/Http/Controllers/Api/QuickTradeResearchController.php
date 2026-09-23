<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\QuickTradeAccuracyReport;
use App\Domain\Trading\QuickTradeResearchService;
use App\Http\Controllers\Controller;
use App\Models\QuickTrade;
use App\Models\WorkspaceMember;
use App\Models\RatioBotForecastBacktest;
use App\Models\BacktestRun;
use App\Jobs\RunQuickTradeHistoricalBacktestJob;
use App\Domain\Trading\ForecastMetricsService;
use App\Domain\RatioBot\ForecastCalibrationQualityService;
use App\Domain\RatioBot\ForecastModelComparisonService;
use App\Domain\RatioBot\ForecastProductionReadinessReport;
use App\Models\ForecastReadinessSnapshot;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class QuickTradeResearchController extends Controller
{
    public function startBacktest(Request $request, string $workspace)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'symbol' => 'required|string|max:32', 'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600',
            'exchange' => 'nullable|string|in:binance,binance_futures',
            'candle_limit' => 'nullable|integer|min:30|max:1000',
        ]);
        $exchange = (string) ($data['exchange'] ?? 'binance_futures');
        $run = BacktestRun::create([
            'id' => (string) Str::uuid(), 'bot_type' => 'quick_trade',
            'exchange' => $exchange, 'symbol' => strtoupper($data['symbol']),
            'timeframe' => $data['timeframe'], 'horizon_seconds' => $data['expiry_seconds'],
            'candle_limit' => $data['candle_limit'] ?? 500, 'status' => 'queued', 'queued_at' => now(),
        ]);
        RunQuickTradeHistoricalBacktestJob::dispatch(
            $exchange, $run->symbol, $run->timeframe, $run->horizon_seconds,
            $run->candle_limit, $run->id, 'quick_trade'
        )->onQueue('analytics');
        return response()->json(['run' => $run->fresh(), 'status' => 'queued'], 202);
    }

    public function backtestResults(Request $request, string $workspace)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'symbol' => 'nullable|string|max:32', 'timeframe' => 'nullable|string|max:16',
            'expiry_seconds' => 'nullable|integer|min:5|max:3600',
        ]);
        $prefix = $data['symbol'] ? '%:' . strtoupper($data['symbol']) . ':' . ($data['timeframe'] ?? '%') : '%';
        $rows = RatioBotForecastBacktest::query()
            ->where('bot_type', 'quick_trade')
            ->when($data['expiry_seconds'] ?? null, fn ($q, $v) => $q->where('horizon_seconds', $v))
            ->when($data['symbol'] ?? null, fn ($q) => $q->where('bot_instance_id', 'like', $prefix))
            ->orderByDesc('computed_at')->limit(100)->get()
            ->map(fn ($row) => [
                'bot_instance_id' => $row->bot_instance_id, 'horizon_seconds' => $row->horizon_seconds,
                'confidence_bucket' => $row->confidence_bucket, 'sample_size' => $row->sample_size,
                'hits' => $row->hits, 'raw_hit_rate_pct' => $row->hit_rate_pct !== null ? (float) $row->hit_rate_pct : null,
                'calibrated' => $row->sample_size >= \App\Domain\RatioBot\ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE,
                'hit_rate_pct' => $row->sample_size >= \App\Domain\RatioBot\ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE
                    ? (float) $row->hit_rate_pct : null,
                'avg_confidence_pct' => $row->avg_confidence_pct !== null ? (float) $row->avg_confidence_pct : null,
                'computed_at' => $row->computed_at?->toIso8601String(),
            ])->values();
        $runs = BacktestRun::query()->where('bot_type', 'quick_trade')
            ->when($data['symbol'] ?? null, fn ($q, $v) => $q->where('symbol', strtoupper($v)))
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn ($run) => [
                'id' => $run->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
                'horizon_seconds' => $run->horizon_seconds, 'status' => $run->status,
                'candles_written' => $run->candles_written, 'result_rows' => $run->result_rows,
                'error_message' => $run->error_message, 'queued_at' => $run->queued_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
            ])->values();
        return response()->json([
            'schema_version' => 1, 'status' => $runs->first()['status'] ?? ($rows->isEmpty() ? 'no_results' : 'completed'),
            'status_source' => $runs->isEmpty() ? 'persisted_backtest_rows' : 'backtest_runs',
            'runs' => $runs, 'results' => $rows,
            'note' => 'Rows are walk-forward results. Confidence is calibrated only at 30+ samples per bucket.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request, string $workspace, QuickTradeResearchService $research, ForecastMetricsService $metrics)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'symbol' => 'nullable|string|max:32', 'timeframe' => 'nullable|string|max:16',
            'expiry_seconds' => 'nullable|integer|min:5|max:3600', 'mode' => 'nullable|in:live,paper',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);
        $trades = QuickTrade::where('workspace_id', $workspace)->where('status', '!=', 'open')
            ->whereNotNull('settled_at')
            ->when($data['symbol'] ?? null, fn ($q, $v) => $q->where('symbol', strtoupper($v)))
            ->when($data['timeframe'] ?? null, fn ($q, $v) => $q->where('timeframe', $v))
            ->when($data['expiry_seconds'] ?? null, fn ($q, $v) => $q->where('expiry_seconds', $v))
            ->when($data['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v))
            ->orderByDesc('settled_at')->limit((int) ($data['limit'] ?? 1000))->get();
        $rows = $trades->map(fn ($trade) => $research->report($trade));
        $rows = $rows->filter(fn ($row) => $row['execution_environment'] === 'live')->values();
        $evaluated = $rows->filter(fn ($row) => $row['primary_comparison']['direction_correct'] !== null);
        $correct = $evaluated->filter(fn ($row) => $row['primary_comparison']['direction_correct'] === true)->count();
        $netRows = $rows->filter(fn ($row) => $row['net_profit_positive'] !== null);
        $netWins = $netRows->filter(fn ($row) => $row['net_profit_positive'] === true)->count();
        return response()->json(array_merge([
            'schema_version' => 1, 'scope' => ['symbol' => $data['symbol'] ?? null,
                'timeframe' => $data['timeframe'] ?? null, 'expiry_seconds' => $data['expiry_seconds'] ?? null],
            'sample_size' => $rows->count(), 'evaluated_live_forecasts' => $evaluated->count(),
            'direction_accuracy_pct' => $evaluated->count() >= 30 ? round($correct / $evaluated->count() * 100, 2) : null,
            'range_hit_rate_pct' => ($range = $evaluated->filter(fn ($row) => $row['primary_comparison']['price_in_predicted_range'] !== null))->count() >= 30
                ? round($range->filter(fn ($row) => $row['primary_comparison']['price_in_predicted_range'] === true)->count() / $range->count() * 100, 2) : null,
            'net_profit_rate_pct' => $netRows->count() >= 30 ? round($netWins / $netRows->count() * 100, 2) : null,
            'net_pnl' => round((float) $rows->sum(fn ($row) => (float) ($row['net_pnl_booked'] ?? 0)), 8),
            'status' => $evaluated->count() >= 30 ? 'observed_sample' : 'insufficient_data',
            // Part A3 -- fee-free direction accuracy, fee-aware trade-decision
            // accuracy, and the WAIT saved/missed USDT totals, as their own
            // separate block beside (never merged into) the existing metrics.
            'audit_summary' => $research->auditSummary($rows),
            'note' => 'This is settled live-trade research, not a historical backtest and not a live model promotion signal.',
        ], $metrics->summarize($rows)))->header('Cache-Control', 'private, no-store');
    }

    /**
     * Part D1/D2/D3 -- bucketed accuracy, break-even and confidence
     * reliability. Extends the EXISTING research exporter: it reads exactly
     * the rows QuickTradeResearchService::report() already produces (the same
     * source summary()/index() above use), so there is no parallel pipeline
     * and no recomputation of a trade that has already been evaluated.
     *
     * Same workspace-membership auth as every other method here.
     */
    public function accuracyReport(
        Request $request,
        string $workspace,
        QuickTradeResearchService $research,
        QuickTradeAccuracyReport $report,
    ) {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'symbol' => 'nullable|string|max:32', 'timeframe' => 'nullable|string|max:16',
            'expiry_seconds' => 'nullable|integer|min:5|max:3600', 'mode' => 'nullable|in:live,paper',
            'limit' => 'nullable|integer|min:1|max:2000',
        ]);
        $trades = QuickTrade::where('workspace_id', $workspace)->where('status', '!=', 'open')
            ->whereNotNull('settled_at')
            ->when($data['symbol'] ?? null, fn ($q, $v) => $q->where('symbol', strtoupper($v)))
            ->when($data['timeframe'] ?? null, fn ($q, $v) => $q->where('timeframe', $v))
            ->when($data['expiry_seconds'] ?? null, fn ($q, $v) => $q->where('expiry_seconds', $v))
            ->when($data['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v))
            ->orderByDesc('settled_at')->limit((int) ($data['limit'] ?? 2000))->get();
        $rows = $trades->map(fn ($trade) => $research->report($trade))
            ->filter(fn ($row) => $row['execution_environment'] === 'live')
            ->values()
            ->all();

        return response()->json(array_merge(
            ['scope' => [
                'symbol' => $data['symbol'] ?? null,
                'timeframe' => $data['timeframe'] ?? null,
                'expiry_seconds' => $data['expiry_seconds'] ?? null,
            ]],
            $report->generate($rows),
            ['audit_summary' => $research->auditSummary($rows)],
        ))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, string $workspace, QuickTradeResearchService $research)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'trade_ids' => 'nullable|array|max:100', 'trade_ids.*' => 'uuid',
            'symbol' => 'nullable|string|max:32', 'mode' => 'nullable|in:live,paper',
            'source' => 'nullable|string|max:32', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'cursor' => 'nullable|uuid', 'limit' => 'nullable|integer|min:1|max:100',
            'as_of' => 'nullable|date|before_or_equal:now',
        ]);
        $asOf = CarbonImmutable::parse($data['as_of'] ?? now())->utc();
        $limit = (int) ($data['limit'] ?? 50);
        $query = QuickTrade::where('workspace_id', $workspace)->where('status', '!=', 'open')
            ->whereNotNull('settled_at')->where('settled_at', '<=', $asOf)
            ->when($data['trade_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['symbol'] ?? null, fn ($q, $symbol) => $q->where('symbol', strtoupper($symbol)))
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->where('entry_at', '>=', CarbonImmutable::parse($date)->utc()))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->where('entry_at', '<=', CarbonImmutable::parse($date)->utc()))
            ->when($data['cursor'] ?? null, fn ($q, $cursor) => $q->where('id', '>', $cursor));
        $trades = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $trades->count() > $limit;
        $page = $trades->take($limit);
        $rows = $page->map(fn ($trade) => $research->report($trade))->values();
        $summary = [];
        foreach (['primary', 'shadow'] as $name) {
            $evaluated = $rows->filter(fn ($row) => $row['mode'] === 'live' && $row['execution_environment'] === 'live'
                && $row[$name.'_comparison']['direction_correct'] !== null);
            $hits = $evaluated->filter(fn ($row) => $row[$name.'_comparison']['direction_correct'] === true)->count();
            $summary[$name] = ['evaluable' => $evaluated->count(), 'correct' => $hits,
                'accuracy_pct' => $evaluated->count() >= 30 ? round($hits / $evaluated->count() * 100, 2) : null,
                'status' => $evaluated->count() >= 30 ? 'observed_sample' : 'insufficient_data'];
        }
        return response()->json(['schema_version' => 1, 'as_of' => $asOf->toIso8601String(), 'rows' => $rows,
            'next_cursor' => $hasMore ? $page->last()->id : null, 'has_more' => $hasMore,
            'summary' => $summary, 'summary_scope' => 'this_page_live_trades_only',
            // Part A3 -- same four aggregates as /summary, scoped to this page
            // so the JSON export can carry a backend-computed audit_summary
            // rather than one derived client-side.
            'audit_summary' => $research->auditSummary($rows),
            'shadow_only' => true, 'sample_storage_ready' => Schema::hasTable('quick_trade_research_samples'),
            'collection_enabled' => (bool) config('quick_trade_research.enabled', true),
            'shadow_enabled' => app(\App\Domain\Ops\FeatureFlagService::class)->enabled('unified_forecast.quick_trade_shadow', true),
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Read-only reliability-diagram / Brier-score / overconfidence-warning
     * view for one (bot_type, bot_instance_id, horizon_seconds,
     * evaluation_period) scope. Delegates entirely to
     * ForecastCalibrationQualityService::evaluate() (already read-only) --
     * this method adds nothing but workspace-membership auth + input
     * validation, matching every other method in this controller.
     */
    public function calibration(Request $request, string $workspace, ForecastCalibrationQualityService $calibration)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'bot_type' => 'nullable|string|max:32',
            'bot_instance_id' => 'required|string|max:191',
            'horizon_seconds' => 'required|integer|min:5|max:3600',
            'evaluation_period' => 'nullable|in:training,holdout,full',
        ]);
        $result = $calibration->evaluate(
            $data['bot_type'] ?? 'quick_trade',
            $data['bot_instance_id'],
            (int) $data['horizon_seconds'],
            $data['evaluation_period'] ?? 'holdout',
        );

        return response()->json(['schema_version' => 1] + $result)->header('Cache-Control', 'private, no-store');
    }

    /**
     * Read-only production-readiness report for one (bot_type,
     * bot_instance_id, horizon_seconds) scope. Delegates to
     * ForecastProductionReadinessReport::generate(); the only thing this
     * method computes itself is the optional real, settled-live-trade
     * holdout P&L summary the report needs -- built the same way
     * summary()/index() above already do (QuickTradeResearchService::report()
     * + ForecastMetricsService::summarize(), execution_environment==='live'
     * rows only), never fabricated. If no symbol/timeframe scope is given,
     * or no matching trades exist, the summary is null and the report
     * itself documents that as a known limitation -- exactly its contract.
     */
    public function readiness(
        Request $request,
        string $workspace,
        ForecastProductionReadinessReport $report,
        QuickTradeResearchService $research,
        ForecastMetricsService $metrics,
    ) {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'bot_type' => 'nullable|string|max:32',
            'bot_instance_id' => 'required|string|max:191',
            'horizon_seconds' => 'required|integer|min:5|max:3600',
            'symbol' => 'nullable|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'mode' => 'nullable|in:live,paper',
        ]);
        $holdoutPnlSummary = $this->realHoldoutPnlSummary($workspace, $data, $research, $metrics);
        $result = $report->generate(
            $data['bot_type'] ?? 'quick_trade',
            $data['bot_instance_id'],
            (int) $data['horizon_seconds'],
            $holdoutPnlSummary,
        );

        return response()->json(['schema_version' => 1] + $result)->header('Cache-Control', 'private, no-store');
    }

    /**
     * Read-only readiness-snapshot HISTORY for one (bot_type,
     * bot_instance_id, horizon_seconds) scope -- the timestamped
     * `forecast_readiness_snapshots` rows the scheduled
     * `forecast:evaluate-readiness` command writes, so a caller can see a
     * status trend over time (e.g. "when did this flip from VALIDATED to
     * NOT_TRADEABLE") instead of only the live, on-demand readiness()
     * result above. Kept in this controller (despite covering all four bot
     * types, not just Quick Trade) for the same reason readiness()/
     * calibration()/compare() already do above -- they all already accept
     * an arbitrary `bot_type` and are the established home for this exact
     * family of read-only forecast-report endpoints; a new controller would
     * just split one cohesive feature across two files for no benefit.
     * Same workspace-membership auth as every other method here -- this
     * table has no workspace_id of its own (a readiness scope isn't owned
     * by one workspace), so the check is "does the requester belong to ANY
     * workspace" (the same abort_unless(...) shape used everywhere in this
     * controller), matching how readiness()/calibration()/compare() already
     * treat bot_instance_id as a cross-workspace analytics scope, not a
     * workspace-owned resource.
     */
    public function readinessHistory(Request $request, string $workspace)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'bot_type' => 'nullable|string|max:32',
            'bot_instance_id' => 'required|string|max:191',
            'horizon_seconds' => 'required|integer|min:1|max:3600',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $snapshots = ForecastReadinessSnapshot::query()
            ->where('bot_type', $data['bot_type'] ?? 'quick_trade')
            ->where('bot_instance_id', $data['bot_instance_id'])
            ->where('horizon_seconds', (int) $data['horizon_seconds'])
            ->orderByDesc('computed_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(fn (ForecastReadinessSnapshot $snapshot) => [
                'id' => $snapshot->id,
                'bot_type' => $snapshot->bot_type,
                'bot_instance_id' => $snapshot->bot_instance_id,
                'horizon_seconds' => $snapshot->horizon_seconds,
                'status' => $snapshot->status,
                'reasons' => $snapshot->reasons,
                'computed_at' => $snapshot->computed_at?->toIso8601String(),
            ])->values();

        return response()->json([
            'schema_version' => 1,
            'scope' => [
                'bot_type' => $data['bot_type'] ?? 'quick_trade',
                'bot_instance_id' => $data['bot_instance_id'],
                'horizon_seconds' => (int) $data['horizon_seconds'],
            ],
            'snapshots' => $snapshots,
            'note' => 'Scheduled snapshots written by forecast:evaluate-readiness -- not the live on-demand computation /readiness returns.',
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Read-only two-scope comparison. Delegates to
     * ForecastModelComparisonService::compare(); builds each scope's real
     * holdout P&L summary the same way readiness() does above (never
     * fabricated -- null when no matching real trades exist). Scopes are
     * encoded as nested query-string arrays: a[bot_instance_id]=...&
     * a[horizon_seconds]=...&b[bot_instance_id]=...&b[horizon_seconds]=...
     * (plus optional a[bot_type]/a[symbol]/a[timeframe]/a[label], same for
     * b), matching the two-full-scope shape
     * ForecastModelComparisonService::compare() actually takes.
     */
    public function compare(
        Request $request,
        string $workspace,
        ForecastModelComparisonService $comparison,
        QuickTradeResearchService $research,
        ForecastMetricsService $metrics,
    ) {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'a' => 'required|array',
            'a.bot_type' => 'nullable|string|max:32',
            'a.bot_instance_id' => 'required|string|max:191',
            'a.horizon_seconds' => 'required|integer|min:5|max:3600',
            'a.symbol' => 'nullable|string|max:32',
            'a.timeframe' => 'nullable|string|max:16',
            'a.label' => 'nullable|string|max:64',
            'b' => 'required|array',
            'b.bot_type' => 'nullable|string|max:32',
            'b.bot_instance_id' => 'required|string|max:191',
            'b.horizon_seconds' => 'required|integer|min:5|max:3600',
            'b.symbol' => 'nullable|string|max:32',
            'b.timeframe' => 'nullable|string|max:16',
            'b.label' => 'nullable|string|max:64',
        ]);
        $scopeA = $this->scopeFromInput($data['a']);
        $scopeB = $this->scopeFromInput($data['b']);
        $summaryA = $this->realHoldoutPnlSummary($workspace, $data['a'], $research, $metrics);
        $summaryB = $this->realHoldoutPnlSummary($workspace, $data['b'], $research, $metrics);
        $result = $comparison->compare($scopeA, $scopeB, $summaryA, $summaryB);

        return response()->json(['schema_version' => 1] + $result)->header('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{bot_type:string, bot_instance_id:string, horizon_seconds:int, label?:string}
     */
    private function scopeFromInput(array $input): array
    {
        $scope = [
            'bot_type' => $input['bot_type'] ?? 'quick_trade',
            'bot_instance_id' => $input['bot_instance_id'],
            'horizon_seconds' => (int) $input['horizon_seconds'],
        ];
        if (! empty($input['label'])) {
            $scope['label'] = $input['label'];
        }

        return $scope;
    }

    /**
     * Real, settled, live-only holdout P&L evidence for a scope -- exactly
     * the same source data and computation summary()/index() above already
     * use (QuickTradeResearchService::report() rows filtered to
     * execution_environment==='live', fed into
     * ForecastMetricsService::summarize()). Returns null (never a
     * fabricated summary) whenever no symbol/timeframe scope is supplied or
     * no matching settled trades exist -- callers (readiness(), compare())
     * pass that straight through to the read-only services, which already
     * document null as "no real evidence yet, report NOT_VALIDATED /
     * unavailable" rather than guessing.
     */
    private function realHoldoutPnlSummary(
        string $workspace,
        array $scope,
        QuickTradeResearchService $research,
        ForecastMetricsService $metrics,
    ): ?array {
        if (empty($scope['symbol'])) {
            return null;
        }
        $trades = QuickTrade::where('workspace_id', $workspace)->where('status', '!=', 'open')
            ->whereNotNull('settled_at')
            ->where('symbol', strtoupper($scope['symbol']))
            ->when($scope['timeframe'] ?? null, fn ($q, $v) => $q->where('timeframe', $v))
            ->when($scope['horizon_seconds'] ?? null, fn ($q, $v) => $q->where('expiry_seconds', $v))
            ->when($scope['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v))
            ->orderByDesc('settled_at')->limit(1000)->get();
        if ($trades->isEmpty()) {
            return null;
        }
        $rows = $trades->map(fn ($trade) => $research->report($trade))
            ->filter(fn ($row) => $row['execution_environment'] === 'live')->values();
        if ($rows->isEmpty()) {
            return null;
        }

        return $metrics->summarize($rows);
    }
}
