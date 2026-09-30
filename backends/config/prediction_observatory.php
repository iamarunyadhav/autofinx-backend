<?php

return [
    'enabled' => env('AUTOFINX_PREDICTION_AUDIT_ENABLED', true),
    'high_resolution_audit_enabled' => env('AUTOFINX_PREDICTION_HIGH_RES_AUDIT_ENABLED', true),
    'providers' => [
        'legacy_autofinx' => ['enabled' => env('AUTOFINX_FORECAST_LEGACY_ENABLED', true), 'influence' => env('AUTOFINX_FORECAST_LEGACY_INFLUENCE', false)],
        'market_structure' => ['enabled' => env('AUTOFINX_FORECAST_MARKET_STRUCTURE_ENABLED', true), 'influence' => env('AUTOFINX_FORECAST_MARKET_STRUCTURE_INFLUENCE', false)],
        'external_ml' => ['enabled' => env('AUTOFINX_FORECAST_EXTERNAL_ML_ENABLED', false), 'influence' => env('AUTOFINX_FORECAST_EXTERNAL_ML_INFLUENCE', false), 'url' => env('AUTOFINX_EXTERNAL_PREDICTOR_URL', 'http://127.0.0.1:8091'), 'timeout_seconds' => (int) env('AUTOFINX_EXTERNAL_PREDICTOR_TIMEOUT_SECONDS', 2)],
        'pine_strategy' => ['enabled' => env('AUTOFINX_FORECAST_PINE_ENABLED', false), 'influence' => env('AUTOFINX_FORECAST_PINE_INFLUENCE', false)],
        'weighted_fusion' => ['enabled' => env('AUTOFINX_FORECAST_WEIGHTED_FUSION_ENABLED', true), 'influence' => env('AUTOFINX_FORECAST_WEIGHTED_FUSION_INFLUENCE', false)],
    ],
    // Binance-native intervals include 1m, 5m, 15m, 30m, 1h, 2h, 4h and 1d.
    // Derived intervals are assembled from fully closed source bars; they are
    // never sent to Binance as if they were native intervals.
    'horizons' => [60, 300, 600, 900, 1800, 3600, 7200, 14400, 86400],
    'timeframes' => ['1m', '5m', '15m', '30m', '1h', '2h', '4h', '1d'],
    'derived_timeframes' => [
        '10m' => ['source' => '5m', 'bars' => 2],
        '45m' => ['source' => '15m', 'bars' => 3],
    ],
];
