# V4 Multi-Factor Event Research — Progress V2

Updated 2026-10-15. This is a research implementation tracker, not a profitability claim.

## Completed in this safe increment

- Isolated package: `audit/autofinx_v4_multifactor_event_research/`.
- Immutable research contracts for exit policies, event trades, and outcomes. Every record hard-fails
  if `execution_authorized=true`.
- Event-driven OHLC simulator with TP, SL, liquidation boundary, conservative ambiguous intrabar
  handling, actual funding input, fees, slippage, MFE/MAE, holding duration, and OPEN_CENSORED outcomes.
- Causal starter factor engine for return, relative volume, ATR percentage, and RSI with explicit
  missing-data behavior.
- Causal factor catalog expanded with normalized candle body/range and normalized 20-bar OLS
  close slope. These are interface/test additions only; no trading decision path uses them.
- Chronological development/validation/test splitter.
- Causal multi-timeframe OHLCV aggregation with alignment, gap, forming-bar, and future-row rejection.
- First individual-factor lab over the verified BTC/ETH/SOL Binance 1h manifest: price action,
  relative volume, ATR-volatility, and RSI, each with fixed rules and the same event simulator.
- Regime and redundancy diagnostics: test-split feature correlations and high/low-volatility,
  up/down-return breakdowns are recorded without selecting a regime from test performance.
- Two frozen factor combinations tested: equal-weight majority and a predeclared correlation-aware
  penalty for price/RSI redundancy. No test-driven weight search was used.
- Cost-robustness replay completed at 1x and 2x fee/slippage assumptions. Test-split losses
  remained negative for both frozen combinations; no profitability claim is supported.
- Nine isolated Python tests pass, including no-future-data and ambiguous TP/SL tests.
- Factor-lab artifact: `D:\autofinx-v4-factor-lab-20261015.json`.
- Diagnostics artifact: `D:\autofinx-v4-factor-lab-diagnostics-20261015.json`.
- Combination artifact: `D:\autofinx-v4-factor-combination-20261015.json`.
- Robustness artifact: `D:\autofinx-v4-robustness-20261015.json`.
- Ablation artifact: `D:\autofinx-v4-factor-ablation-20261015.json`. Added-factor test means:
  candle body `-0.1041%` (66 trades), candle range `-0.4497%` (82), and trend slope
  `-0.4982%` (66), all net of the fixed fee/slippage policy. These are diagnostic results,
  not authorization evidence.

## Not yet completed (must not be represented as complete)

- Full 1m/5m aggregation and the complete factor catalog.
- Historical order-flow/depth/OI/liquidation data acquisition. OHLCV cannot substitute for these.
- Individual factor laboratory, factor ablation, weight discovery, regime classifier, and
  multi-year 2020–2026 manifests.
- Complete variable-holding walk-forward replay across all requested years and coins.
- Calibrated probabilities and cost-aware directional authorization. Existing ResearchDecisionPolicy
  remains WAIT/REJECT-only and was not weakened.
- Disabled current-market shadow collector and research dashboard.

## Evidence gates

- Existing V4 PHP tests: 71 passed / 475 assertions.
- Existing V4 Python contract tests: 8 passed.
- New event-research Python tests: 9 passed.
- ResearchDecisionPolicy + ForecastGateway focused PHP tests: 12 passed / 30 assertions.
  PHPUnit emitted only existing doc-comment metadata deprecation warnings.
- Fresh V2 Chronos/data replay: see `FRESH_V2_REPORT.md`; verdict remains NO-GO.

## Safety boundary

No Laravel execution service, wallet, risk guard, webhook, production configuration, migration, or
historical record was modified. No live flag or order capability exists in this package.

## Next safe phase

Add individually tested factor groups, then run a preregistered
event-policy comparison on a new non-holdout manifest. Do not tune on the protected holdout.
