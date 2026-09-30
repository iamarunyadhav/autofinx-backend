<?php

return [
    // This training runner writes candidates into storage only. It never changes
    // the predictor's configured model directory or live trading configuration.
    'python_bin' => env('AUTOFINX_FRESH_PYTHON_BIN', base_path('external-predictive-engine/.venv/bin/python')),
    'training_timeout_seconds' => (int) env('AUTOFINX_FRESH_TRAINING_TIMEOUT_SECONDS', 600),
    // Scheduler dispatch is opt-in: a sealed dataset is immutable, and a
    // human must choose when a collecting dataset becomes a sealed snapshot.
    'automatic_training_enabled' => env('AUTOFINX_FRESH_AUTOMATIC_TRAINING_ENABLED', false),
    'training_schedule_utc' => env('AUTOFINX_FRESH_TRAINING_SCHEDULE_UTC', '02:30'),
    'training_queue' => env('AUTOFINX_FRESH_TRAINING_QUEUE', 'analytics'),
    // Conservative research assumptions, expressed in basis points. These are
    // evaluation inputs, never execution settings; actual fill costs will be
    // calibrated separately once enough verified execution evidence exists.
    'costs' => [
        'fee_bps_per_leg' => (float) env('AUTOFINX_FRESH_FEE_BPS_PER_LEG', 5),
        'slippage_bps_round_trip' => (float) env('AUTOFINX_FRESH_SLIPPAGE_BPS_ROUND_TRIP', 4),
        'spread_bps_round_trip' => (float) env('AUTOFINX_FRESH_SPREAD_BPS_ROUND_TRIP', 2),
        'funding_bps_per_8h' => (float) env('AUTOFINX_FRESH_FUNDING_BPS_PER_8H', 0),
        'minimum_edge_bps' => (float) env('AUTOFINX_FRESH_MINIMUM_EDGE_BPS', 2),
    ],
    // Read-only shadow monitoring thresholds. These never alter a registry
    // pointer, disable a model, or affect a trading decision automatically.
    'monitoring' => [
        'minimum_live_samples' => (int) env('AUTOFINX_FRESH_MINIMUM_LIVE_SAMPLES', 30),
        'minimum_live_direction_accuracy_pct' => (float) env('AUTOFINX_FRESH_MINIMUM_LIVE_DIRECTION_ACCURACY_PCT', 52),
        'maximum_accuracy_drop_from_holdout_pct' => (float) env('AUTOFINX_FRESH_MAX_ACCURACY_DROP_FROM_HOLDOUT_PCT', 10),
    ],
    'shadow_model_dir' => env('AUTOFINX_FRESH_SHADOW_MODEL_DIR', storage_path('app/prediction-fresh-shadow-models')),
    'legacy_model_dir' => env('AUTOFINX_LEGACY_PREDICTOR_MODEL_DIR', base_path('external-predictive-engine/models')),
];
