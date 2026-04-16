<?php

namespace App\Domain\Ops;

class LiveReadinessService
{
    public function __construct(
        private readonly DeploymentReadinessService $deploymentReadiness,
        private readonly OperationalHealthService $operationalHealth,
    ) {
    }

    public function state(string $workspaceId): array
    {
        $deployment = $this->deploymentReadiness->state($workspaceId);
        $health = $this->operationalHealth->summary($workspaceId);

        $blockers = array_values(array_unique(array_merge(
            $deployment['blockers'] ?? [],
            $health['blockers'] ?? []
        )));
        $warnings = array_values(array_unique(array_merge(
            $deployment['warnings'] ?? [],
            $health['warnings'] ?? []
        )));

        $environment = (string) ($deployment['environment'] ?? 'paper');
        $deploymentReady = (bool) ($deployment['deployment_ready'] ?? false);
        $healthState = (string) ($health['state'] ?? 'healthy');

        if ($blockers !== [] || $deployment['state'] === 'blocked' || $healthState === 'blocked') {
            $state = 'blocked';
        } elseif (in_array($environment, ['local', 'demo', 'paper'], true)) {
            $state = $warnings !== [] ? 'degraded' : 'paper_only';
        } elseif ($environment === 'sandbox') {
            $state = $warnings !== [] ? 'degraded' : 'sandbox_ready';
        } elseif ($environment === 'live' && $deploymentReady && $healthState === 'healthy') {
            $state = 'restricted_live_ready';
        } elseif ($warnings !== [] || $healthState === 'degraded' || $deployment['state'] === 'caution') {
            $state = 'degraded';
        } else {
            $state = 'not_ready';
        }

        $liveAllowed = $state === 'restricted_live_ready';

        return [
            'workspace_id' => $workspaceId,
            'state' => $state,
            'live_allowed' => $liveAllowed,
            'deployment_ready' => $deploymentReady,
            'environment' => $environment,
            'blocked_live' => $state === 'blocked' || ! $liveAllowed,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'reason_codes' => array_values(array_unique(array_merge(
                $deployment['blockers'] ?? [],
                $deployment['warnings'] ?? [],
                $health['blockers'] ?? [],
                $health['warnings'] ?? []
            ))),
            'deployment' => $deployment,
            'health' => $health,
            'summary' => $this->summary($state, $environment, $blockers, $warnings),
            'suggested_actions' => $this->suggestedActions($state, $blockers, $warnings),
        ];
    }

    private function summary(string $state, string $environment, array $blockers, array $warnings): string
    {
        if ($state === 'blocked') {
            return 'Live trading is blocked until blockers are cleared.';
        }

        if ($state === 'restricted_live_ready') {
            return 'Restricted live mode is ready with current safeguards.';
        }

        if ($state === 'sandbox_ready') {
            return 'Sandbox environment is ready.';
        }

        if ($state === 'paper_only') {
            return 'Paper or demo operation only.';
        }

        if ($warnings !== []) {
            return 'Operational caution is active. Review warnings before increasing automation.';
        }

        return sprintf('Readiness is not yet sufficient for %s live enablement.', $environment);
    }

    private function suggestedActions(string $state, array $blockers, array $warnings): array
    {
        $actions = [];

        if ($state === 'blocked') {
            $actions[] = 'Inspect blockers and linked incidents.';
            $actions[] = 'Do not enable live submissions until the blocker list is clear.';
        } elseif ($state === 'restricted_live_ready') {
            $actions[] = 'Continue supervised operation.';
        } elseif ($state === 'sandbox_ready') {
            $actions[] = 'Use sandbox for validation before live enablement.';
        } else {
            $actions[] = 'Resolve missing configuration and unresolved warnings.';
        }

        if (in_array('queue_backlog_blocked', $blockers, true)) {
            $actions[] = 'Drain the queue before allowing live submissions.';
        }

        if (in_array('external_context_snapshot_stale', $warnings, true)) {
            $actions[] = 'Refresh external context before resuming aggressive automation.';
        }

        return array_values(array_unique($actions));
    }
}
