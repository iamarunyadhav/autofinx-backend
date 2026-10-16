<?php

/*
|--------------------------------------------------------------------------
| Quick Trade - Manual SL/TP mode
|--------------------------------------------------------------------------
| EVERYTHING here defaults to OFF / most conservative. The mode needs BOTH the
| server flag (this file) AND the per-workspace flag (Setting group
| `quick_trade_manual`, key `enabled`) - same two-gate shape as Future Trade's
| live gate. Read these through config(), never raw env() (config:cache).
| See docs/quick-trade-manual/DESIGN.md.
*/

return [
    // Server-level kill switch. OFF = no route works, no scheduler entry, no guard query.
    'enabled' => filter_var(env('QUICK_TRADE_MANUAL_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Mainnet stays blocked until the owner has run docs/quick-trade-manual/TESTNET_CHECKLIST.md.
    // Testnet integrations (config_json.environment = testnet) do not need this.
    'allow_mainnet' => filter_var(env('QUICK_TRADE_MANUAL_ALLOW_MAINNET', false), FILTER_VALIDATE_BOOL),

    // OPT-IN (default OFF): allow a trade WITHOUT an exchange stop-loss. Even when on, every trade must
    // still carry an explicit per-trade opt-in + acknowledgement; a missing stop-loss is never inferred.
    // Without a stop the ONLY automatic exit is liquidation, so the caps below are stricter.
    'allow_no_stop_loss' => filter_var(env('QUICK_TRADE_MANUAL_ALLOW_NO_STOP_LOSS', false), FILTER_VALIDATE_BOOL),
    'no_stop_loss_max_leverage' => (float) env('QUICK_TRADE_MANUAL_NO_SL_MAX_LEVERAGE', 5),
    // Cross margin lets liquidation consume the whole futures wallet: refuse no-SL unless ISOLATED.
    'no_stop_loss_require_isolated' => filter_var(env('QUICK_TRADE_MANUAL_NO_SL_REQUIRE_ISOLATED', true), FILTER_VALIDATE_BOOL),

    // Symbols are limited to what the Binance Quick Trade provider already supports.
    'symbols' => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT'],

    'min_stake' => 20.0,
    'max_stake' => 500.0,
    'max_leverage' => 20.0,
    'max_open_positions' => 3,

    // Stop-loss is a PRICE-MOVEMENT percentage (not margin %). Bounds on that number.
    'min_sl_pct' => 0.1,
    'max_sl_pct' => 15.0,
    'min_trailing_pct' => 0.1,
    'max_trailing_pct' => 5.0,
    'max_trailing_activation_pct' => 100.0,
    // The stop must sit inside this share of the estimated distance to liquidation.
    'sl_liq_ratio' => 0.5,
    // MARK_PRICE avoids wick triggers; CONTRACT_PRICE is the alternative.
    'working_type' => 'MARK_PRICE',

    // Reject an open if mark moved more than this % since the price the user confirmed.
    'price_tolerance_pct' => 0.3,
    // Reject if the exchange mark price timestamp is older than this.
    'mark_max_age_seconds' => 15,

    // Entry fill resolution: how many times to re-query the order and how long to wait.
    'fill_query_attempts' => 3,
    'fill_query_sleep_ms' => 300,

    // Reconciler thresholds.
    'entry_pending_stale_seconds' => 20,
    'reconcile_unknown_after_failures' => 3,
    'reconcile_batch' => 50,

    // Fees used ONLY for previews and pre-trade estimates (real fills replace them).
    'taker_fee_rate' => 0.0005,
    'slippage_buffer_pct' => 0.04,
];
