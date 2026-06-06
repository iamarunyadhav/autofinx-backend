<?php

return [
    'runtime' => [
        'owner' => env('AUTOFINX_RUNTIME_OWNER', 'supervisor'),
        'supervisor' => filter_var(env('AUTOFINX_SUPERVISOR_RUNTIME', true), FILTER_VALIDATE_BOOL),
    ],

    'scheduler' => [
        'enabled' => filter_var(env('AUTOFINX_SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'market_consumers' => filter_var(env('AUTOFINX_SCHEDULER_MARKET_CONSUMERS', false), FILTER_VALIDATE_BOOL),
        'binance_rest' => filter_var(env('AUTOFINX_SCHEDULER_BINANCE_REST', false), FILTER_VALIDATE_BOOL),
        'metals' => filter_var(env('AUTOFINX_SCHEDULER_METALS', false), FILTER_VALIDATE_BOOL),
    ],
];
