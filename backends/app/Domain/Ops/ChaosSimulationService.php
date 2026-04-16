<?php

namespace App\Domain\Ops;

use App\Models\ChaosSimulation;
use Illuminate\Support\Str;

class ChaosSimulationService
{
    public function __construct(
        private readonly RecoveryDrillScenarioRegistry $registry,
        private readonly OperationalHealthService $health,
        private readonly DeploymentReadinessService $deployment,
    ) {
    }

    public function scenarios(): array
    {
        return $this->registry->chaos();
    }

    public function history(string $workspaceId, int $limit = 20): array
    {
        return [
            'simulations' => ChaosSimulation::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('started_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (ChaosSimulation $simulation) => $simulation->toArray())
                ->values()
                ->all(),
        ];
    }

    public function run(string $workspaceId, string $scenarioKey, ?string $actorId = null, array $options = []): array
    {
        $scenario = $this->registry->findChaos($scenarioKey);
        if (! $scenario) {
            return ['status' => 'blocked', 'blocked_reason' => 'unknown_scenario'];
        }

        $environment = (string) $this->deployment->state($workspaceId)['environment'];
        $safeEnvironments = (array) ($scenario['safe_environments'] ?? []);
        $allowLive = (bool) config('ops.maintenance.allow_live_chaos', false);
        $acknowledged = (bool) ($options['acknowledged_risk'] ?? false);
        if (! in_array($environment, $safeEnvironments, true) && ! ($allowLive && $acknowledged)) {
            return $this->record($workspaceId, $scenario, 'blocked', 'Chaos simulation blocked outside safe environments.', $actorId, [
                'environment' => $environment,
                'acknowledged_risk' => $acknowledged,
            ]);
        }

        $health = $this->health->summary($workspaceId);
        $expected = $scenario['expected_behavior'] ?? [];
        $actual = $this->actualBehavior($scenario, $health);
        $pass = $this->evaluatePass($expected, $actual);

        return $this->record(
            $workspaceId,
            $scenario,
            $pass ? 'passed' : 'failed',
            $pass ? 'Chaos simulation behaved as expected.' : 'Chaos simulation revealed a resilience gap.',
            $actorId,
            [
                'health' => $health,
                'expected' => $expected,
                'actual' => $actual,
                'options' => $options,
            ]
        );
    }

    private function actualBehavior(array $scenario, array $health): array
    {
        $actions = [];
        if (data_get($health, 'state') === 'blocked') {
            $actions[] = 'block_new_live_submissions';
        }
        if (data_get($health, 'checks.queue.state') === 'blocked' || data_get($health, 'checks.scheduler.state') === 'blocked') {
            $actions[] = 'degrade_readiness';
        }
        if (data_get($health, 'checks.orders.state') === 'blocked') {
            $actions[] = 'request_reconcile';
        }
        if (data_get($health, 'checks.ai.state') === 'blocked') {
            $actions[] = 'fall_back_safely';
        }

        return [
            'expected_behavior' => $scenario['expected_behavior'] ?? [],
            'actions' => array_values(array_unique($actions)),
            'health_state' => $health['state'] ?? null,
        ];
    }

    private function evaluatePass(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if ($value && ! in_array($key, $actual['actions'], true)) {
                return false;
            }
        }

        return true;
    }

    private function record(string $workspaceId, array $scenario, string $status, string $summary, ?string $actorId, array $evidence): array
    {
        $simulation = ChaosSimulation::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'scenario_key' => $scenario['key'],
            'scenario_name' => $scenario['title'],
            'environment' => (string) $this->deployment->state($workspaceId)['environment'],
            'status' => $status,
            'summary' => $summary,
            'expected_behavior_json' => $evidence['expected'] ?? [],
            'actual_behavior_json' => $evidence['actual'] ?? [],
            'findings_json' => [
                'pass' => $status === 'passed',
                'scenario_category' => $scenario['category'] ?? null,
            ],
            'evidence_json' => $evidence,
            'blocked_reason' => $status === 'blocked' ? 'unsafe_environment' : null,
            'requested_by' => $actorId,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return [
            'status' => $status,
            'simulation' => $simulation->toArray(),
        ];
    }
}

