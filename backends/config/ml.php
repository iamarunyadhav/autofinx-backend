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
    // Phase 3: long horizons only. At 15m the round-trip cost (~0.18% required
    // move) eats most of the typical BTC/ETH move (~0.15-0.21%), so 15m is no
    // longer trained or predicted; its history stays in the registry. Each
    // horizon is its own model and is never relabelled for another expiry.
    'horizons_minutes' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('AUTOFINX_ML_HORIZONS_MINUTES', '60,120,240,1440')))))),
    // Quick Trade expiries at or below this get the "costs exceed the typical
    // move" message instead of a model.
    'cost_exceeds_move_max_seconds' => (int) env('AUTOFINX_ML_COST_EXCEEDS_MOVE_MAX_SECONDS', 900),

    'funding' => [
        // Binance USDT-M funding settles every 8h (00:00/08:00/16:00 UTC).
        'interval_hours' => 8,
        'default_months' => 12,
    ],

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
        'classifier_script' => base_path('external-predictive-engine/train_side_classifier.py'),
        'analysis_script' => base_path('external-predictive-engine/analyze_signals.py'),
        // Candidate types the daily retrain trains per symbol×horizon.
        // 'side_classifier' (Phase 3) and/or 'regression' (Phase 1/2).
        'candidate_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTOFINX_ML_CANDIDATE_TYPES', 'side_classifier'))))),
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
        // fraction (0.01 = 1%) on the chronological holdout. 0 let a model
        // that was only ~0.02% better (statistical noise) become champion.
        'min_mae_improvement_vs_baseline' => (float) env('AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_BASELINE', 0.01),
        // A challenger must beat the champion's MAE on the SAME holdout by
        // this fraction AND not have a worse fee-aware net metric.
        'min_mae_improvement_vs_champion' => (float) env('AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_CHAMPION', 0.005),
        'min_holdout_rows' => (int) env('AUTOFINX_ML_MIN_HOLDOUT_ROWS', 2000),
        'min_walk_forward_folds' => 3,
        // Side classifiers are gated on a NON-overlapping fee+funding-aware
        // trade simulation, never on MAE.
        'classifier' => [
            // Minimum non-overlapping holdout trades per horizon (the holdout is
            // ~55 days: at most ~55 independent 1d trades exist).
            'min_trades' => [60 => 30, 120 => 20, 240 => 15, 1440 => 8],
            'default_min_trades' => 20,
            // Share of walk-forward folds whose non-overlapping net must be > 0.
            'min_positive_fold_share' => (float) env('AUTOFINX_ML_MIN_POSITIVE_FOLD_SHARE', 0.67),
            // A challenger's holdout net (sum of % per trade) must beat the
            // champion's on the same simulation by at least this much.
            'min_net_improvement_vs_champion_pct' => (float) env('AUTOFINX_ML_MIN_NET_IMPROVEMENT_VS_CHAMPION_PCT', 0.5),
            // Average holdout net per trade (after fees + funding) must clear
            // this, so a little extra slippage cannot erase the edge.
            'min_avg_net_per_trade_pct' => (float) env('AUTOFINX_ML_MIN_AVG_NET_PER_TRADE_PCT', 0.10),
            // One-sample t-statistic of per-trade net; 1.65 ≈ 95% one-sided.
            'min_t_stat' => (float) env('AUTOFINX_ML_MIN_T_STAT', 1.65),
        ],
    ],

    'classifier' => [
        // A side is only taken when its calibrated probability reaches this
        // floor AND its expected net (after costs + funding) is positive.
        'probability_floor' => (float) env('AUTOFINX_ML_CLASSIFIER_PROBABILITY_FLOOR', 0.55),
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
