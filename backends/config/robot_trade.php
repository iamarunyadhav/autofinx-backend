<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Robot Trade / shared TradeForecastService cost-and-risk knobs
    |--------------------------------------------------------------------------
    |
    | This file was missing entirely until now -- every `config('robot_trade.*')`
    | call across the codebase (TradeForecastService, QuickTradeCostModel,
    | QuickTradeService, RobotSessionService) was silently falling back to its
    | inline PHP default, and any ROBOT_TRADE_* value in .env had zero effect,
    | because Laravel only reads config() from a config/*.php file that exists.
    |
    | These keys are shared by Quick Trade, Robot Trade, and Ratio Bot's
    | brain-confirmation path (all three call TradeForecastService::forecast()).
    | Changing live_min_edge_cost_multiplier here changes the cost gate for
    | all three at once -- it is the same multiplier that was deliberately
    | raised from 1x to 2x on 2026-09-23 (TradeForecastService.php) so the
    | BUY/SELL badge would never contradict the Profit Calculator's own
    | tradeability check. Lowering it below 2.0 reverses that safety fix.
    |
    */

    'live_slippage_buffer_pct' => env('ROBOT_TRADE_LIVE_SLIPPAGE_BUFFER_PCT', 0.04),

    'live_min_edge_cost_multiplier' => env('ROBOT_TRADE_LIVE_MIN_EDGE_COST_MULTIPLIER', 2.0),

    'live_min_expected_net_usdt' => env('ROBOT_TRADE_LIVE_MIN_EXPECTED_NET_USDT', 0.03),

    'max_entry_price_drift_bps' => env('ROBOT_TRADE_MAX_ENTRY_PRICE_DRIFT_BPS', 20.0),

    'live_min_hold_seconds' => env('ROBOT_TRADE_LIVE_MIN_HOLD_SECONDS', 60),

];
