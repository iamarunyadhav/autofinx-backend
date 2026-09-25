<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared TradeForecastService helper knobs
    |--------------------------------------------------------------------------
    |
    | Keys here are read by app/Domain/Trading/TradeForecastService.php and
    | therefore change live decisions in MORE THAN ONE engine at once. Every
    | caller of TradeForecastService::computeAtrAvgPct() (directly or via
    | forecast()/netEdge()) is affected by `atr_latest_bars`:
    |
    |   Quick Trade   forecast()/netEdge() via QuickTradeForecastCalculator and
    |                 QuickTradeResearchService (the live A5 placement guard)
    |   Robot Trade   netEdge() preflight, ATR-based stake sizing, the
    |                 canonical trade-decision gate, the Robot page forecast
    |                 (only when no volatility.atr_pct is supplied)
    |   Ratio Bot     RatioBotBrainService (forecast()),
    |                 MultiWindowTrendScoringService (per-window reference
    |                 move), SpotEmergencyGuard (flash-crash ATR multiple),
    |                 ForecastHorizonBacktestService (forecast())
    |   Nexus         NexusExperimentService (forecast(), research only)
    |
    */

    /*
    | computeAtrAvgPct() is documented as "average ATR as % of price over the
    | last 20 periods", but until 2026-09-24 it averaged the true ranges of
    | bars 1..20 of whatever array it was handed -- i.e. the OLDEST 20 bars of
    | a 100-candle window -- while dividing by the LATEST close. true (the
    | default, the bug fix) averages the latest 20 true ranges. false restores
    | the legacy oldest-bars behaviour byte-for-byte, for config-only rollback.
    | Inputs with <= 21 candles are identical under both settings.
    */
    'atr_latest_bars' => env('TRADING_FORECAST_ATR_LATEST_BARS', true),

    /*
    | How many candles Quick Trade's live placement path (QuickTradeService::
    | place() -> SignalPreviewService + the research forecast behind the A5
    | guard) fetches, and how many of Robot Trade's already-fetched candles
    | its canonical trade-decision gate hands to forecast(). One value so the
    | two gates decide on comparable inputs. 80 preserves Quick Trade's
    | existing placement behaviour exactly; Robot's gate previously used all
    | 100 candles its evaluation loop fetches (forecast() only reads its own
    | horizon-sized window of these, so the difference only shows for
    | horizons of 20+ chart candles). Robot's own signal/volatility/net-edge
    | inputs keep their 100 candles -- only the gate's input is trimmed.
    */
    'decision_candle_count' => (int) env('TRADING_FORECAST_DECISION_CANDLE_COUNT', 80),

];
