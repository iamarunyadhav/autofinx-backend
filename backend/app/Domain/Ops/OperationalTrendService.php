<?php

namespace App\Domain\Ops;

use App\Domain\Trading\ExecutionQualityTrendService;
use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\ChaosSimulation;
use App\Models\ExternalContextSnapshot;
use App\Models\ExternalContextSourceHealth;
use App\Models\Incident;
use App\Models\OperationalDriftDetection;
use App\Models\OperationalReadinessSnapshot;
use App\Models\OperationalTrendSnapshot;
use App\Models\RecoveryDrill;
use App\Models\ReleaseRecord;
use App\Models\RollbackRecord;
use App\Models\SchedulerRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalTrendService
{
    public function __construct(
        private readonly ExecutionQualityTrendService $executionQuality,
        private readonly OperationalHealthService $health,
    ) {
    }

    public function capture(string $workspaceId, string $source = 'scheduled', ?int $windowDays = null): OperationalTrendSnapshot
    {
        $windowDays = max(1, $windowDays ?: (int) config('ops.maintenance.trend_window_days', 7));
        $summary = $this->buildSummary($workspaceId, $windowDays);

        $payload = [
            'workspace_id' => $workspaceId,
            'scope' => 'workspace',
            'state' => $summary['state'],
            'severity' => $summary['severity'],
            'window_days' => $windowDays,
            'summary' => $summary['summary'],
            'metrics_json' => $summary['metrics'],
            'evidence_json' => [
                'source' => $source,
                'execution_quality' => $summary['execution_quality'],
                'health' => $summary['health'],
                'readiness' => $summary['readiness'],
                'incidents' => $summary['incidents'],
                'ai' => $summary['ai'],
                'external_context' => $summary['external_context'],
                'drift' => $summary['drift'],
                'drills' => $summary['drills'],
                'releases' => $summary['releases'],
            ],
            'source_hash' => sha1(json_encode([
                'state' => $summary['state'],
                'severity' => $summary['severity'],
                'metrics' => $summary['metrics'],
            ])),
            'observed_at' => now(),
        ];

        $latest = OperationalTrendSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('scope', 'workspace')
            ->orderByDesc('observed_at')
            ->first();

        if ($latest && $latest->source_hash === $payload['source_hash'] && $latest->observed_at?->gt(now()->subMinutes(10))) {
            $latest->fill($payload)->save();
            return $latest->fresh();
        }

        return OperationalTrendSnapshot::create(array_merge(['id' => (string) Str::uuid()], $payload));
    }

    public function summary(string $workspaceId, int $windowDays = 30): array
    {
        $latest = OperationalTrendSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('observed_at')
            ->first();

        $previous = OperationalTrendSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('observed_at', '<', now()->subHours(12))
            ->orderByDesc('observed_at')
            ->first();

        $current = $latest?->toArray() ?? $this->buildSummary($workspaceId, $windowDays);

        return [
            'workspace_id' => $workspaceId,
            'current' => $current,
            'previous' => $previous?->toArray(),
            'changes' => $this->changes($current['metrics_json'] ?? $current['metrics'] ?? [], $previous?->metrics_json ?? []),
            'history' => $this->history($workspaceId, 20)['snapshots'],
        ];
    }

    public function history(string $workspaceId, int $limit = 20): array
    {
        return [
            'snapshots' => OperationalTrendSnapshot::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('observed_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (OperationalTrendSnapshot $snapshot) => $snapshot->toArray())
                ->values()
                ->all(),
        ];
    }

    public function prune(): array
    {
        $retention = (int) data_get(config('ops.maintenance.retention_days', []), 'trend', 180);
        if ($retention <= 0 || ! Schema::hasTable('operational_trend_snapshots')) {
            return ['deleted' => 0];
        }

        return ['deleted' => OperationalTrendSnapshot::query()->where('observed_at', '<', now()->subDays($retention))->delete()];
    }

    private function buildSummary(string $workspaceId, int $windowDays): array
    {
        $execution = $this->executionQuality->summary($workspaceId, $windowDays);
        $health = $this->health->summary($workspaceId);
        $readiness = $this->readinessSummary($workspaceId, $windowDays);
        $incidents = $this->incidentSummary($workspaceId, $windowDays);
        $ai = $this->aiSummary($workspaceId, $windowDays);
        $external = $this->externalContextSummary($workspaceId);
        $drift = $this->driftSummary($workspaceId);
        $drills = $this->drillSummary($workspaceId, $windowDays);
        $releases = $this->releaseSummary($workspaceId);

        $warnings = array_values(array_unique(array_filter(array_merge(
            $execution['warnings'] ?? [],
            $health['warnings'] ?? [],
            $readiness['warnings'] ?? [],
            $incidents['warnings'] ?? [],
            $ai['warnings'] ?? [],
            $external['warnings'] ?? [],
            $drift['warnings'] ?? [],
            $drills['warnings'] ?? [],
            $releases['warnings'] ?? [],
        ))));

        $blockers = array_values(array_unique(array_filter(array_merge(
            $execution['blockers'] ?? [],
            $health['blockers'] ?? [],
            $readiness['blockers'] ?? [],
            $incidents['blockers'] ?? [],
            $ai['blockers'] ?? [],
            $external['blockers'] ?? [],
            $drift['blockers'] ?? [],
            $drills['blockers'] ?? [],
            $releases['blockers'] ?? [],
        ))));

        $state = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy');
        $severity = match ($state) {
            'blocked' => 'critical',
            'caution' => 'medium',
            default => 'low',
        };

        return [
            'state' => $state,
            'severity' => $severity,
            'summary' => $this->summaryText($state, $execution, $health, $drift, $incidents, $external),
            'metrics' => [
                'execution' => $execution['metrics'] ?? [],
                'health' => $health,
                'readiness' => $readiness,
                'incidents' => $incidents,
                'ai' => $ai,
                'external_context' => $external,
                'drift' => $drift,
                'drills' => $drills,
                'releases' => $releases,
                'warnings' => $warnings,
                'blockers' => $blockers,
            ],
            'readiness' => $readiness,
            'health' => $health,
            'execution_quality' => $execution,
            'incidents' => $incidents,
            'ai' => $ai,
            'external_context' => $external,
            'drift' => $drift,
            'drills' => $drills,
            'releases' => $releases,
        ];
    }

    private function readinessSummary(string $workspaceId, int $windowDays): array
    {
        $snapshots = OperationalReadinessSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('checked_at')
            ->limit(max(1, $windowDays))
            ->get();

        $blocked = $snapshots->where('state', 'blocked')->count();
        $degraded = $snapshots->where('state', 'degraded')->count();
        $latest = $snapshots->first();

        return [
            'state' => $latest?->state ?? 'unknown',
            'summary' => $latest?->summary ?? 'No readiness history yet.',
            'blockers' => $latest?->blocker_codes_json ?? [],
            'warnings' => $latest?->warning_codes_json ?? [],
            'metrics' => [
                'blocked_snapshots' => $blocked,
                'degraded_snapshots' => $degraded,
                'history_count' => $snapshots->count(),
            ],
            'latest_checked_at' => $latest?->checked_at?->toIso8601String(),
        ];
    }

    private function incidentSummary(string $workspaceId, int $windowDays): array
    {
        $cutoff = now()->subDays(max(1, $windowDays));
        $incidents = Incident::query()
            ->where('workspace_id', $workspaceId)
            ->where('detected_at', '>=', $cutoff)
            ->get();

        $critical = $incidents->where('severity', 'critical')->count();
        $high = $incidents->where('severity', 'high')->count();
        $warnings = [];
        $blockers = [];

        if ($critical > 0) {
            $blockers[] = 'critical_incident_trend';
        } elseif ($high > 2) {
            $warnings[] = 'incident_frequency_high';
        } elseif ($incidents->count() > 0) {
            $warnings[] = 'incidents_present';
        }

        return [
            'state' => $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy'),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => [
                'open_incidents' => Incident::query()->where('workspace_id', $workspaceId)->whereIn('status', ['open', 'acknowledged', 'investigating'])->count(),
                'critical_incidents_30d' => $critical,
                'high_incidents_30d' => $high,
                'total_incidents_30d' => $incidents->count(),
                'recovery_time_minutes_avg' => $this->averageRecoveryTime($incidents),
            ],
        ];
    }

    private function aiSummary(string $workspaceId, int $windowDays): array
    {
        $cutoff = now()->subDays(max(1, $windowDays));
        $cycles = AiDecisionCycle::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', $cutoff)
            ->get();
        $decisions = AiDecision::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $cutoff)
            ->get();

        $failed = $cycles->whereIn('status', [AiDecisionCycle::STATUS_FAILED, AiDecisionCycle::STATUS_BLOCKED])->count();
        $pending = $decisions->where('status', AiDecision::STATUS_PENDING_APPROVAL)->count();
        $warnings = [];
        $blockers = [];

        if ($failed >= 5) {
            $blockers[] = 'ai_cycle_failure_trend';
        } elseif ($failed >= 2) {
            $warnings[] = 'ai_cycle_failures_rising';
        }

        if ($pending >= 10) {
            $warnings[] = 'ai_approval_backlog';
        }

        return [
            'state' => $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy'),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => [
                'cycles_30d' => $cycles->count(),
                'failed_cycles_30d' => $failed,
                'pending_decisions' => $pending,
                'blocked_decisions' => $decisions->where('status', AiDecision::STATUS_BLOCKED)->count(),
                'executed_decisions' => $decisions->where('status', AiDecision::STATUS_EXECUTED)->count(),
            ],
        ];
    }

    private function externalContextSummary(string $workspaceId): array
    {
        $latestSnapshot = Schema::hasTable('external_context_snapshots')
            ? ExternalContextSnapshot::query()->where('workspace_id', $workspaceId)->orderByDesc('computed_at')->first()
            : null;
        $sourceHealth = Schema::hasTable('external_context_source_health')
            ? ExternalContextSourceHealth::query()->where('workspace_id', $workspaceId)->get()
            : collect();

        $unhealthy = $sourceHealth->whereIn('state', ['unhealthy', 'blocked'])->count();
        $stale = $latestSnapshot && $latestSnapshot->computed_at ? now()->diffInMinutes($latestSnapshot->computed_at) > max(30, (int) config('intelligence.external_context.max_age_minutes', 60)) : true;
        $warnings = [];

        if ($latestSnapshot === null) {
            $warnings[] = 'external_context_unavailable';
        } elseif ($stale) {
            $warnings[] = 'external_context_stale';
        }

        if ($unhealthy > 0) {
            $warnings[] = 'external_context_source_unhealthy';
        }

        return [
            'state' => $warnings !== [] ? 'caution' : 'healthy',
            'blockers' => [],
            'warnings' => $warnings,
            'metrics' => [
                'latest_snapshot_state' => $latestSnapshot?->state,
                'latest_snapshot_age_minutes' => $latestSnapshot?->computed_at ? now()->diffInMinutes($latestSnapshot->computed_at) : null,
                'source_count' => $sourceHealth->count(),
                'unhealthy_sources' => $unhealthy,
            ],
        ];
    }

    private function driftSummary(string $workspaceId): array
    {
        $detections = OperationalDriftDetection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get();

        $critical = $detections->where('severity', 'action_required')->count();
        $warnings = [];
        $blockers = [];

        if ($critical > 0) {
            $blockers[] = 'operational_drift_blocked';
        } elseif ($detections->isNotEmpty()) {
            $warnings[] = 'operational_drift_detected';
        }

        return [
            'state' => $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy'),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => [
                'open_detections' => $detections->count(),
                'action_required' => $critical,
            ],
            'latest' => $detections->map(fn (OperationalDriftDetection $detection) => $detection->toArray())->values()->all(),
        ];
    }

    private function drillSummary(string $workspaceId, int $windowDays): array
    {
        $cutoff = now()->subDays(max(1, $windowDays));
        $drills = RecoveryDrill::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', $cutoff)
            ->orderByDesc('started_at')
            ->get();
        $simulations = ChaosSimulation::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', $cutoff)
            ->orderByDesc('started_at')
            ->get();

        $failed = $drills->whereIn('status', ['failed', 'blocked'])->count() + $simulations->whereIn('status', ['failed', 'blocked'])->count();
        $warnings = [];

        if ($failed >= 3) {
            $warnings[] = 'recovery_drill_failures';
        } elseif ($drills->isEmpty() && $simulations->isEmpty()) {
            $warnings[] = 'no_recent_recovery_practice';
        }

        return [
            'state' => $warnings !== [] ? 'caution' : 'healthy',
            'blockers' => [],
            'warnings' => $warnings,
            'metrics' => [
                'drills' => $drills->count(),
                'drill_failures' => $drills->where('status', 'failed')->count(),
                'simulations' => $simulations->count(),
                'simulation_failures' => $simulations->where('status', 'failed')->count(),
            ],
            'latest_drills' => $drills->take(5)->map(fn (RecoveryDrill $drill) => $drill->toArray())->values()->all(),
            'latest_simulations' => $simulations->take(5)->map(fn (ChaosSimulation $simulation) => $simulation->toArray())->values()->all(),
        ];
    }

    private function releaseSummary(string $workspaceId): array
    {
        $releases = ReleaseRecord::query()
            ->where(function ($query) use ($workspaceId) {
                $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            })
            ->orderByDesc('released_at')
            ->limit(10)
            ->get();
        $rollbacks = RollbackRecord::query()
            ->where(function ($query) use ($workspaceId) {
                $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            })
            ->orderByDesc('requested_at')
            ->limit(10)
            ->get();

        $warnings = [];
        if ($releases->isEmpty()) {
            $warnings[] = 'no_release_history';
        }
        if ($rollbacks->isNotEmpty()) {
            $warnings[] = 'recent_rollback_activity';
        }

        return [
            'state' => $warnings !== [] ? 'caution' : 'healthy',
            'blockers' => [],
            'warnings' => $warnings,
            'metrics' => [
                'releases' => $releases->count(),
                'verified_releases' => $releases->whereNotNull('verified_at')->count(),
                'rollbacks' => $rollbacks->count(),
                'last_release_at' => $releases->first()?->released_at?->toIso8601String(),
                'last_rollback_at' => $rollbacks->first()?->requested_at?->toIso8601String(),
            ],
            'latest_releases' => $releases->map(fn (ReleaseRecord $release) => $release->toArray())->values()->all(),
            'latest_rollbacks' => $rollbacks->map(fn (RollbackRecord $rollback) => $rollback->toArray())->values()->all(),
        ];
    }

    private function summaryText(string $state, array $execution, array $health, array $drift, array $incidents, array $external): string
    {
        if ($state === 'blocked') {
            return implode(' ', array_filter([
                'Operational maturity is blocked.',
                $execution['summary'] ?? null,
                $drift['warnings'][0] ?? null,
                $incidents['warnings'][0] ?? null,
                $external['warnings'][0] ?? null,
            ]));
        }

        if ($state === 'caution') {
            return implode(' ', array_filter([
                'Operational maturity is cautious.',
                $execution['summary'] ?? null,
                $health['warnings'][0] ?? null,
                $drift['warnings'][0] ?? null,
            ]));
        }

        return 'Operational maturity is stable across current evidence windows.';
    }

    private function changes(array $current, array $previous): array
    {
        $currentFailure = (float) data_get($current, 'execution.failure_rate_pct', 0);
        $previousFailure = (float) data_get($previous, 'execution.failure_rate_pct', 0);
        $currentLatency = (float) data_get($current, 'execution.avg_latency_ms', 0);
        $previousLatency = (float) data_get($previous, 'execution.avg_latency_ms', 0);
        $currentDrift = (int) data_get($current, 'drift.metrics.open_detections', 0);
        $previousDrift = (int) data_get($previous, 'drift.metrics.open_detections', 0);

        return [
            'failure_rate_delta_pct' => round($currentFailure - $previousFailure, 2),
            'latency_delta_ms' => round($currentLatency - $previousLatency, 2),
            'drift_delta' => $currentDrift - $previousDrift,
        ];
    }

    private function averageRecoveryTime(\Illuminate\Support\Collection $incidents): ?float
    {
        $resolved = $incidents->filter(fn (Incident $incident) => $incident->detected_at && $incident->resolved_at);
        if ($resolved->isEmpty()) {
            return null;
        }

        return round($resolved->avg(fn (Incident $incident) => $incident->detected_at->diffInMinutes($incident->resolved_at)), 2);
    }
}

