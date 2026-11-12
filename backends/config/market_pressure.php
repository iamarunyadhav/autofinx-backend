<?php

return [
    'enabled' => filter_var(env('MARKET_PRESSURE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'auto_trade_enabled' => false,
    'stale_after_ms' => (int) env('MARKET_PRESSURE_STALE_AFTER_MS', 3000),
];
