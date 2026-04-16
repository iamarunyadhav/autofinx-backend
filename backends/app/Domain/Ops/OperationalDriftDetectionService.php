<?php

namespace App\Domain\Ops;

use App\Models\Incident;
use App\Models\Notification;
use App\Models\OperationalDriftDetection;
use App\Models\OperationalTrendSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OperationalDriftDetectionService
{
    public function __construct(
        private readonly OperationalTrendService $trends,
        private readonly IncidentService $incidents,
    ) {
    }

    public function evaluate(string $workspaceId, ?string $actorId = null, string $source = 'scheduled'): array
    {
        $current = $this->trends->capture($workspaceId, $source);
        $previous = $this->previousSnapshot($workspaceId, $current->observed_at ?? now());
        $currentMetrics = $current->metrics_json ?? [];
        $previousMetrics = $previous?->metrics_json ?? [];

        $detections = [];
        foreach ($this->rules() as $rule) {
            $detection = $this->evaluateRule($workspaceId, $rule, $currentMetrics, $previousMetrics, $current->observed_at ?? now(), $actorId);
            if ($detection !== null) {
                $detections[] = $detection;
            }
        }

        $incident = $this->syncIncident($workspaceId, $detections, $actorId);

        return [
            'current_snapshot' => $current->toArray(),
            'previous_snapshot' => $previous?->toArray(),
            'detections' => $detections,
            'incident' => $incident?->load(['runbook', 'events', 'actions'])?->toArray(),
        ];
    }

    public function history(string $workspaceId, int $limit = 30): array
    {
        return [
            'detections' => OperationalDriftDetection::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('detected_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (OperationalDriftDetection $detection) => $detection->toArray())
                ->values()
                ->all(),
        ];
    }

    public function openDetections(string $workspaceId): array
    {
        return OperationalDriftDetection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->get()
            ->map(fn (OperationalDriftDetection $detection) => $detection->toArray())
            ->values()
            ->all();
    }

    public function prune(): array
    {
        $retention = (int) data_get(config('ops.maintenance.retention_days', []), 'drift', 180);
        if ($retention <= 0) {
            return ['deleted' => 0];
        }

        return ['deleted' => OperationalDriftDetection::query()->where('detected_at', '<', now()->subDays($retention))->delete()];
    }

    private function rules(): array
    {
        return [
            ['metric_key' => 'execution.failure_rate_pct', 'label' => 'Order failure rate', 'severity' => 'action_required', 'absolute_threshold' => 10, 'delta_threshold_pct' => 20],
            ['metric_key' => 'execution.avg_slippage_pct', 'label' => 'Execution slippage', 'severity' => 'caution', 'absolute_threshold' => 0.4, 'delta_threshold_pct' => 20],
            ['metric_key' => 'execution.avg_latency_ms', 'label' => 'Execution latency', 'severity' => 'caution', 'absolute_threshold' => 30_000, 'delta_threshold_pct' => 20],
            ['metric_key' => 'readiness.metrics.blocked_snapshots', 'label' => 'Blocked readiness snapshots', 'severity' => 'action_required', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
            ['metric_key' => 'incidents.metrics.critical_incidents_30d', 'label' => 'Critical incident frequency', 'severity' => 'action_required', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
            ['metric_key' => 'ai.metrics.failed_cycles_30d', 'label' => 'AI cycle failures', 'severity' => 'caution', 'absolute_threshold' => 2, 'delta_threshold_pct' => 20],
            ['metric_key' => 'external_context.metrics.unhealthy_sources', 'label' => 'External context sources', 'severity' => 'caution', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
            ['metric_key' => 'drift.metrics.open_detections', 'label' => 'Open drift detections', 'severity' => 'action_required', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
            ['metric_key' => 'drills.metrics.simulation_failures', 'label' => 'Chaos simulation failures', 'severity' => 'caution', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
            ['metric_key' => 'releases.metrics.rollbacks', 'label' => 'Rollback activity', 'severity' => 'caution', 'absolute_threshold' => 1, 'delta_threshold_pct' => 1],
        ];
    }

    private function evaluateRule(string $workspaceId, array $rule, array $current, array $previous, Carbon $detectedAt, ?string $actorId): ?array
    {
        $metricKey = $rule['metric_key'];
        $currentValue = (float) data_get($current, $metricKey, 0);
        $previousValue = (float) data_get($previous, $metricKey, 0);
        $delta = $currentValue - $previousValue;
        $deltaPct = $previousValue > 0 ? (($delta / $previousValue) * 100) : ($currentValue > 0 ? 100 : 0);
        $absoluteThreshold = (float) $rule['absolute_threshold'];
        $deltaThreshold = (float) $rule['delta_threshold_pct'];

        $shouldFlag = $currentValue >= $absoluteThreshold && ($delta >= 0 || $deltaPct >= $deltaThreshold);
        if (! $shouldFlag) {
            return null;
        }

        $severity = $rule['severity'] === 'action_required' || $currentValue >= ($absoluteThreshold * 2) ? 'action_required' : 'caution';
        $state = $severity === 'action_required' ? 'blocked' : 'caution';
        $summary = sprintf(
            '%s drifted to %.2f from %.2f across the comparison window.',
            $rule['label'],
            $currentValue,
            $previousValue
        );
        $sourceHash = sha1(json_encode([$workspaceId, $metricKey, round($currentValue, 4), round($previousValue, 4), $severity, $state]));

        $detection = OperationalDriftDetection::query()
            ->where('workspace_id', $workspaceId)
            ->where('metric_key', $metricKey)
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->first();

        if ($detection && $detection->source_hash === $sourceHash) {
            $detection->fill([
                'state' => $state,
                'severity' => $severity,
                'summary' => $summary,
                'details_json' => [
                    'metric_key' => $metricKey,
                    'label' => $rule['label'],
                    'current' => $currentValue,
                    'previous' => $previousValue,
                    'delta' => $delta,
                    'delta_pct' => round($deltaPct, 2),
                    'thresholds' => [
                        'absolute' => $absoluteThreshold,
                        'delta_pct' => $deltaThreshold,
                    ],
                ],
                'evidence_json' => [
                    'current_snapshot' => $current,
                    'previous_snapshot' => $previous,
                ],
                'last_seen_at' => $detectedAt,
            ])->save();

            return $detection->fresh()->toArray();
        }

        $fresh = OperationalDriftDetection::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'metric_key' => $metricKey,
            'state' => $state,
            'severity' => $severity,
            'status' => 'open',
            'summary' => $summary,
            'details_json' => [
                'metric_key' => $metricKey,
                'label' => $rule['label'],
                'current' => $currentValue,
                'previous' => $previousValue,
                'delta' => $delta,
                'delta_pct' => round($deltaPct, 2),
                'thresholds' => [
                    'absolute' => $absoluteThreshold,
                    'delta_pct' => $deltaThreshold,
                ],
            ],
            'evidence_json' => [
                'current_snapshot' => $current,
                'previous_snapshot' => $previous,
            ],
            'source_hash' => $sourceHash,
            'detected_at' => $detectedAt,
            'last_seen_at' => $detectedAt,
            'created_by' => $actorId,
        ]);

        $this->notify($workspaceId, $severity === 'action_required' ? 'critical' : 'warning', 'Operational drift detected', $summary, [
            'metric_key' => $metricKey,
            'detection_id' => $fresh->id,
        ]);

        return $fresh->toArray();
    }

    private function previousSnapshot(string $workspaceId, Carbon $before): ?OperationalTrendSnapshot
    {
        return OperationalTrendSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('observed_at', '<', $before->copy()->subHours(12))
            ->orderByDesc('observed_at')
            ->first();
    }

    private function syncIncident(string $workspaceId, array $detections, ?string $actorId): ?Incident
    {
        if ($detections === []) {
            return $this->resolveIncident($workspaceId, $actorId);
        }

        if (! (bool) config('ops.maintenance.drift_triggers_incident', true)) {
            return null;
        }

        $critical = collect($detections)->where('severity', 'action_required')->count();
        $summary = collect($detections)->pluck('summary')->filter()->take(3)->implode(' | ');
        $incident = Incident::query()->where('incident_key', 'ops-drift:' . $workspaceId)->first();
        $severity = $critical > 0 ? 'critical' : 'high';

        if (! $incident) {
            $incident = Incident::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'incident_key' => 'ops-drift:' . $workspaceId,
                'kind' => 'operational_drift',
                'severity' => $severity,
                'status' => 'open',
                'title' => 'Operational drift detected',
                'summary' => $summary ?: 'Operational drift requires operator review.',
                'runbook_key' => 'drift-detection',
                'detection_source' => 'trend_analysis',
                'block_live' => $critical > 0,
                'affected_entities_json' => ['detections' => $detections],
                'context_json' => ['detections' => $detections],
                'detected_at' => now(),
                'last_activity_at' => now(),
            ]);
        } else {
            $incident->fill([
                'severity' => $severity,
                'status' => in_array($incident->status, ['resolved', 'mitigated'], true) ? 'open' : $incident->status,
                'summary' => $summary ?: $incident->summary,
                'runbook_key' => 'drift-detection',
                'block_live' => $critical > 0,
                'affected_entities_json' => ['detections' => $detections],
                'context_json' => ['detections' => $detections],
                'last_activity_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
            ])->save();
        }

        $this->incidents->recordEvent($incident, 'drift_detected', $actorId, 'Operational drift was detected.', [
            'detections' => $detections,
        ]);
        $this->incidents->recordAction($incident, 'drift_detected', $actorId, 'Operational drift recorded.', 'Review drift evidence.', [
            'detections' => $detections,
        ]);

        return $incident->fresh();
    }

    private function resolveIncident(string $workspaceId, ?string $actorId): ?Incident
    {
        $incident = Incident::query()
            ->where('incident_key', 'ops-drift:' . $workspaceId)
            ->first();

        if (! $incident || $incident->status === 'resolved') {
            return $incident;
        }

        $incident->fill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $actorId,
            'resolution_summary' => 'Operational drift was cleared.',
            'block_live' => false,
            'last_activity_at' => now(),
        ])->save();

        $this->incidents->recordEvent($incident, 'drift_cleared', $actorId, 'Operational drift cleared.');
        $this->incidents->recordAction($incident, 'drift_cleared', $actorId, 'Operational drift cleared.', 'Recovered.', []);

        return $incident->fresh();
    }

    private function notify(string $workspaceId, string $severity, string $title, string $body, array $payload = []): Notification
    {
        return Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => 'ops_drift',
            'content' => array_merge([
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
            ], $payload),
        ]);
    }
}
