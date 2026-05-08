<?php

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

    'queues' => [
        'critical' => ['priority' => 100, 'purpose' => 'Kill switch, emergency exits, live safety controls'],
        'execution' => ['priority' => 90, 'purpose' => 'Order placement, exits, exchange sync'],
        'market' => ['priority' => 80, 'purpose' => 'Market data ingestion and price cache updates'],
        'signals' => ['priority' => 70, 'purpose' => 'Signal scanning and strategy decisions'],
        'ops' => ['priority' => 60, 'purpose' => 'Reconciliation, readiness checks, incident automation'],
        'intelligence' => ['priority' => 50, 'purpose' => 'AI decision cycles and context building'],
        'risk' => ['priority' => 45, 'purpose' => 'Portfolio optimizer and risk analytics'],
        'analytics' => ['priority' => 35, 'purpose' => 'Reports, backfills, non-critical calculations'],
        'notifications' => ['priority' => 25, 'purpose' => 'Alerts, emails, webhooks'],
        'default' => ['priority' => 10, 'purpose' => 'Fallback queue for uncategorized jobs'],
    ],

    'worker_command' => env(
        'QUEUE_WORKER_COMMAND',
        'php artisan queue:work redis --queue=critical,execution,market,signals,ops,intelligence,risk,analytics,notifications,default --sleep=1 --tries=3 --timeout=120 --max-time=3600'
    ),

    'horizon_enabled' => (bool) env('HORIZON_ENABLED', false),
    'allow_pending_clear' => (bool) env('QUEUE_DASHBOARD_ALLOW_CLEAR', true),
];
