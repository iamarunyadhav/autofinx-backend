<?php

return [
    'runtime' => [
        'owner' => env('AUTOFINX_RUNTIME_OWNER', 'supervisor'),
        'supervisor' => filter_var(env('AUTOFINX_SUPERVISOR_RUNTIME', true), FILTER_VALIDATE_BOOL),
        'require_green_for_live' => filter_var(env('AUTOFINX_REQUIRE_RUNTIME_GREEN_FOR_LIVE', false), FILTER_VALIDATE_BOOL),
        'block_duplicate_symbol_live' => filter_var(env('AUTOFINX_BLOCK_DUPLICATE_SYMBOL_LIVE', true), FILTER_VALIDATE_BOOL),
        'live_feed_max_age_seconds' => (int) env('AUTOFINX_LIVE_FEED_MAX_AGE_SECONDS', 3),
        // Frames older than this are transport backlog, not executable market
        // data. They must never refresh Redis, candles, health, or breakers.
        'live_feed_max_event_lag_ms' => (int) env('AUTOFINX_LIVE_FEED_MAX_EVENT_LAG_MS', 10000),
        'user_stream_max_age_seconds' => (int) env('AUTOFINX_USER_STREAM_MAX_AGE_SECONDS', 60),
        'queue_max_lag_seconds' => (int) env('AUTOFINX_QUEUE_MAX_LAG_SECONDS', 30),
        'price_drift_max_bps' => (float) env('AUTOFINX_PRICE_DRIFT_MAX_BPS', 10),
    ],

    'scheduler' => [
        'enabled' => filter_var(env('AUTOFINX_SCHEDULER_ENABLED', true), FILTER_VALIDATE_BOOL),
        // Default false, matching binance_rest/metals below: this must stay
        // off whenever a Supervisor daemon owns market feed consumption
        // (AUTOFINX_SUPERVISOR_RUNTIME=true), and .env.example already
        // documents false as the intended value — but an environment that
        // never sets this key explicitly used to silently get `true` here,
        // conflicting with the Supervisor-owned feed
        // (AUTOFINX_PHASE1B_ARCHITECTURE_AUDIT_2026-09-02.md §6, P3).
        'market_consumers' => filter_var(env('AUTOFINX_SCHEDULER_MARKET_CONSUMERS', false), FILTER_VALIDATE_BOOL),
        'binance_rest' => filter_var(env('AUTOFINX_SCHEDULER_BINANCE_REST', false), FILTER_VALIDATE_BOOL),
        'metals' => filter_var(env('AUTOFINX_SCHEDULER_METALS', false), FILTER_VALIDATE_BOOL),
        'robot_sessions' => filter_var(env('AUTOFINX_SCHEDULER_ROBOT_SESSIONS', true), FILTER_VALIDATE_BOOL),
        'quick_settler' => filter_var(env('AUTOFINX_SCHEDULER_QUICK_SETTLER', true), FILTER_VALIDATE_BOOL),
        'runtime_auto_repair' => filter_var(env('AUTOFINX_SCHEDULER_RUNTIME_AUTO_REPAIR', true), FILTER_VALIDATE_BOOL),
        // AUTOFINX_PHASE1B_ARCHITECTURE_AUDIT_2026-09-02.md §19: routes/console.php
        // already reads config('autofinx.scheduler.ratio_bots', true) to guard
        // ratio-bots:evaluate/reconcile, but this key was never defined here, so
        // it silently always fell back to the hardcoded default with no env var
        // able to override it — inconsistent with every sibling scheduler toggle.
        'ratio_bots' => filter_var(env('AUTOFINX_SCHEDULER_RATIO_BOTS', true), FILTER_VALIDATE_BOOL),
        // Walk-forward forecast-accuracy backtest (Multi-Window Forecast
        // Engine Part C) -- a once-daily, low-cost job, independently
        // toggleable from the per-minute ratio_bots evaluation/reconcile jobs.
        'ratio_bots_backtest' => filter_var(env('AUTOFINX_SCHEDULER_RATIO_BOTS_BACKTEST', true), FILTER_VALIDATE_BOOL),
    ],

    // RatioBrain orchestration facade -- global kill switch AND per-bot
    // ratio_brain_enabled must both be true before any bot uses it (see
    // RatioBotConfig::ratioBrainEnabled()); shadow_mode defaults true so a
    // newly-enabled bot only logs shadow decisions until manually reviewed
    // and switched off.
    'ratio_brain' => [
        'enabled' => filter_var(env('RATIO_BRAIN_ENABLED', false), FILTER_VALIDATE_BOOL),
        'shadow_mode' => filter_var(env('RATIO_BRAIN_SHADOW_MODE', true), FILTER_VALIDATE_BOOL),
    ],

    'recovery_slot' => [
        'default_allocation_pct' => (float) env('AUTOFINX_RECOVERY_SLOT_DEFAULT_ALLOCATION_PCT', 10.0),
        'default_target_pct' => (float) env('AUTOFINX_RECOVERY_SLOT_DEFAULT_TARGET_PCT', 25.0),
    ],
];
