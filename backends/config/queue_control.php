<?php

$queueCatalog = [
    'critical' => ['priority' => 100, 'purpose' => 'Kill switch, emergency exits, live safety controls'],
    'execution' => ['priority' => 90, 'purpose' => 'Order placement, exits, exchange sync'],
    'market' => ['priority' => 80, 'purpose' => 'Market data ingestion and price cache updates'],
    'signals' => ['priority' => 70, 'purpose' => 'Signal scanning and strategy decisions'],
    'strategies' => ['priority' => 68, 'purpose' => 'Legacy strategy evaluation and AI recommendation jobs'],
    'ratio_bot' => ['priority' => 66, 'purpose' => 'Ratio bot evaluation jobs'],
    'ops' => ['priority' => 60, 'purpose' => 'Operational actions and remediation jobs'],
    'monitoring' => ['priority' => 58, 'purpose' => 'Health checks, stale feed detection, exit monitoring'],
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

    'horizon_enabled' => (bool) env('HORIZON_ENABLED', false),
    'allow_pending_clear' => (bool) env('QUEUE_DASHBOARD_ALLOW_CLEAR', true),
];
