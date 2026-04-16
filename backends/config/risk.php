<?php

return [
    'kill_switch' => env('RISK_KILL_SWITCH', false),
    'max_daily_loss' => env('RISK_MAX_DAILY_LOSS', 2500), // absolute currency amount
    'max_drawdown_percent' => env('RISK_MAX_DRAWDOWN_PERCENT', 20), // percent from starting balance
    'connection_fresh_minutes' => env('RISK_CONN_FRESH_MINUTES', 15),
    'maintenance_until' => env('MAINTENANCE_UNTIL'), // ISO datetime; strategies restart when past this
    'failed_order_retry_limit' => env('FAILED_ORDER_RETRY_LIMIT', 2),
];
