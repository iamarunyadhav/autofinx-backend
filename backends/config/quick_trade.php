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
     * `closed_candles_only` above trimmed the forming bar from forecast()'s
     * own candles, but the INDICATOR VOTE fed into it (SignalPreviewService::
     * preview(), the 25% signal weight) was still computed on candles that
     * included the forming bar -- at /quick-trade/chart (whose
     * signal_preview the page sends back as the forecast `signal`) and at
     * live placement (the vote behind the A5 guard's research forecast).
     * When true, both votes are computed on CandleCloseResolver's closed
     * candles. The chart's `candles` series is untouched (the forming bar is
     * still drawn). The trim itself is CandleCloseResolver's, so it is also
     * a no-op while `closed_candles_only` is false.
     */
    'indicator_vote_closed_candles_only' => env('QUICK_TRADE_INDICATOR_VOTE_CLOSED_CANDLES_ONLY', true),

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
     * !! OWNER DECISION MADE (2026-09-24): ships ON. !!
     *
     * Enforces `min_horizon_candles` above (3) on manual LIVE placements.
     * This used to ship OFF because 1m chart / 60s expiry was the
     * PLATFORM'S OWN DEFAULT live combination (1 candle, which this rule
     * rejects) -- flipping the default without also fixing that would have
     * silently stopped the out-of-the-box live configuration from trading
     * at all. Both are now fixed together:
     *   - `TradingModuleSettingsService::quickTradeConfig()` now has a
     *     LIVE-only fallback (`default_live_timeframe` '5m',
     *     `default_live_duration` 3600s = 12 candles, matching the
     *     recommended `live_expiry_presets` entry below) that
     *     `QuickTradeService::place()` uses whenever a manual live
     *     placement omits both fields. PAPER mode's fallback (1m/60s) and
     *     Robot Trade's own separate fallback are untouched.
     *   - Every existing test that used a manual live 1m/60s trade purely
     *     as a vehicle for testing something else (stop-loss, take-profit,
     *     leverage, margin/liquidation, fee settlement) was moved to a
     *     live-valid combination (1m/300s = 5 candles).
     *
     * Production sets QUICK_TRADE_ENFORCE_MIN_HORIZON_CANDLES=true via
     * .env; this default matches that so local dev/test runs the same rule
     * production does. The guard itself has dedicated coverage in
     * QuickTradeDecisionExecutionGuardTest regardless of this default.
     */
    'enforce_min_horizon_candles' => env('QUICK_TRADE_ENFORCE_MIN_HORIZON_CANDLES', true),

    /** Reject chart timeframe > expiry outright (both modes). */
    'reject_timeframe_above_expiry' => env('QUICK_TRADE_REJECT_TIMEFRAME_ABOVE_EXPIRY', true),

    // ---- Gap-checklist N4: stop-loss on long live expiries ---------------
    /**
     * A LIVE quick trade whose expiry is at or above this many seconds is a
     * long, unattended position. The UI ALWAYS shows a warning when such a
     * trade is about to be placed with Stop Loss off (published to the
     * frontend via TradingModuleSettingsService::metadata()). No value is
     * ever auto-filled -- the user sets their own stop.
     */
    'require_stop_loss_min_expiry_seconds' => (int) env('QUICK_TRADE_REQUIRE_STOP_LOSS_MIN_EXPIRY_SECONDS', 1800),

    /**
     * When true, QuickTradeService::place() REJECTS (422) a manual live
     * placement at/above the expiry above that has no stop_loss_pct. Ships
     * OFF: the warning above is the default behaviour; enforcement is an
     * owner opt-in. Robot Trade's programmatic placements are not affected.
     */
    'enforce_stop_loss_min_expiry' => env('QUICK_TRADE_ENFORCE_STOP_LOSS_MIN_EXPIRY', false),

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

    /**
     * LIVE Binance only: price the fee-aware decision off the connected
     * account's real USDⓈ-M taker commission (signed GET
     * /fapi/v1/commissionRate, cached 1h per integration+symbol) instead of
     * `trading.binance_futures_taker_fee_rate`. Any lookup failure falls back
     * to the config rate; `fee_rate_source` says which was used. Applies to
     * the /quick-trade/forecast preview (when the request names a workspace
     * the caller belongs to) and the placement-time decision behind the A5
     * guard. Ships OFF: it has not been verified against a real account.
     */
    'use_account_fee_rate' => env('QUICK_TRADE_USE_ACCOUNT_FEE_RATE', false),

    // ---- Part D: accuracy & confidence reliability -----------------------
    /** Minimum n before an accuracy bucket is reported rather than `insufficient`. */
    'bucket_min_samples' => 30,

    /** Minimum n before a bucket may be labelled `tradeable`. */
    'tradeable_min_samples' => 100,

    /** Minimum evaluated samples before a confidence figure stops being labelled "uncalibrated score". */
    'calibration_min_samples' => 150,

    // ---- Phase 1 item 4: untraded WAIT shadow records --------------------
    /**
     * When true, every fee-aware WAIT the LIVE Quick Trade forecast shows is
     * recorded once per symbol/timeframe/expiry/decision candle close
     * (table quick_trade_wait_shadows) and evaluated at expiry with the same
     * hypothetical net-after-costs rule used for traded WAITs, feeding the
     * separate `untraded_wait_shadow` block of the research audit summary.
     * OBSERVATION ONLY -- never places, blocks or alters a trade. Ships OFF
     * (new behaviour defaults off); requires the 2026_09_30_000000 migration.
     */
    'shadow_untraded_waits' => env('QUICK_TRADE_SHADOW_UNTRADED_WAITS', false),

    /** Hypothetical position size (USDT notional) untraded-WAIT outcomes are quoted on. */
    'shadow_untraded_waits_reference_notional_usdt' => (float) env('QUICK_TRADE_SHADOW_UNTRADED_WAITS_REFERENCE_NOTIONAL_USDT', 100),

    /** Rows older than this are pruned by quick-trades:sample-research (at most once an hour). */
    'shadow_untraded_waits_retention_days' => (int) env('QUICK_TRADE_SHADOW_UNTRADED_WAITS_RETENTION_DAYS', 30),
];
