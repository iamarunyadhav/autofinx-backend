# Experiments

Current registered evaluator: chronological forecast proxy over 160–1000 existing OHLC candles; exact exchange/symbol/timeframe/horizon, UTC date bounds and positive round-trip cost in basis points.
Reject incomplete candles, discontinuous history and horizons below/not divisible by candle resolution. Warm-up comes before the holdout; decisions only see trailing candles; outcomes do not overlap.
Persist dataset hash, split timestamp, model version, sample size, direction accuracy, MAE, range hit rate and mean cost-adjusted return proxy. WAIT predictions are excluded and no strategy is automatically promoted.

This does not simulate order fills, variable spreads, funding or execution latency. A hypothetical SELL does not imply spot short execution. Proposed strategy candidates require their own reviewed evaluator before validation. No arbitrary code or model-generated strategy execution is accepted.

