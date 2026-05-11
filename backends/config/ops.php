<?php

return [
    'deployment_environment' => env('DEPLOYMENT_ENV'),
    'allow_demo_mode' => (bool) env('OPS_ALLOW_DEMO_MODE', true),
    'require_2fa_for_live' => (bool) env('OPS_REQUIRE_2FA_FOR_LIVE', true),
    'maintenance' => [
        'trend_window_days' => (int) env('OPS_MAINTENANCE_TREND_WINDOW_DAYS', 7),
        'trend_history_limit' => (int) env('OPS_MAINTENANCE_TREND_HISTORY_LIMIT', 60),
        'drift_history_limit' => (int) env('OPS_MAINTENANCE_DRIFT_HISTORY_LIMIT', 60),
        'drift_delta_threshold_pct' => (float) env('OPS_MAINTENANCE_DRIFT_DELTA_THRESHOLD_PCT', 20),
        'drift_absolute_threshold_pct' => (float) env('OPS_MAINTENANCE_DRIFT_ABSOLUTE_THRESHOLD_PCT', 5),
        'drift_triggers_incident' => (bool) env('OPS_DRIFT_TRIGGERS_INCIDENT', true),
        'confidence_history_limit' => (int) env('OPS_MAINTENANCE_CONFIDENCE_HISTORY_LIMIT', 60),
        'allow_live_chaos' => (bool) env('OPS_ALLOW_LIVE_CHAOS', false),
        'allow_live_drills' => (bool) env('OPS_ALLOW_LIVE_DRILLS', false),
        'retention_days' => [
            'trend' => (int) env('OPS_MAINTENANCE_TREND_RETENTION_DAYS', 180),
            'drift' => (int) env('OPS_MAINTENANCE_DRIFT_RETENTION_DAYS', 180),
            'drills' => (int) env('OPS_MAINTENANCE_DRILL_RETENTION_DAYS', 365),
            'releases' => (int) env('OPS_MAINTENANCE_RELEASE_RETENTION_DAYS', 365),
            'incidents' => (int) env('OPS_MAINTENANCE_INCIDENT_REVIEW_RETENTION_DAYS', 365),
        ],
    ],
    'live_readiness' => [
        'scheduler_stale_minutes' => (int) env('OPS_SCHEDULER_STALE_MINUTES', 15),
        'scheduler_block_minutes' => (int) env('OPS_SCHEDULER_BLOCK_MINUTES', 30),
        'queue_backlog_warning' => (int) env('OPS_QUEUE_BACKLOG_WARNING', 100),
        'queue_backlog_block' => (int) env('OPS_QUEUE_BACKLOG_BLOCK', 500),
        'queue_backlog_excluded_queues' => array_values(array_filter(array_map(
            static fn (string $queue) => trim($queue),
            explode(',', (string) env('OPS_QUEUE_BACKLOG_EXCLUDED_QUEUES', 'monitoring'))
        ))),
        'uncertain_order_warning' => (int) env('OPS_UNCERTAIN_ORDER_WARNING', 3),
        'uncertain_order_block' => (int) env('OPS_UNCERTAIN_ORDER_BLOCK', 10),
        'order_failure_warning' => (int) env('OPS_ORDER_FAILURE_WARNING', 3),
        'order_failure_block' => (int) env('OPS_ORDER_FAILURE_BLOCK', 5),
        'incident_warning' => (int) env('OPS_INCIDENT_WARNING', 2),
        'incident_block' => (int) env('OPS_INCIDENT_BLOCK', 1),
    ],
];
