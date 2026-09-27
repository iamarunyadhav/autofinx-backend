# Quick / Robot display and forecast comparison

Browser display uses Binance Futures `/public/ws/<symbol>@bookTicker` for
the bid/ask midpoint and `/market/ws/<symbol>@kline_<interval>` for forming
candles. Quotes appear as a separate chart price line; they do not overwrite
OHLC history or become executable order prices.

The legacy root kline endpoint can connect without delivering candles.
An eight-second read-only probe on 2026-09-27 observed zero legacy kline
messages, eight routed kline messages and 164 routed book-ticker messages.
This is a diagnostic observation, not a latency or throughput guarantee.
See Binance's [route migration documentation](https://developers.binance.com/en/docs/products/derivatives-trading-usds-futures/websocket-market-streams/Important-WebSocket-Change-Notice).

Quick Trade forecast targets use the last closed candle rather than the
current canonical quote. Canonical feed freshness checks remain active.
Comparison requests retain candle-open timestamps so `CandleCloseResolver`
can remove forming bars. Robot report forecasts recompute their signal on
closed candles rather than borrowing a changing runtime entry decision.
The existing `quick_trade.closed_candles_only` setting must remain enabled
(its default). This does not change Robot execution strategy settings.

Quick and Robot comparison cards expose Current, Unified, MarketAnalysisEngine
and ConfidenceEngine outputs. MarketAnalysisEngine reuses the persisted-candle
trend scorer; its windows are lookbacks on one timeframe. ConfidenceEngine
reuses the existing contribution formula with Unified's scope-matched
calibration evidence. It is a weighting helper, not a fourth independent
predictor. No separate historical accuracy for these helpers is invented;
insufficient calibration gives zero forecast weight. These cards do not
switch execution engines or place trades.

Deploy frontend assets and backend code together. Existing prediction workers
need to be restarted by the server operator to load PHP changes. No migration
is required. Local exchange probes do not verify the production browser,
network, workers or deployment.
