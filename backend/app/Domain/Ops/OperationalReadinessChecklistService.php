<?php

namespace App\Domain\Ops;

use App\Models\OperationalReadinessSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OperationalReadinessChecklistService
{
    public function __construct(
        private readonly DeploymentReadinessService $deploymentReadiness,
        private readonly LiveReadinessService $liveReadiness,
        private readonly OperationalHealthService $operationalHealth,
        private readonly IncidentService $incidents,
    ) {
    }

    public function evaluate(string $workspaceId, bool $persist = false, ?string $actorId = null, string $source = 'manual'): array
    {
        $deployment = $this->deploymentReadiness->state($workspaceId);
        $health = $this->operationalHealth->summary($workspaceId);
        $readiness = $this->liveReadiness->state($workspaceId);
        $checklist = $this->checklist($deployment, $health, $readiness);

        $payload = [
            'workspace_id' => $workspaceId,
            'scope' => 'workspace',
            'environment' => $deployment['environment'] ?? 'paper',
            'state' => $readiness['state'],
            'severity' => $this->severity($readiness['state'], $deployment, $health),
            'summary' => $readiness['summary'],
            'reason_codes_json' => $readiness['reason_codes'] ?? [],
            'warning_codes_json' => array_values(array_unique(array_merge(
                $deployment['warnings'] ?? [],
                $health['warnings'] ?? []
            ))),
            'blocker_codes_json' => array_values(array_unique(array_merge(
                $deployment['blockers'] ?? [],
                $health['blockers'] ?? []
            ))),
            'details_json' => [
                'readiness' => $readiness,
                'deployment' => $deployment,
                'health' => $health,
                'checklist' => $checklist,
                'source' => $source,
            ],
            'health_json' => [
                'deployment' => $deployment,
                'health' => $health,
            ],
            'source_hash' => sha1(json_encode([
                'state' => $readiness['state'],
                'deployment_state' => $deployment['state'] ?? null,
                'health_state' => $health['state'] ?? null,
                'blockers' => $readiness['blockers'] ?? [],
                'warnings' => $readiness['warnings'] ?? [],
            ])),
            'checked_at' => now(),
        ];

        $snapshot = $persist ? $this->persistSnapshot($workspaceId, $payload, $source) : null;

        if ($persist) {
            $this->incidents->recordReadiness($workspaceId, $readiness, $health, $actorId);
        }

        return [
            'readiness' => $readiness,
            'deployment' => $deployment,
            'health' => $health,
            'checklist' => $checklist,
            'snapshot' => $snapshot?->fresh()?->toArray(),
        ];
    }

    public function history(string $workspaceId, int $limit = 20): array
    {
        $snapshots = OperationalReadinessSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();

        return [
            'snapshots' => $snapshots->map(fn (OperationalReadinessSnapshot $snapshot) => $snapshot->toArray())->values()->all(),
        ];
    }

    private function persistSnapshot(string $workspaceId, array $payload, string $source): OperationalReadinessSnapshot
    {
        $latest = OperationalReadinessSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('scope', 'workspace')
            ->orderByDesc('checked_at')
            ->first();

        if ($latest && $latest->source_hash === $payload['source_hash']) {
            $sameRecentSnapshot = $latest->checked_at && $latest->checked_at->gt(now()->subMinutes(10));
            if ($sameRecentSnapshot) {
                $latest->fill([
                    'checked_at' => $payload['checked_at'],
                    'summary' => $payload['summary'],
                    'reason_codes_json' => $payload['reason_codes_json'],
                    'warning_codes_json' => $payload['warning_codes_json'],
                    'blocker_codes_json' => $payload['blocker_codes_json'],
                    'details_json' => $payload['details_json'],
                    'health_json' => $payload['health_json'],
                    'source_hash' => $payload['source_hash'],
                ])->save();

                return $latest->fresh();
            }
        }

        return OperationalReadinessSnapshot::create(array_merge(
            [
                'id' => (string) Str::uuid(),
            ],
            $payload
        ));
    }

    private function checklist(array $deployment, array $health, array $readiness): array
    {
        $checks = [];

        $checks[] = $this->check(
            'environment-separation',
            in_array((string) ($deployment['environment'] ?? 'paper'), ['local', 'demo', 'paper', 'sandbox', 'live'], true),
            'Environment classification is present.',
            'Environment classification is missing.',
            'Verify deployment mode and trading settings.'
        );
        $checks[] = $this->check(
            'live-readiness',
            (bool) ($deployment['deployment_ready'] ?? false),
            'Deployment readiness checks passed.',
            'Deployment readiness is blocked.',
            'Review deployment blockers and secrets.'
        );
        $checks[] = $this->check(
            'exchange-health',
            (string) ($health['checks']['rest_health']['last_error'] ?? '') === '' && ! in_array('exchange_provider_breaker_active', (array) ($health['blockers'] ?? []), true),
            'Exchange health is acceptable.',
            'Exchange health is degraded or blocked.',
            'Check the exchange health snapshot and breaker state.'
        );
        $checks[] = $this->check(
            'market-data',
            ! in_array('stale_market_data', (array) ($health['checks']['market_data']['blockers'] ?? []), true),
            'Market data is fresh enough.',
            'Market data is stale.',
            'Inspect market feed and websocket freshness.'
        );
        $checks[] = $this->check(
            'scheduler',
            (string) ($health['checks']['scheduler']['state'] ?? 'healthy') !== 'blocked',
            'Scheduler is not blocked.',
            'Scheduler is blocked.',
            'Review cron / schedule runs.'
        );
        $checks[] = $this->check(
            'queue',
            (string) ($health['checks']['queue']['state'] ?? 'healthy') !== 'blocked',
            'Queue backlog is manageable.',
            'Queue backlog is blocked.',
            'Drain jobs and failed jobs before live enablement.'
        );
        $checks[] = $this->check(
            'incidents',
            (int) ($health['counts']['critical_incidents'] ?? 0) === 0,
            'No critical incidents are open.',
            'Critical incidents are open.',
            'Inspect incidents and linked runbooks.'
        );
        $checks[] = $this->check(
            'ai-provider',
            (string) ($health['checks']['ai']['state'] ?? 'healthy') !== 'blocked',
            'AI provider health is acceptable.',
            'AI provider is blocked.',
            'Keep AI in advisor or manual approval mode.'
        );
        $checks[] = $this->check(
            'external-context',
            (string) ($health['checks']['external_context']['state'] ?? 'healthy') !== 'blocked',
            'External context sources are acceptable.',
            'External context is blocked or unavailable.',
            'Review source health and freshness windows.'
        );

        return $checks;
    }

    private function check(string $key, bool $pass, string $passMessage, string $failMessage, string $action): array
    {
        return [
            'key' => $key,
            'state' => $pass ? 'pass' : 'fail',
            'message' => $pass ? $passMessage : $failMessage,
            'action' => $pass ? null : $action,
        ];
    }

    private function severity(string $state, array $deployment, array $health): string
    {
        if ($state === 'blocked') {
            return 'critical';
        }

        if ((string) ($health['state'] ?? 'healthy') === 'degraded' || (string) ($deployment['state'] ?? 'healthy') === 'caution') {
            return 'high';
        }

        if ($state === 'degraded') {
            return 'medium';
        }

        return 'low';
    }
}
