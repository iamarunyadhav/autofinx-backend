<?php

/*
|--------------------------------------------------------------------------
| Quick Trade Python ML — Phase 1 automatic shadow learning loop
|--------------------------------------------------------------------------
|
| Everything here is SHADOW ONLY. No live order path reads any value produced
| by this module: predictions are stored and displayed, never executed, and
| a model can never block or place an order. Every scheduled step is behind
| `ml.enabled` (default false) so deploying the code changes nothing until a
| human opts in.
|
*/

return [
    'enabled' => (bool) env('AUTOFINX_ML_SHADOW_ENABLED', false),

    // Hard-coded invariants surfaced in every payload. Not configurable on
    // purpose: Phase 1 has no influence/execution mode.
    'mode' => 'shadow_only',

    'provider' => 'binance_futures',
    'symbols' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTOFINX_ML_SYMBOLS', 'BTCUSDT,ETHUSDT'))))),
    // Base candle interval for features and labels. Horizons must be exact
    // multiples of it so every label lands on a real candle close.
    'base_timeframe' => '5m',
    'base_interval_seconds' => 300,
    // Live Quick Trade hides <15m expiries and the fee gate makes shorter
    // horizons non-viable. Add more here later; each is its own model.
    'horizons_minutes' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('AUTOFINX_ML_HORIZONS_MINUTES', '15,60,240')))))),

    'binance' => [
        'base_url' => env('AUTOFINX_ML_BINANCE_FUTURES_URL', env('BINANCE_FUTURES_API_URL', 'https://fapi.binance.com')),
        'page_limit' => 1000,
        'timeout_seconds' => 15,
        // Extra spacing between backfill pages on top of the shared
        // BinanceRestCircuitBreaker throttle, so a 12-month backfill never
        // competes with trading REST traffic for request weight.
        'backfill_page_sleep_ms' => (int) env('AUTOFINX_ML_BACKFILL_PAGE_SLEEP_MS', 750),
    ],

    'backfill' => [
        'default_months' => 12,
        // Maximum pages per invocation; the checkpoint makes the next run
        // resume exactly where this one stopped.
        'max_pages_per_run' => (int) env('AUTOFINX_ML_BACKFILL_MAX_PAGES', 400),
    ],

    'data' => [
        // A prediction anchored on a candle older than this is DATA_STALE.
        'max_anchor_age_seconds' => (int) env('AUTOFINX_ML_MAX_ANCHOR_AGE_SECONDS', 720),
        'sync_lookback_pages' => 2,
    ],

    'training' => [
        'python_bin' => env('AUTOFINX_ML_PYTHON_BIN', env('AUTOFINX_FRESH_PYTHON_BIN', base_path('external-predictive-engine/.venv/bin/python'))),
        'script' => base_path('external-predictive-engine/train_shadow_horizon.py'),
        'timeout_seconds' => (int) env('AUTOFINX_ML_TRAINING_TIMEOUT_SECONDS', 1800),
        // Dedicated connection/queue (config/queue.php `ml_training`) so a long
        // training run never occupies a trading or analytics worker.
        'connection' => env('AUTOFINX_ML_TRAINING_CONNECTION', 'ml_training'),
        'queue' => env('AUTOFINX_ML_TRAINING_QUEUE', 'ml_training'),
        // First model for a symbol×horizon needs at least this many mature
        // labels (~70 days of 5m anchors). Fewer => no training at all.
        'min_total_mature_labels' => (int) env('AUTOFINX_ML_MIN_TOTAL_MATURE_LABELS', 20000),
        // Retrain only when this many NEW mature labels exist past the last
        // trained window (~1 day of 5m anchors).
        'min_new_mature_labels' => (int) env('AUTOFINX_ML_MIN_NEW_MATURE_LABELS', 288),
        'max_training_rows' => (int) env('AUTOFINX_ML_MAX_TRAINING_ROWS', 150000),
        'schedule_utc' => env('AUTOFINX_ML_RETRAIN_SCHEDULE_UTC', '03:05'),
        'artifact_dir' => env('AUTOFINX_ML_ARTIFACT_DIR', storage_path('app/ml-shadow/models')),
        'export_dir' => storage_path('app/ml-shadow/exports'),
        // The python service reads the CHAMPION copies from here.
        'champion_dir' => env('AUTOFINX_ML_CHAMPION_DIR', storage_path('app/ml-shadow/champions')),
    ],

    'promotion' => [
        // A first champion must beat the zero-return baseline MAE by this
        // fraction on the chronological holdout.
        'min_mae_improvement_vs_baseline' => (float) env('AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_BASELINE', 0.0),
        // A challenger must beat the champion's MAE on the SAME holdout by
        // this fraction AND not have a worse fee-aware net metric.
        'min_mae_improvement_vs_champion' => (float) env('AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_CHAMPION', 0.005),
        'min_holdout_rows' => (int) env('AUTOFINX_ML_MIN_HOLDOUT_ROWS', 2000),
        'min_walk_forward_folds' => 3,
    ],

    'predictor' => [
        'url' => env('AUTOFINX_ML_PREDICTOR_URL', env('AUTOFINX_EXTERNAL_PREDICTOR_URL', 'http://127.0.0.1:8091')),
        'timeout_seconds' => (float) env('AUTOFINX_ML_PREDICTOR_TIMEOUT_SECONDS', 3),
    ],

    'evaluation' => [
        // Below this many scored predictions the UI shows "insufficient
        // data", never a percentage. Evaluation starts at the first Phase 1
        // prediction; no historical trade ever seeds it.
        'min_samples' => (int) env('AUTOFINX_ML_EVAL_MIN_SAMPLES', 50),
    ],

    // Execution-feedback eligibility (Step 3.5). Trades before this instant
    // were produced by earlier system versions and can at most be COST_ONLY.
    'trade_feedback_min_entry_at' => env('AUTOFINX_ML_TRADE_FEEDBACK_MIN_ENTRY_AT', '2026-09-25T13:35:00+05:30'),
];
