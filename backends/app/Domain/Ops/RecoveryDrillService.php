<?php

namespace App\Domain\Ops;

use App\Models\Incident;
use App\Models\PostIncidentReview;
use App\Models\RecoveryDrill;
use Illuminate\Support\Str;

class RecoveryDrillService
{
    public function __construct(
        private readonly RecoveryDrillScenarioRegistry $registry,
        private readonly RecoveryDrillResultRecorder $recorder,
        private readonly OperationalHealthService $health,
        private readonly OperationalReadinessChecklistService $checklist,
    ) {
    }

    public function scenarios(): array
    {
        return $this->registry->drills();
    }

    public function history(string $workspaceId, int $limit = 20): array
    {
        return [
            'drills' => RecoveryDrill::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('started_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (RecoveryDrill $drill) => $drill->toArray())
                ->values()
                ->all(),
        ];
    }

    public function reviews(string $workspaceId, int $limit = 20): array
    {
        return [
            'reviews' => PostIncidentReview::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('reviewed_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (PostIncidentReview $review) => $review->toArray())
                ->values()
                ->all(),
        ];
    }

    public function run(string $workspaceId, string $scenarioKey, ?string $actorId = null, array $options = []): array
    {
        $scenario = $this->registry->findDrill($scenarioKey);
        if (! $scenario) {
            return ['status' => 'blocked', 'blocked_reason' => 'unknown_scenario'];
        }

        $deploymentEnvironment = $this->deploymentEnvironment($workspaceId);
        $safeEnvironments = (array) ($scenario['safe_environments'] ?? []);
        $allowLive = (bool) config('ops.maintenance.allow_live_drills', false);
        $requestedLive = (bool) ($options['allow_live'] ?? false);
        if (! in_array($deploymentEnvironment, $safeEnvironments, true) && ! ($allowLive && $requestedLive)) {
            return $this->record($workspaceId, $scenario, 'blocked', 'Drill blocked outside safe environments.', $actorId, [
                'workspace_environment' => $deploymentEnvironment,
                'allow_live' => $allowLive,
                'requested_live' => $requestedLive,
            ]);
        }

        $readiness = $this->checklist->evaluate($workspaceId, false);
        $health = $this->health->summary($workspaceId);
        $expected = $scenario['expected'] ?? [];
        $actual = $this->actualOutcome($scenario, $readiness, $health);
        $pass = $this->evaluatePass($expected, $actual, $readiness, $health);

        return $this->record(
            $workspaceId,
            $scenario,
            $pass ? 'passed' : 'failed',
            $pass ? 'Recovery drill passed.' : 'Recovery drill exposed a maturity gap.',
            $actorId,
            [
                'readiness' => $readiness,
                'health' => $health,
                'expected' => $expected,
                'actual' => $actual,
                'options' => $options,
            ]
        );
    }

    public function recordReview(string $workspaceId, string $incidentId, array $data, ?string $actorId = null): array
    {
        $incident = Incident::query()->findOrFail($incidentId);
        abort_unless((string) $incident->workspace_id === (string) $workspaceId, 404);

        $existing = PostIncidentReview::query()->where('incident_id', $incident->id)->first();
        $payload = [
            'id' => $existing?->id ?? (string) Str::uuid(),
            'incident_id' => $incident->id,
            'workspace_id' => $workspaceId,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
            'root_cause_hypothesis' => $data['root_cause_hypothesis'] ?? null,
            'contributing_factors_json' => $data['contributing_factors_json'] ?? [],
            'detection_summary' => $data['detection_summary'] ?? null,
            'mitigation_summary' => $data['mitigation_summary'] ?? null,
            'lessons_learned' => $data['lessons_learned'] ?? null,
            'follow_up_actions_json' => $data['follow_up_actions_json'] ?? [],
            'evidence_json' => $data['evidence_json'] ?? [],
            'source_hash' => sha1(json_encode([
                'incident_id' => $incident->id,
                'root_cause_hypothesis' => $data['root_cause_hypothesis'] ?? null,
                'lessons_learned' => $data['lessons_learned'] ?? null,
            ])),
        ];

        if ($existing) {
            $existing->fill($payload)->save();
            $review = $existing->fresh();
        } else {
            $review = PostIncidentReview::create($payload);
        }

        return ['review' => $review->toArray()];
    }

    private function deploymentEnvironment(string $workspaceId): string
    {
        return (string) (app(DeploymentReadinessService::class)->state($workspaceId)['environment'] ?? 'paper');
    }

    private function actualOutcome(array $scenario, array $readiness, array $health): array
    {
        return [
            'runbook' => $scenario['expected']['runbook'] ?? null,
            'readiness_state' => data_get($readiness, 'readiness.state', data_get($readiness, 'state')),
            'health_state' => $health['state'] ?? null,
            'actions' => array_values(array_filter([
                data_get($scenario, 'expected.pause_new_submissions') ? 'pause_new_submissions' : null,
                data_get($scenario, 'expected.refresh_sync') ? 'refresh_sync' : null,
                data_get($scenario, 'expected.reconcile_before_resubmit') ? 'reconcile_before_resubmit' : null,
                data_get($scenario, 'expected.verify_readiness') ? 'verify_readiness' : null,
                data_get($scenario, 'expected.block_live') ? 'block_live' : null,
            ])),
        ];
    }

    private function evaluatePass(array $expected, array $actual, array $readiness, array $health): bool
    {
        if ($expected === []) {
            return true;
        }

        if (($expected['runbook'] ?? null) !== null && ($actual['runbook'] ?? null) !== ($expected['runbook'] ?? null)) {
            return false;
        }

        if (($expected['pause_new_submissions'] ?? false) && ! in_array('pause_new_submissions', $actual['actions'], true)) {
            return false;
        }

        if (($expected['refresh_sync'] ?? false) && ! in_array('refresh_sync', $actual['actions'], true)) {
            return false;
        }

        if (($expected['reconcile_before_resubmit'] ?? false) && ! in_array('reconcile_before_resubmit', $actual['actions'], true)) {
            return false;
        }

        if (($expected['verify_readiness'] ?? false) && ! in_array('verify_readiness', $actual['actions'], true)) {
            return false;
        }

        if (($expected['block_live'] ?? false) && ! in_array('block_live', $actual['actions'], true)) {
            return false;
        }

        return (string) ($health['state'] ?? 'healthy') !== 'blocked' || (string) data_get($readiness, 'readiness.state', 'healthy') === 'blocked';
    }

    private function record(string $workspaceId, array $scenario, string $status, string $summary, ?string $actorId, array $evidence): array
    {
        $record = $this->recorder->record([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'scenario_key' => $scenario['key'],
            'scenario_name' => $scenario['title'],
            'environment' => $this->deploymentEnvironment($workspaceId),
            'status' => $status,
            'summary' => $summary,
            'actions_json' => $scenario['steps'] ?? [],
            'findings_json' => [
                'expected' => $scenario['expected'] ?? [],
                'pass' => $status === 'passed',
            ],
            'recommendations_json' => $status === 'passed' ? [] : [
                'Review the scenario evidence and update the runbook or automation gap.',
            ],
            'evidence_json' => $evidence,
            'blocked_reason' => $status === 'blocked' ? 'unsafe_environment' : null,
            'performed_by' => $actorId,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return [
            'status' => $status,
            'drill' => $record->toArray(),
        ];
    }
}
