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

    /*
    |--------------------------------------------------------------------------
    | Canonical fee-aware trade_decision gate for Robot Trade LIVE entries
    |--------------------------------------------------------------------------
    |
    | When true, a live Robot Trade entry (timed_expiry AND candle_direction)
    | is only placed when TradeForecastService::forecast()'s fee-aware
    | `trade_decision.value` equals the side the robot is about to open --
    | the SAME engine, cost model and closed-candle input preparation Quick
    | Trade's own live guard (QuickTradeService::assertOrderAgreesWith
    | TradeDecision(), `quick_trade.block_live_against_decision`) uses. No fee
    | percentage is defined here: the cost is always derived from the keys
    | above plus `trading.binance_futures_taker_fee_rate`.
    |
    | Unlike Quick Trade's manual guard, this one is FAIL-CLOSED: an
    | unavailable/uncomputable decision blocks the entry (an autonomous robot
    | must never trade on "no answer"). It only ever ADDS a block -- Robot
    | Trade's existing confidence, streak, cooldown, active-trade, loss-limit,
    | duplicate, stale-feed, net-edge and price-drift guards all still run
    | first, unchanged. Paper mode is never gated (same scope as Quick Trade).
    |
    | Default OFF so currently-running live robot sessions behave exactly as
    | before until the owner opts in. A `settings` row (group `robot_trade`,
    | key `block_live_against_decision`) overrides this per workspace.
    |
    */

    'block_live_against_decision' => env('ROBOT_TRADE_BLOCK_LIVE_AGAINST_DECISION', false),

];
