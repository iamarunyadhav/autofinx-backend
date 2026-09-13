<?php

namespace App\Domain\AuditResearch;

use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Ops\OperationalHealthService;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\AuditIncident;
use App\Models\ExchangeConnection;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotForecastBacktest;
use App\Models\RecoveryDrill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-based production-readiness scorecard. Every verdict is derived
 * from REAL rows already in this app -- never a hardcoded PASS. A category
 * is INSUFFICIENT_DATA whenever the underlying real sample is smaller than
 * the documented threshold constant for that category, rather than guessing
 * a verdict from a thin sample. The overall summary is deliberately NOT a
 * single invented percentage: it is PASS/WARNING/FAIL only when every
 * category has real evidence, and INSUFFICIENT_DATA otherwise (so a mostly-
 * empty workspace can never be shown as "production ready").
 */
class ProductionReadinessService
{
    public const VERDICT_PASS = 'PASS';
    public const VERDICT_WARNING = 'WARNING';
    public const VERDICT_FAIL = 'FAIL';
    public const VERDICT_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    /** Minimum RatioBotForecastBacktest sample_size before trusting hit_rate_pct. */
    public const MIN_PREDICTION_SAMPLE_SIZE = 30;

    /** Minimum diagnosed (AuditDecisionSnapshot) rows before judging engine/strategy/risk categories. */
    public const MIN_DIAGNOSIS_SAMPLE_SIZE = 20;

    /** Minimum RatioBotDecision rows with a known execution_status before judging the execution category. */
    public const MIN_EXECUTION_SAMPLE_SIZE = 20;

    /** Minimum audit_events rows in the trailing observability window before judging observability. */
    public const MIN_OBSERVABILITY_EVENT_SAMPLE = 10;

    public const OBSERVABILITY_WINDOW_HOURS = 24;

    public function __construct(
        private readonly OperationalHealthService $operationalHealth,
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function evaluate(string $workspaceId, ?string $botInstanceId = null): array
    {
        $categories = [
            'market_feed' => $this->marketFeed($workspaceId),
            'strategy_logic' => $this->diagnosisCategoryVerdict($workspaceId, $botInstanceId, [
                RootCauseCategory::STRATEGY_RULE,
                RootCauseCategory::DIRECTION_ERROR,
                RootCauseCategory::MARKET_ANALYSIS_ERROR,
                RootCauseCategory::FORECAST_ERROR,
            ], warnRatio: 0.10, failRatio: 0.30),
            'prediction' => $this->prediction($workspaceId, $botInstanceId),
            'decision_engine' => $this->diagnosisCategoryVerdict($workspaceId, $botInstanceId, [
                RootCauseCategory::SOFTWARE_DEFECT,
                RootCauseCategory::CONFIGURATION_ERROR,
            ], warnRatio: 0.05, failRatio: 0.15),
            'risk_controls' => $this->riskControls($workspaceId, $botInstanceId),
            'execution' => $this->execution($workspaceId, $botInstanceId),
            'queue_workers' => $this->queueWorkers(),
            'database' => $this->database(),
            'exchange_connectivity' => $this->exchangeConnectivity($workspaceId),
            'observability' => $this->observability($workspaceId, $botInstanceId),
            'incident_handling' => $this->incidentHandling($workspaceId, $botInstanceId, RootCauseCategory::ALL),
            'recovery' => $this->recovery($workspaceId),
        ];

        return [
            'workspace_id' => $workspaceId,
            'bot_instance_id' => $botInstanceId,
            'generated_at' => now()->toIso8601String(),
            'categories' => $categories,
            'overall' => $this->overall($categories),
        ];
    }

    private function overall(array $categories): string
    {
        $verdicts = array_map(fn (array $c) => $c['verdict'], $categories);

        if (in_array(self::VERDICT_INSUFFICIENT_DATA, $verdicts, true)) {
            return self::VERDICT_INSUFFICIENT_DATA;
        }

        if (in_array(self::VERDICT_FAIL, $verdicts, true)) {
            return self::VERDICT_FAIL;
        }

        if (in_array(self::VERDICT_WARNING, $verdicts, true)) {
            return self::VERDICT_WARNING;
        }

        return self::VERDICT_PASS;
    }

    private function verdict(string $verdict, string $evidence, array $extra = []): array
    {
        return array_merge(['verdict' => $verdict, 'evidence' => $evidence], $extra);
    }

    // ---- market_feed ------------------------------------------------------

    private function marketFeed(string $workspaceId): array
    {
        $summary = $this->operationalHealth->summary($workspaceId);
        $marketData = $summary['checks']['market_data'] ?? [];

        if (($marketData['last_tick_at'] ?? null) === null) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'No market tick has ever been recorded for this workspace.');
        }

        return match ($marketData['state'] ?? 'healthy') {
            'blocked' => $this->verdict(self::VERDICT_FAIL, 'Market data is stale beyond the block threshold.', $marketData),
            'healthy' => $this->verdict(self::VERDICT_PASS, 'Market data is fresh.', $marketData),
            default => $this->verdict(self::VERDICT_WARNING, 'Market data is aging toward stale.', $marketData),
        };
    }

    // ---- diagnosis-category-based categories (strategy_logic, decision_engine) ----

    private function diagnosisCategoryVerdict(string $workspaceId, ?string $botInstanceId, array $categories, float $warnRatio, float $failRatio): array
    {
        $decisionIds = $this->diagnosedDecisionIdsQuery($workspaceId, $botInstanceId);
        $total = (clone $decisionIds)->count();

        if ($total < self::MIN_DIAGNOSIS_SAMPLE_SIZE) {
            return $this->verdict(
                self::VERDICT_INSUFFICIENT_DATA,
                "Only {$total} diagnosed decision(s) recorded; need at least " . self::MIN_DIAGNOSIS_SAMPLE_SIZE . '.'
            );
        }

        $matching = (clone $decisionIds)->whereIn('diagnosis_category', $categories)->count();
        $ratio = $total > 0 ? $matching / $total : 0.0;

        $evidence = "{$matching}/{$total} diagnosed decisions (" . round($ratio * 100, 1) . '%) matched: ' . implode(', ', $categories);

        if ($ratio >= $failRatio) {
            return $this->verdict(self::VERDICT_FAIL, $evidence, ['sample_size' => $total, 'ratio' => $ratio]);
        }

        if ($ratio >= $warnRatio) {
            return $this->verdict(self::VERDICT_WARNING, $evidence, ['sample_size' => $total, 'ratio' => $ratio]);
        }

        return $this->verdict(self::VERDICT_PASS, $evidence, ['sample_size' => $total, 'ratio' => $ratio]);
    }

    private function diagnosedDecisionIdsQuery(string $workspaceId, ?string $botInstanceId)
    {
        $configIds = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->when($botInstanceId, fn ($q) => $q->where('id', $botInstanceId))
            ->pluck('id');

        $decisionIds = RatioBotDecision::query()
            ->whereIn('ratio_bot_config_id', $configIds)
            ->pluck('id');

        return AuditDecisionSnapshot::query()
            ->whereIn('ratio_bot_decision_id', $decisionIds)
            ->whereNotNull('diagnosis_category');
    }

    // ---- prediction ---------------------------------------------------------

    private function prediction(string $workspaceId, ?string $botInstanceId): array
    {
        $configIds = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->when($botInstanceId, fn ($q) => $q->where('id', $botInstanceId))
            ->pluck('id');

        $backtests = RatioBotForecastBacktest::query()
            ->whereIn('ratio_bot_config_id', $configIds)
            ->get();

        $totalSamples = (int) $backtests->sum('sample_size');

        if ($totalSamples < self::MIN_PREDICTION_SAMPLE_SIZE) {
            return $this->verdict(
                self::VERDICT_INSUFFICIENT_DATA,
                "Only {$totalSamples} forecast-backtest sample(s) recorded; need at least " . self::MIN_PREDICTION_SAMPLE_SIZE . '.'
            );
        }

        $weightedHits = 0.0;
        $weightedSamples = 0;
        foreach ($backtests as $b) {
            if ($b->hit_rate_pct !== null && (int) $b->sample_size > 0) {
                $weightedHits += (float) $b->hit_rate_pct * (int) $b->sample_size;
                $weightedSamples += (int) $b->sample_size;
            }
        }

        $avgHitRate = $weightedSamples > 0 ? $weightedHits / $weightedSamples : null;
        if ($avgHitRate === null) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'Forecast samples exist but no hit_rate_pct has been computed yet.');
        }

        $evidence = "Weighted avg hit rate {$this->fmt($avgHitRate)}% across {$weightedSamples} sample(s).";

        if ($avgHitRate < 45.0) {
            return $this->verdict(self::VERDICT_FAIL, $evidence, ['avg_hit_rate_pct' => round($avgHitRate, 2), 'sample_size' => $weightedSamples]);
        }

        if ($avgHitRate < 55.0) {
            return $this->verdict(self::VERDICT_WARNING, $evidence, ['avg_hit_rate_pct' => round($avgHitRate, 2), 'sample_size' => $weightedSamples]);
        }

        return $this->verdict(self::VERDICT_PASS, $evidence, ['avg_hit_rate_pct' => round($avgHitRate, 2), 'sample_size' => $weightedSamples]);
    }

    // ---- risk_controls --------------------------------------------------

    private function riskControls(string $workspaceId, ?string $botInstanceId): array
    {
        return $this->incidentHandling($workspaceId, $botInstanceId, [
            RootCauseCategory::RISK_BLOCK,
            RootCauseCategory::BALANCE_INSUFFICIENT,
        ], 'risk-related');
    }

    // ---- execution --------------------------------------------------------

    private function execution(string $workspaceId, ?string $botInstanceId): array
    {
        $configIds = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->when($botInstanceId, fn ($q) => $q->where('id', $botInstanceId))
            ->pluck('id');

        $decisions = RatioBotDecision::query()->whereIn('ratio_bot_config_id', $configIds);
        $total = (clone $decisions)->whereNotNull('execution_status')->count();

        if ($total < self::MIN_EXECUTION_SAMPLE_SIZE) {
            return $this->verdict(
                self::VERDICT_INSUFFICIENT_DATA,
                "Only {$total} decision(s) with a known execution_status; need at least " . self::MIN_EXECUTION_SAMPLE_SIZE . '.'
            );
        }

        $failed = (clone $decisions)->where('execution_status', RatioBotDecision::EXEC_FAILED)->count();
        $ratio = $total > 0 ? $failed / $total : 0.0;
        $evidence = "{$failed}/{$total} decisions ({$this->fmt($ratio * 100)}%) failed execution.";

        if ($ratio > 0.20) {
            return $this->verdict(self::VERDICT_FAIL, $evidence, ['sample_size' => $total, 'failure_ratio' => $ratio]);
        }

        if ($ratio > 0.05) {
            return $this->verdict(self::VERDICT_WARNING, $evidence, ['sample_size' => $total, 'failure_ratio' => $ratio]);
        }

        return $this->verdict(self::VERDICT_PASS, $evidence, ['sample_size' => $total, 'failure_ratio' => $ratio]);
    }

    // ---- queue_workers ------------------------------------------------------

    private function queueWorkers(): array
    {
        if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'jobs/failed_jobs tables are not present in this environment.');
        }

        $backlog = (int) DB::table('jobs')->count();
        $failedJobs = (int) DB::table('failed_jobs')->count();
        $evidence = "queue backlog={$backlog}, failed_jobs={$failedJobs}.";

        if ($backlog >= 500) {
            return $this->verdict(self::VERDICT_FAIL, $evidence, ['backlog' => $backlog, 'failed_jobs' => $failedJobs]);
        }

        if ($backlog >= 100 || $failedJobs > 0) {
            return $this->verdict(self::VERDICT_WARNING, $evidence, ['backlog' => $backlog, 'failed_jobs' => $failedJobs]);
        }

        return $this->verdict(self::VERDICT_PASS, $evidence, ['backlog' => $backlog, 'failed_jobs' => $failedJobs]);
    }

    // ---- database -----------------------------------------------------------

    private function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return $this->verdict(self::VERDICT_FAIL, 'Database connection failed: ' . $e->getMessage());
        }

        return $this->verdict(self::VERDICT_PASS, 'Database connection is live.');
    }

    // ---- exchange_connectivity ---------------------------------------------

    private function exchangeConnectivity(string $workspaceId): array
    {
        if ($this->breakers->isProviderOutageActive($workspaceId, 'binance')) {
            $state = $this->breakers->providerState($workspaceId, 'binance');

            return $this->verdict(self::VERDICT_FAIL, 'Binance provider outage breaker is currently active.', $state);
        }

        $activeConnections = ExchangeConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->count();

        if ($activeConnections === 0) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'No active exchange connection is configured for this workspace.');
        }

        return $this->verdict(self::VERDICT_PASS, "{$activeConnections} active exchange connection(s), no outage breaker active.");
    }

    // ---- observability ------------------------------------------------------

    private function observability(string $workspaceId, ?string $botInstanceId): array
    {
        $configIds = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->when($botInstanceId, fn ($q) => $q->where('id', $botInstanceId))
            ->pluck('id');

        $count = AuditEvent::query()
            ->whereIn('bot_instance_id', $configIds)
            ->where('occurred_at', '>=', now()->subHours(self::OBSERVABILITY_WINDOW_HOURS))
            ->count();

        if ($count === 0) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'No audit events recorded in the trailing ' . self::OBSERVABILITY_WINDOW_HOURS . 'h window.');
        }

        if ($count < self::MIN_OBSERVABILITY_EVENT_SAMPLE) {
            return $this->verdict(self::VERDICT_WARNING, "Only {$count} audit event(s) in the trailing " . self::OBSERVABILITY_WINDOW_HOURS . 'h window.', ['count' => $count]);
        }

        return $this->verdict(self::VERDICT_PASS, "{$count} audit event(s) recorded in the trailing " . self::OBSERVABILITY_WINDOW_HOURS . 'h window.', ['count' => $count]);
    }

    // ---- incident_handling --------------------------------------------------

    private function incidentHandling(string $workspaceId, ?string $botInstanceId, array $categories, string $label = ''): array
    {
        $query = AuditIncident::query()
            ->where('workspace_id', $workspaceId)
            ->when($botInstanceId, fn ($q) => $q->where('bot_instance_id', $botInstanceId))
            ->whereIn('root_cause_category', $categories);

        $total = (clone $query)->count();
        $labelPrefix = $label !== '' ? "{$label} " : '';

        if ($total === 0) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, "No {$labelPrefix}incidents have ever been recorded for this workspace.");
        }

        $persistent = (clone $query)->where('status', AuditIncident::STATUS_PERSISTENT)->count();
        if ($persistent > 0) {
            return $this->verdict(self::VERDICT_FAIL, "{$persistent} persistent {$labelPrefix}incident(s) out of {$total}.", ['total' => $total, 'persistent' => $persistent]);
        }

        $open = (clone $query)->whereIn('status', AuditIncident::OPEN_STATUSES)->count();
        if ($open > 0) {
            return $this->verdict(self::VERDICT_WARNING, "{$open} open {$labelPrefix}incident(s) out of {$total}.", ['total' => $total, 'open' => $open]);
        }

        return $this->verdict(self::VERDICT_PASS, "All {$total} {$labelPrefix}incident(s) recovered/resolved.", ['total' => $total]);
    }

    // ---- recovery -----------------------------------------------------------

    private function recovery(string $workspaceId): array
    {
        $latest = RecoveryDrill::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')
            ->first();

        if (! $latest) {
            return $this->verdict(self::VERDICT_INSUFFICIENT_DATA, 'No recovery drill has ever been run for this workspace.');
        }

        return match ($latest->status) {
            'passed' => $this->verdict(self::VERDICT_PASS, "Latest recovery drill ({$latest->scenario_key}) passed.", ['scenario_key' => $latest->scenario_key, 'started_at' => optional($latest->started_at)->toIso8601String()]),
            'failed' => $this->verdict(self::VERDICT_FAIL, "Latest recovery drill ({$latest->scenario_key}) failed.", ['scenario_key' => $latest->scenario_key]),
            default => $this->verdict(self::VERDICT_WARNING, "Latest recovery drill ({$latest->scenario_key}) was blocked/inconclusive: {$latest->status}.", ['scenario_key' => $latest->scenario_key, 'status' => $latest->status]),
        };
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.') ?: '0';
    }
}
