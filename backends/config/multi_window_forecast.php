<?php

/**
 * VNext Dynamic Decision Engine, Phase A -- read-only, observation-only
 * multi-window forecast comparison for Quick Trade.
 *
 * Purely additive: nothing here is read by any existing decision/execution
 * path (QuickTradeService::place()/assertOrderAgreesWithTradeDecision(),
 * RobotSessionService, any Ratio Bot decision file). See
 * App\Domain\Trading\MultiWindowForecastComparisonService for how these
 * keys are used.
 */
return [
    /**
     * The 3 predefined horizon windows this comparison always evaluates,
     * short/medium/long -- confirmed real, already-supported entries, not
     * guessed:
     *   - `timeframe` values match TimeframeRegistry::MAP (15m/1h/4h are
     *     all real chart timeframes already served by
     *     TradingMarketService::candles()).
     *   - `expiry_seconds` values (900/3600/14400) are all present in
     *     Providers\BinanceTimedFuturesProvider::capabilities()'s real
     *     `durations` catalog ([60, 300, 900, 3600, 14400, 86400]) -- the
     *     same list the live Quick Trade duration picker already offers.
     * Each window keeps its own Direction/Trade Decision/WAIT reason/cost
     * breakdown end to end (service, audit row, API response, UI) -- never
     * merged/averaged/fused into one number.
     */
    'windows' => [
        ['label' => 'short', 'timeframe' => '15m', 'expiry_seconds' => 900],
        ['label' => 'medium', 'timeframe' => '1h', 'expiry_seconds' => 3600],
        ['label' => 'long', 'timeframe' => '4h', 'expiry_seconds' => 14400],
    ],

    /**
     * Persist each computed window's forecast to `multi_window_forecast_audits`
     * (correlation-id-linked, one row per window per comparison request).
     * MultiWindowForecastAuditService::enabled() additionally requires the
     * table to actually exist, so this stays a safe no-op until the
     * (currently Pending, un-migrated) migration is reviewed and applied.
     */
    'capture_audit' => env('MULTI_WINDOW_FORECAST_CAPTURE_AUDIT', true),

    /**
     * Hindsight accuracy evaluation (MultiWindowForecastAuditService::
     * evaluateDue()) only ever scores a window against the canonical
     * binance_futures LIVE feed -- the same restriction
     * QuickTradeWaitShadowService already applies to its own untraded-WAIT
     * shadows, for the same reason: paper-mode candles are a synthetic
     * seeded series (TradingMarketService::ensureSyntheticSeries()), not a
     * real price, so scoring hindsight accuracy against them would be
     * fabricated data.
     */
    'reference_notional_usdt' => (float) env('MULTI_WINDOW_FORECAST_REFERENCE_NOTIONAL_USDT', 100),
];
