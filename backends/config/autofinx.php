<?php

return [
    'runtime' => [
        'owner' => env('AUTOFINX_RUNTIME_OWNER', 'supervisor'),
        'supervisor' => filter_var(env('AUTOFINX_SUPERVISOR_RUNTIME', true), FILTER_VALIDATE_BOOL),
        'require_green_for_live' => filter_var(env('AUTOFINX_REQUIRE_RUNTIME_GREEN_FOR_LIVE', false), FILTER_VALIDATE_BOOL),
        'block_duplicate_symbol_live' => filter_var(env('AUTOFINX_BLOCK_DUPLICATE_SYMBOL_LIVE', true), FILTER_VALIDATE_BOOL),
        'live_feed_max_age_seconds' => (int) env('AUTOFINX_LIVE_FEED_MAX_AGE_SECONDS', 3),
        'user_stream_max_age_seconds' => (int) env('AUTOFINX_USER_STREAM_MAX_AGE_SECONDS', 60),
        'queue_max_lag_seconds' => (int) env('AUTOFINX_QUEUE_MAX_LAG_SECONDS', 30),
        'price_drift_max_bps' => (float) env('AUTOFINX_PRICE_DRIFT_MAX_BPS', 10),
    ],

    'scheduler' => [
        'enabled' => filter_var(env('AUTOFINX_SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'market_consumers' => filter_var(env('AUTOFINX_SCHEDULER_MARKET_CONSUMERS', true), FILTER_VALIDATE_BOOL),
        'binance_rest' => filter_var(env('AUTOFINX_SCHEDULER_BINANCE_REST', false), FILTER_VALIDATE_BOOL),
        'metals' => filter_var(env('AUTOFINX_SCHEDULER_METALS', false), FILTER_VALIDATE_BOOL),
        'robot_sessions' => filter_var(env('AUTOFINX_SCHEDULER_ROBOT_SESSIONS', true), FILTER_VALIDATE_BOOL),
        'quick_settler' => filter_var(env('AUTOFINX_SCHEDULER_QUICK_SETTLER', true), FILTER_VALIDATE_BOOL),
        'runtime_auto_repair' => filter_var(env('AUTOFINX_SCHEDULER_RUNTIME_AUTO_REPAIR', true), FILTER_VALIDATE_BOOL),
    ],
];
