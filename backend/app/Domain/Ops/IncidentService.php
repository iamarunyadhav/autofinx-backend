<?php

namespace App\Domain\Ops;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentEvent;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class IncidentService
{
    public function list(string $workspaceId, array $filters = []): Collection
    {
        return Incident::query()
            ->with(['runbook'])
            ->where('workspace_id', $workspaceId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('detected_at')
            ->limit((int) ($filters['limit'] ?? 50))
            ->get();
    }

    public function find(string $incidentId): ?Incident
    {
        return Incident::query()
            ->with(['runbook', 'events', 'actions'])
            ->find($incidentId);
    }

    public function show(string $incidentId): ?Incident
    {
        return Incident::query()
            ->with(['runbook', 'events', 'actions'])
            ->find($incidentId);
    }

    public function recordReadiness(string $workspaceId, array $readiness, array $health, ?string $actorId = null): array
    {
        $snapshotIncident = $this->syncIncidentFromReadiness($workspaceId, $readiness, $health, $actorId);

        return [
            'incident' => $snapshotIncident?->load(['runbook', 'events', 'actions'])?->toArray(),
        ];
    }

    public function syncIncidentFromReadiness(string $workspaceId, array $readiness, array $health, ?string $actorId = null): ?Incident
    {
        $state = (string) ($readiness['state'] ?? 'not_ready');
        $deploymentState = (string) data_get($readiness, 'deployment.state', 'healthy');
        $healthState = (string) ($health['state'] ?? 'healthy');
        $blockers = array_values(array_unique(array_merge(
            (array) ($readiness['blockers'] ?? []),
            (array) ($health['blockers'] ?? [])
        )));
        $warnings = array_values(array_unique(array_merge(
            (array) ($readiness['warnings'] ?? []),
            (array) ($health['warnings'] ?? [])
        )));

        if ($state !== 'blocked' && $deploymentState !== 'blocked' && $healthState !== 'blocked' && $blockers === []) {
            return $this->resolveAutoIncident($workspaceId, $actorId);
        }

        $severity = $this->severityFromState($state, $blockers, $warnings, $healthState);
        $runbookKey = $this->runbookKeyFromSignals($blockers, $warnings, $readiness, $health);
        $incidentKey = 'ops-readiness:' . $workspaceId;
        $title = 'Live readiness blocked';
        $summary = implode(' | ', array_filter([
            $readiness['summary'] ?? null,
            $health['recommendations'][0] ?? null,
        ]));

        $incident = Incident::query()->where('incident_key', $incidentKey)->first();
        if (! $incident) {
            $incident = Incident::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'incident_key' => $incidentKey,
                'kind' => 'readiness_blocked',
                'severity' => $severity,
                'status' => 'open',
                'title' => $title,
                'summary' => $summary ?: 'Operational readiness checks reported blockers.',
                'runbook_key' => $runbookKey,
                'detection_source' => 'readiness_check',
                'block_live' => true,
                'affected_entities_json' => [
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'environment' => $readiness['environment'] ?? null,
                ],
                'context_json' => [
                    'readiness' => $readiness,
                    'health' => $health,
                ],
                'detected_at' => now(),
                'last_activity_at' => now(),
            ]);
        } else {
            $incident->fill([
                'severity' => $severity,
                'status' => in_array($incident->status, ['resolved', 'mitigated'], true) ? 'open' : $incident->status,
                'title' => $title,
                'summary' => $summary ?: $incident->summary,
                'runbook_key' => $runbookKey,
                'block_live' => true,
                'affected_entities_json' => [
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'environment' => $readiness['environment'] ?? null,
                ],
                'context_json' => [
                    'readiness' => $readiness,
                    'health' => $health,
                ],
                'last_activity_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
            ])->save();
        }

        $this->recordEvent($incident, 'readiness_blocked', $actorId, 'Readiness check reported a blocker.', [
            'readiness_state' => $state,
            'health_state' => $healthState,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);

        $this->recordAction($incident, 'readiness_blocked', $actorId, 'System recorded a readiness blocker.', 'Readiness blocked.', [
            'state' => $state,
            'severity' => $severity,
        ]);

        $this->notify($workspaceId, 'ops_incident', 'Readiness blocked', $incident->summary ?: $title, $severity, [
            'incident_id' => $incident->id,
            'incident_key' => $incident->incident_key,
            'runbook_key' => $incident->runbook_key,
        ]);

        return $incident->fresh();
    }

    public function acknowledge(Incident $incident, ?string $actorId, ?string $message = null): Incident
    {
        $incident->fill([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $actorId,
            'last_activity_at' => now(),
        ])->save();

        $this->recordAction($incident, 'acknowledge', $actorId, $message ?: 'Incident acknowledged.', null, []);
        $this->audit($incident, 'incident_acknowledged', $actorId, ['message' => $message]);

        return $incident->fresh();
    }

    public function resolve(Incident $incident, ?string $actorId, ?string $summary = null): Incident
    {
        $incident->fill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $actorId,
            'resolution_summary' => $summary,
            'last_activity_at' => now(),
            'block_live' => false,
        ])->save();

        $this->recordAction($incident, 'resolve', $actorId, $summary ?: 'Incident resolved.', null, []);
        $this->audit($incident, 'incident_resolved', $actorId, ['summary' => $summary]);

        $this->notify((string) $incident->workspace_id, 'ops_incident', 'Incident resolved', $incident->title, 'low', [
            'incident_id' => $incident->id,
            'incident_key' => $incident->incident_key,
        ]);

        return $incident->fresh();
    }

    public function recordEvent(Incident $incident, string $eventType, ?string $actorId, ?string $message = null, array $metadata = []): IncidentEvent
    {
        return IncidentEvent::create([
            'id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'workspace_id' => $incident->workspace_id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'message' => $message,
            'metadata_json' => $metadata,
        ]);
    }

    public function recordAction(
        Incident $incident,
        string $actionKey,
        ?string $actorId,
        ?string $message = null,
        ?string $reason = null,
        array $metadata = [],
        string $status = 'recorded',
    ): IncidentAction {
        return IncidentAction::create([
            'id' => (string) Str::uuid(),
            'incident_id' => $incident->id,
            'workspace_id' => $incident->workspace_id,
            'action_key' => $actionKey,
            'status' => $status,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'message' => $message,
            'reason' => $reason,
            'metadata_json' => $metadata,
            'executed_at' => now(),
        ]);
    }

    private function resolveAutoIncident(string $workspaceId, ?string $actorId): ?Incident
    {
        $incident = Incident::query()
            ->where('workspace_id', $workspaceId)
            ->where('incident_key', 'ops-readiness:' . $workspaceId)
            ->first();

        if (! $incident || $incident->status === 'resolved') {
            return $incident;
        }

        $incident->fill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $actorId,
            'resolution_summary' => 'Readiness recovered.',
            'block_live' => false,
            'last_activity_at' => now(),
        ])->save();

        $this->recordEvent($incident, 'readiness_recovered', $actorId, 'Readiness returned to a non-blocked state.');
        $this->recordAction($incident, 'readiness_recovered', $actorId, 'Readiness recovered.', 'Recovered.', []);
        $this->audit($incident, 'incident_resolved', $actorId, ['summary' => 'Readiness recovered.']);

        $this->notify($workspaceId, 'ops_incident', 'Readiness recovered', 'The system returned to a non-blocked readiness state.', 'low', [
            'incident_id' => $incident->id,
            'incident_key' => $incident->incident_key,
        ]);

        return $incident->fresh();
    }

    private function severityFromState(string $state, array $blockers, array $warnings, string $healthState): string
    {
        if ($state === 'blocked' || $healthState === 'blocked' || $blockers !== []) {
            return 'critical';
        }

        if ($healthState === 'degraded' || count($warnings) >= 3) {
            return 'high';
        }

        if ($warnings !== []) {
            return 'medium';
        }

        return 'low';
    }

    private function runbookKeyFromSignals(array $blockers, array $warnings, array $readiness, array $health): ?string
    {
        $checks = array_merge(
            (array) data_get($readiness, 'deployment.blockers', []),
            (array) data_get($health, 'blockers', []),
            $blockers,
            $warnings
        );

        if (in_array('kill_switch_active', $checks, true)) {
            return 'live-disable-kill-switch';
        }
        if (in_array('queue_backlog_blocked', $checks, true)) {
            return 'queue-backlog';
        }
        if (in_array('scheduler_stalled', $checks, true)) {
            return 'queue-backlog';
        }
        if (in_array('stale_market_data', $checks, true) || in_array('market_data_stale', $checks, true)) {
            return 'stale-feed';
        }
        if (in_array('external_context_snapshot_stale', $checks, true) || in_array('external_context_unavailable', $checks, true)) {
            return 'external-context-outage';
        }
        if (in_array('ai_provider_unavailable', $checks, true) || in_array('ai_provider_failures', $checks, true)) {
            return 'ai-provider-outage';
        }
        if (in_array('order_uncertainty_blocked', $checks, true) || in_array('order_failure_streak_blocked', $checks, true)) {
            return 'uncertain-execution';
        }
        if (in_array('critical_incident_open', $checks, true)) {
            return 'exchange-outage';
        }

        return 'post-incident-verification';
    }

    private function notify(string $workspaceId, string $type, string $title, string $body, string $severity, array $payload = []): Notification
    {
        return Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => $type,
            'content' => array_merge([
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
            ], $payload),
        ]);
    }

    private function audit(Incident $incident, string $action, ?string $actorId, array $metadata = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $incident->workspace_id,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'metadata' => array_merge([
                'incident_id' => $incident->id,
                'incident_key' => $incident->incident_key,
            ], $metadata),
        ]);
    }
}
