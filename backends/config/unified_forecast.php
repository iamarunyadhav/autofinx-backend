<?php

return [

    /*
    |--------------------------------------------------------------------
    | Unified Forecast Engine -- per-engine rollout flags
    |--------------------------------------------------------------------
    |
    | Every engine gets its own flag, per this session's established
    | "default OFF / shadow-mode first" discipline (RatioBrain, the Future
    | Trade AI gate, the wallet-fallback feature). Quick Trade's shadow flag
    | defaults to TRUE because shadow mode has zero effect on real Quick
    | Trade output in this pass -- it only logs a comparison via
    | AuditEventRecorder; the actual value returned to the caller is
    | unchanged either way. Robot Trade / Future Trade / Fast2X wiring is
    | explicitly out of scope for this task and has no flag here yet.
    |
    */

    'quick_trade_shadow_enabled' => env('UNIFIED_FORECAST_QUICK_TRADE_SHADOW_ENABLED', true),

    /*
    |--------------------------------------------------------------------
    | Robot Trade shadow flag
    |--------------------------------------------------------------------
    |
    | Same discipline as quick_trade_shadow_enabled above: defaults to TRUE
    | because this shadow comparison has zero effect on Robot Trade's real
    | decision (which QuickTrade to open, in which direction) -- it only
    | logs a comparison via AuditEventRecorder alongside the existing
    | SignalPreviewService::preview() call RobotSessionService already
    | makes. See RobotSessionService::recordUnifiedForecastShadowComparison().
    |
    */

    'robot_trade_shadow_enabled' => env('UNIFIED_FORECAST_ROBOT_TRADE_SHADOW_ENABLED', true),

    /*
    |--------------------------------------------------------------------
    | Quick Trade exchange key used for MarketOhlc/calibration lookups
    |--------------------------------------------------------------------
    |
    | Quick Trade's own request/DB schema has no first-class "exchange"
    | column (its candles come from TradingMarketService's abstraction, not
    | a direct MarketOhlc query) -- this is the exchange key the shadow
    | comparison and its periodic calibration refresh use when the caller
    | doesn't supply one explicitly, matching the same 'binance' key
    | RatioBotConfig::exchangeKey() defaults to elsewhere in this codebase.
    |
    */

    'quick_trade_default_exchange' => env('UNIFIED_FORECAST_QUICK_TRADE_DEFAULT_EXCHANGE', 'binance'),

];
