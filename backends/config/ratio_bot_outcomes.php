<?php

/*
 * Ratio Bot trade-level accuracy tracking -- OBSERVATION ONLY.
 * Nothing here is read by any trading decision; see
 * App\Domain\RatioBot\RatioBotTradeOutcomeService.
 */
return [
    // Record one outcome row per real filled spot BUY/SELL. Costs a single
    // extra insert on the (already heavy) fill path; wrapped so it can never
    // throw into it.
    'capture_enabled' => (bool) env('RATIO_BOT_OUTCOMES_CAPTURE_ENABLED', true),

    // Lazily score due rows from RunRatioBotEvaluationJob, AFTER that tick's
    // trading work has finished. Throttled per bot (below) and batch-capped;
    // set false to take it off the evaluation loop entirely (rows then stay
    // `pending` until `ratio-bot:evaluate-trade-outcomes` is run by hand --
    // that command is NOT scheduled; it also covers stopped bots, which get
    // no evaluation-job ticks).
    'evaluate_in_loop' => (bool) env('RATIO_BOT_OUTCOMES_EVALUATE_IN_LOOP', true),
    'evaluation_throttle_seconds' => (int) env('RATIO_BOT_OUTCOMES_THROTTLE_SECONDS', 60),
    'evaluation_batch_size' => (int) env('RATIO_BOT_OUTCOMES_BATCH_SIZE', 5),
    // A row is only scored once its bounded window has fully closed. If
    // candle coverage is still short at that point (daemon gap; a backfill
    // may close it), it is retried every recheck_interval_seconds up to
    // max_evaluation_attempts times, then settles on `insufficient_data`.
    'recheck_interval_seconds' => (int) env('RATIO_BOT_OUTCOMES_RECHECK_SECONDS', 300),
    'max_evaluation_attempts' => (int) env('RATIO_BOT_OUTCOMES_MAX_ATTEMPTS', 3),

    // BUY: how long after the fill the lot is given to reach its net-profit
    // floor before it is scored `not_recovered_within_horizon`.
    'buy_horizon_hours' => (float) env('RATIO_BOT_OUTCOMES_BUY_HORIZON_HOURS', 24),
    // SELL: lookahead window after the fill used to measure missed upside.
    'sell_lookahead_hours' => (float) env('RATIO_BOT_OUTCOMES_SELL_LOOKAHEAD_HOURS', 4),

    // A negative/timing verdict is only issued when at least this fraction
    // of the expected closed candles in the window actually exist in
    // market_ohlc; otherwise the row is `insufficient_data`, never guessed.
    'min_candle_coverage' => (float) env('RATIO_BOT_OUTCOMES_MIN_CANDLE_COVERAGE', 0.8),
    // Allowance for the WS daemon to persist the last closed candle.
    'close_grace_seconds' => (int) env('RATIO_BOT_OUTCOMES_CLOSE_GRACE_SECONDS', 120),

    // Accuracy percentages are null (status insufficient_data) below this
    // many evaluated rows -- same convention as Quick Trade's audit_summary.
    'min_samples_for_accuracy' => (int) env('RATIO_BOT_OUTCOMES_MIN_SAMPLES', 30),
];
