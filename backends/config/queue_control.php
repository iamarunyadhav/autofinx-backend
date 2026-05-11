<?php

$queueCatalog = [
    'critical' => ['priority' => 100, 'purpose' => 'Kill switch, emergency exits, live safety controls'],
    'execution' => ['priority' => 90, 'purpose' => 'Order placement, exits, exchange sync'],
    'market' => ['priority' => 80, 'purpose' => 'Market data ingestion and price cache updates'],
    'signals' => ['priority' => 70, 'purpose' => 'Signal scanning and strategy decisions'],
    'strategies' => ['priority' => 68, 'purpose' => 'Legacy strategy evaluation and AI recommendation jobs'],
    'ratio_bot' => ['priority' => 66, 'purpose' => 'Ratio bot evaluation jobs'],
    'ops' => ['priority' => 60, 'purpose' => 'Operational actions and remediation jobs'],
    'monitoring' => ['priority' => 58, 'purpose' => 'Health checks and live exit monitoring'],
    'monitoring_safe' => ['priority' => 57, 'purpose' => 'Non-critical monitoring checks isolated from live exits'],
    'reconcile' => ['priority' => 56, 'purpose' => 'Order, trade, wallet, and stream reconciliation'],
    'billing' => ['priority' => 54, 'purpose' => 'Payouts and withdrawal schedule processing'],
    'intelligence' => ['priority' => 50, 'purpose' => 'AI decision cycles and context building'],
    'risk' => ['priority' => 45, 'purpose' => 'Portfolio optimizer and risk analytics'],
    'analytics' => ['priority' => 35, 'purpose' => 'Reports, backfills, non-critical calculations'],
    'copytrading' => ['priority' => 30, 'purpose' => 'Copy trading replication jobs'],
    'notifications' => ['priority' => 25, 'purpose' => 'Alerts, emails, webhooks'],
    'default' => ['priority' => 10, 'purpose' => 'Fallback queue for uncategorized jobs'],
];

$defaultConnection = env('QUEUE_CONNECTION', 'database');
$defaultQueues = implode(',', array_keys($queueCatalog));
$defaultWorkerCommand = sprintf(
    'php artisan queue:work %s --queue=%s --sleep=1 --tries=3 --timeout=120 --max-time=3600',
    $defaultConnection,
    $defaultQueues
);
$workerProfiles = [
    'worker_1_live' => [
        'label' => 'Worker 1 Live',
        'purpose' => 'Keep live execution, reconciles, and exit monitoring ahead of every other queue.',
        'queues' => ['critical', 'execution', 'reconcile', 'monitoring'],
    ],
    'worker_2_runtime' => [
        'label' => 'Worker 2 Runtime',
        'purpose' => 'Handle market, scanner, AI, and bot runtime jobs without blocking live order flow.',
        'queues' => ['market', 'signals', 'strategies', 'ratio_bot', 'ops', 'intelligence', 'risk', 'billing', 'copytrading'],
    ],
    'worker_3_background' => [
        'label' => 'Worker 3 Background',
        'purpose' => 'Drain analytics, notifications, fallback jobs, and safe monitoring work.',
        'queues' => ['analytics', 'notifications', 'default', 'monitoring_safe'],
    ],
];

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Control Dashboard
    |--------------------------------------------------------------------------
    |
    | These queues are shown in the Jobs dashboard and should match the
    | Supervisor/Horizon worker priority order used on the server.
    |
    */

    'queues' => $queueCatalog,

    'worker_command' => env(
        'QUEUE_WORKER_COMMAND',
        $defaultWorkerCommand
    ),
    'worker_profiles' => collect($workerProfiles)
        ->mapWithKeys(function (array $profile, string $key) use ($defaultConnection) {
            $queues = implode(',', $profile['queues']);

            return [
                $key => [
                    ...$profile,
                    'command' => sprintf(
                        'php artisan queue:work %s --queue=%s --sleep=1 --tries=3 --timeout=120 --max-time=3600',
                        $defaultConnection,
                        $queues
                    ),
                ],
            ];
        })
        ->all(),

    'horizon_enabled' => (bool) env('HORIZON_ENABLED', false),
    'allow_pending_clear' => (bool) env('QUEUE_DASHBOARD_ALLOW_CLEAR', true),
    'protected_queues' => array_values(array_filter(array_map(
        static fn (string $queue) => trim($queue),
        explode(',', (string) env('QUEUE_DASHBOARD_PROTECTED_QUEUES', 'critical,execution,reconcile,market,monitoring'))
    ))),
    'stale_prune' => [
        'enabled' => (bool) env('QUEUE_DASHBOARD_ALLOW_STALE_PRUNE', true),
        'older_than_minutes' => (int) env('QUEUE_DASHBOARD_STALE_PRUNE_MINUTES', 30),
        'max_delete' => (int) env('QUEUE_DASHBOARD_STALE_PRUNE_MAX_DELETE', 250),
        'safe_queues' => array_values(array_filter(array_map(
            static fn (string $queue) => trim($queue),
            explode(',', (string) env('QUEUE_DASHBOARD_STALE_PRUNE_SAFE_QUEUES', 'analytics,notifications,default,monitoring_safe'))
        ))),
        'safe_job_classes' => array_values(array_filter(array_map(
            static fn (string $job) => trim($job),
            explode(',', (string) env('QUEUE_DASHBOARD_STALE_PRUNE_SAFE_JOBS', 'App\\Jobs\\DetectStaleFeedsJob'))
        ))),
    ],
];
