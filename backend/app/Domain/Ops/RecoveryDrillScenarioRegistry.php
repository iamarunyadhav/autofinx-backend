<?php

namespace App\Domain\Ops;

class RecoveryDrillScenarioRegistry
{
    public function drills(): array
    {
        return [
            [
                'key' => 'exchange-outage-recovery',
                'title' => 'Exchange Outage Recovery',
                'category' => 'exchange',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice how the system should behave when exchange health degrades.',
                'expected' => [
                    'pause_new_submissions' => true,
                    'inspect_breakers' => true,
                    'runbook' => 'exchange-outage',
                ],
                'steps' => [
                    'Confirm breaker and kill switch visibility.',
                    'Check that new live submissions are blocked.',
                    'Review open orders and current uncertainty.',
                    'Document the recovery timing and operator actions.',
                ],
            ],
            [
                'key' => 'user-stream-disconnect-recovery',
                'title' => 'User Stream Disconnect Recovery',
                'category' => 'exchange',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice recovery when Binance user stream keepalive or websocket health fails.',
                'expected' => [
                    'refresh_sync' => true,
                    'reconcile_open_orders' => true,
                    'runbook' => 'exchange-outage',
                ],
                'steps' => [
                    'Verify user stream health and keepalive freshness.',
                    'Run a sync refresh and reconcile sweep.',
                    'Confirm no duplicate submissions occur during recovery.',
                ],
            ],
            [
                'key' => 'reconciliation-mismatch-recovery',
                'title' => 'Reconciliation Mismatch Recovery',
                'category' => 'execution',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice mismatch handling when local state and exchange truth differ.',
                'expected' => [
                    'reconcile_before_resubmit' => true,
                    'runbook' => 'reconciliation-mismatch',
                ],
                'steps' => [
                    'Inspect uncertain orders and partial fills.',
                    'Run the reconciliation sweep before retrying.',
                    'Validate that duplicate fill booking does not occur.',
                ],
            ],
            [
                'key' => 'queue-backlog-recovery',
                'title' => 'Queue Backlog Recovery',
                'category' => 'operations',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice how the system behaves when jobs are delayed or retried.',
                'expected' => [
                    'pause_non_critical' => true,
                    'runbook' => 'queue-backlog',
                ],
                'steps' => [
                    'Confirm backlog and failed jobs visibility.',
                    'Check scheduler health.',
                    'Verify live submission remains conservative during backlog.',
                ],
            ],
            [
                'key' => 'post-incident-verification',
                'title' => 'Post-Incident Verification',
                'category' => 'operations',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox', 'live'],
                'summary' => 'Practice the post-incident recovery checklist.',
                'expected' => [
                    'verify_readiness' => true,
                    'runbook' => 'post-incident-verification',
                ],
                'steps' => [
                    'Check readiness and health are back to stable.',
                    'Confirm unresolved incidents are acknowledged or closed.',
                    'Record the operator summary before resuming live flow.',
                ],
            ],
            [
                'key' => 'deployment-rollback-verification',
                'title' => 'Deployment Rollback Verification',
                'category' => 'deployment',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice rollback readiness and post-rollback verification.',
                'expected' => [
                    'verify_rollback_path' => true,
                    'runbook' => 'deployment-rollback',
                ],
                'steps' => [
                    'Check the rollback target and current version.',
                    'Ensure data migrations are understood before rollback.',
                    'Run readiness checks again after rollback.',
                ],
            ],
            [
                'key' => 'sandbox-live-config-mismatch',
                'title' => 'Sandbox / Live Config Mismatch',
                'category' => 'configuration',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'summary' => 'Practice detection of unsafe environment configuration combinations.',
                'expected' => [
                    'block_live' => true,
                    'runbook' => 'deployment-rollback',
                ],
                'steps' => [
                    'Confirm the environment classification.',
                    'Check for mixed live and sandbox credentials.',
                    'Block live progression until the mismatch is resolved.',
                ],
            ],
        ];
    }

    public function chaos(): array
    {
        return [
            [
                'key' => 'exchange-api-timeout',
                'title' => 'Exchange API Timeout',
                'category' => 'exchange',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'expected_behavior' => [
                    'block_new_live_submissions' => true,
                    'raise_warning' => true,
                ],
            ],
            [
                'key' => 'user-stream-delay',
                'title' => 'User Stream Delay',
                'category' => 'exchange',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'expected_behavior' => [
                    'degrade_readiness' => true,
                    'request_reconcile' => true,
                ],
            ],
            [
                'key' => 'duplicate-event-burst',
                'title' => 'Duplicate Event Burst',
                'category' => 'execution',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'expected_behavior' => [
                    'dedupe_events' => true,
                    'avoid_duplicate_fills' => true,
                ],
            ],
            [
                'key' => 'ai-provider-timeout',
                'title' => 'AI Provider Timeout',
                'category' => 'ai',
                'safe_environments' => ['local', 'demo', 'paper', 'sandbox'],
                'expected_behavior' => [
                    'fall_back_safely' => true,
                    'avoid_auto_mode' => true,
                ],
            ],
        ];
    }

    public function findDrill(string $key): ?array
    {
        return collect($this->drills())->firstWhere('key', $key);
    }

    public function findChaos(string $key): ?array
    {
        return collect($this->chaos())->firstWhere('key', $key);
    }
}

