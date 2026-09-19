# Initial Nexus capability and research baseline

This is a source-code baseline, not a market study or live performance measurement. Evidence level C unless a separate experiment is linked.

| Area | Verified foundation | Recommended investigation |
| --- | --- | --- |
| Existing strengths | Separate trading domains, shared forecast/indicators, audit events, risk guards, backtest services | Measure production wiring and outcomes rather than inferring readiness from files |
| Existing improvement | Quick Trade research and calibration services | Versioned, reconciled outcomes by market, symbol, timeframe, horizon and regime |
| Missing/uncertain capabilities | No Nexus module existed before this implementation | Confirm historical depth and spread coverage before claiming microstructure replay support |
| Scalping | Fast2X and existing short-horizon engines | Dedicated spread/depth/trade-flow datasets and latency-aware fills; avoid duplicate strategy implementations |
| Crypto | OHLC storage and exchange execution paths | Data completeness, exchange/product identity and realistic costs |
| Futures | Logical trades, risk, funding and reconciliation fields | Separate funding, entry/exit fees and reconciliation completion in evaluation |
| Forex | ForexGrid sizing and feed-freshness helpers | Verify demo account/feed access and session-spread history |
| Metals | Pepperstone basket, MTF, regime and portfolio helpers | Session tagging, macro event publication time, broker spread history |
| Execution | SmartOrderRouter and execution guards | Measure effective spread, fill failures and latency before proposing routing improvements |
| ML/forecast | UnifiedForecastEngine and calibration/backtest services | Out-of-sample drift/calibration study; do not translate strength into probability without evidence |
| Data collection | Structured audit, trade, candle and source records | Begin immutable depth/spread datasets and retain forecast versions, timestamps and costs |
| Priorities | Deploy Nexus observation and sources first | Verify evidence quality, gather samples, approve one targeted experiment, then review a build package |

Code-reading caveat: the legacy `laravel-services/StrategyService/Support/Signals/SignalCalculator.php` still contains random demo signal logic. Determine whether any intended live workflow invokes it before considering that legacy strategy stack production-ready. Nexus does not modify it.

The source registry's initial central-bank/academic feeds have not been claimed live-verified. No hypothesis in this report is claimed profitable. Missing datasets remain research requirements rather than discarded ideas.
