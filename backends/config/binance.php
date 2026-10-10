<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Conditional (stop / take-profit / trailing) order endpoint mode
    |--------------------------------------------------------------------------
    | Binance USD-S-M Futures moved conditional order types (STOP_MARKET,
    | TAKE_PROFIT_MARKET, STOP, TAKE_PROFIT, TRAILING_STOP_MARKET) to the Algo
    | Order service (POST /fapi/v1/algoOrder). The legacy POST /fapi/v1/order is
    | reported to reject them with -4120.
    |
    |  auto   : algo endpoint first; legacy ONLY after a DEFINITE "unsupported"
    |           rejection of the algo call (never after a timeout/5xx/unknown
    |           outcome). A legacy -4120 switches to algo. The learned mode is
    |           cached for conditional_mode_cache_ttl seconds.
    |  algo   : algo endpoint only; the legacy endpoint is never called.
    |  legacy : exactly the previous behaviour (rollback switch).
    */
    'conditional_order_mode' => env('BINANCE_CONDITIONAL_ORDER_MODE', 'auto'),
    'conditional_mode_cache_ttl' => (int) env('BINANCE_CONDITIONAL_MODE_CACHE_TTL', 600),
];
