<?php

/**
 * Quick Trade decision/timing/fee-economics settings.
 *
 * NOTE ON NAMING: the `quick_trade` *workspace settings group* (the
 * `settings` DB table, read by TradingModuleSettingsService::quickTradeConfig())
 * is a different, pre-existing mechanism from this file. They do not collide --
 * Laravel's config() never reads that table. The one key that is deliberately
 * workspace-overridable (`block_live_against_decision`) is mirrored into
 * quickTradeConfig()'s defaults, seeded FROM this file, so a workspace row can
 * override it while this file supplies the platform default.
 */
return [
    // ---- Part A: execution guard ---------------------------------------
    /**
     * Reject a LIVE quick-trade order that disagrees with the fee-aware
     * trade decision (WAIT, or the opposite side). Enforced server-side in
     * QuickTradeService::place() with a 422 carrying the numeric reason.
     * Paper mode is never blocked (spec: "default true in live, false in
     * paper") -- the enforcement site itself is live-only, so this single
     * flag expresses both halves of that rule.
     */
    'block_live_against_decision' => env('QUICK_TRADE_BLOCK_LIVE_AGAINST_DECISION', true),

    // ---- Part B: candle timing correctness ------------------------------
    /**
     * Decisions are computed from CLOSED candles only. The forming bar is
     * used for the live quote/current price, never for direction or the
     * indicator vote. Turning this off restores the historical behaviour
     * (forming bar included) and is provided only as an escape hatch.
     */
    'closed_candles_only' => env('QUICK_TRADE_CLOSED_CANDLES_ONLY', true),

    /**
     * Grace period after a candle close before the decision for the new
     * candle is considered computable -- absorbs exchange/feed delivery
     * jitter so the first tick after a close isn't computed from a
     * half-written bar.
     */
    'decision_close_grace_ms' => env('QUICK_TRADE_DECISION_CLOSE_GRACE_MS', 1500),

    /**
     * Cache the Quick Trade decision until the next candle close (+ grace).
     * Scoped to the Quick Trade forecast path only -- Ratio Bot / Robot
     * Trade / Future Trade call TradeForecastService directly and are
     * unaffected.
     */
    'decision_close_anchored_cache' => env('QUICK_TRADE_DECISION_CLOSE_ANCHORED_CACHE', true),

    /**
     * Trade decision becomes WAIT with gate_reason=candle_gap when the last
     * CLOSED candle is older than this multiple of the chart timeframe.
     */
    'candle_gap_timeframe_multiplier' => 1.5,

    /**
     * Live mode requires at least this many chart candles inside the trade
     * expiry (horizon_candles). A 15m chart with a 60s expiry has 1.
     */
    'min_horizon_candles' => env('QUICK_TRADE_MIN_HORIZON_CANDLES', 3),

    /**
     * !! OWNER DECISION REQUIRED -- ships OFF deliberately. !!
     *
     * Turning this on enforces `min_horizon_candles` above (3) on manual LIVE
     * placements. That is exactly what the spec asks for, and it is the right
     * rule on the evidence. It is not enabled by default here because
     * 1m chart / 60s expiry is currently the PLATFORM'S OWN DEFAULT live
     * combination (TradingModuleSettingsService::quickTradeConfig():
     * default_timeframe '1m', default_duration 60) -- 1 candle, which this
     * rule rejects. Flipping the default would therefore silently stop the
     * out-of-the-box live configuration from trading at all, which is a
     * product decision, not a code detail.
     *
     * To enable: set QUICK_TRADE_ENFORCE_MIN_HORIZON_CANDLES=true. Doing so
     * also requires updating the 37 existing tests that place a manual live
     * 1m/60s trade as the vehicle for testing something else entirely
     * (stop-loss, take-profit, leverage, margin/liquidation, fee settlement)
     * -- they need a live-valid combination such as 1m/300s. The guard itself
     * is fully implemented and unit-tested either way; only its default is
     * conservative.
     */
    'enforce_min_horizon_candles' => env('QUICK_TRADE_ENFORCE_MIN_HORIZON_CANDLES', false),

    /** Reject chart timeframe > expiry outright (both modes). */
    'reject_timeframe_above_expiry' => env('QUICK_TRADE_REJECT_TIMEFRAME_ABOVE_EXPIRY', true),

    // ---- Part C: fee economics ------------------------------------------
    /**
     * Live-mode expiry presets, longest-viable-first. The first entry is the
     * recommended combination. Paper mode keeps every configured duration.
     */
    'live_expiry_presets' => [
        ['timeframe' => '5m', 'expiry_seconds' => 3600, 'recommended' => true],
        ['timeframe' => '5m', 'expiry_seconds' => 1800, 'recommended' => false],
        ['timeframe' => '15m', 'expiry_seconds' => 3600, 'recommended' => false],
    ],

    /**
     * When false, sub-15-minute expiries are hidden/disabled in LIVE mode
     * (they are mathematically incapable of clearing the cost gate at
     * observed BTCUSDT volatility -- see the spec's §0.1 table).
     */
    'allow_short_live_expiry' => env('QUICK_TRADE_ALLOW_SHORT_LIVE_EXPIRY', false),

    /** Below this expiry a live duration counts as "short" for the rule above. */
    'short_live_expiry_threshold_seconds' => 900,

    /**
     * Cost model used for the fee-aware trade decision.
     *   taker        -- taker fee both legs + full slippage buffer (today's
     *                   real, live-verified behaviour).
     *   maker_entry  -- post-only limit entry (maker fee) + taker exit.
     *                   PAPER/SHADOW ONLY: refused in live mode until real
     *                   fill-rate data exists (see QuickTradeCostModel).
     */
    'fee_model' => env('QUICK_TRADE_FEE_MODEL', 'taker'),

    /** Maker fee rate used by the maker_entry model (Binance USDⓈ-M default). */
    'maker_fee_rate' => env('QUICK_TRADE_MAKER_FEE_RATE', 0.0002),

    /** maker_entry is never allowed to drive a live decision. */
    'allow_maker_entry_in_live' => env('QUICK_TRADE_ALLOW_MAKER_ENTRY_IN_LIVE', false),

    // ---- Part D: accuracy & confidence reliability -----------------------
    /** Minimum n before an accuracy bucket is reported rather than `insufficient`. */
    'bucket_min_samples' => 30,

    /** Minimum n before a bucket may be labelled `tradeable`. */
    'tradeable_min_samples' => 100,

    /** Minimum evaluated samples before a confidence figure stops being labelled "uncalibrated score". */
    'calibration_min_samples' => 150,
];
