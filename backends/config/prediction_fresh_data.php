<?php

return [
    // This training runner writes candidates into storage only. It never changes
    // the predictor's configured model directory or live trading configuration.
    'python_bin' => env('AUTOFINX_FRESH_PYTHON_BIN', base_path('external-predictive-engine/.venv/bin/python')),
    'training_timeout_seconds' => (int) env('AUTOFINX_FRESH_TRAINING_TIMEOUT_SECONDS', 600),
    'shadow_model_dir' => env('AUTOFINX_FRESH_SHADOW_MODEL_DIR', storage_path('app/prediction-fresh-shadow-models')),
    'legacy_model_dir' => env('AUTOFINX_LEGACY_PREDICTOR_MODEL_DIR', base_path('external-predictive-engine/models')),
];
