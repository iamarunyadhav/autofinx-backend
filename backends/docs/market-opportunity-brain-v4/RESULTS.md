# RESULTS.md — Market Opportunity Brain (append-only)

No experiment has been run yet. Phase 1 (this session) builds contracts and a deterministic
offline fixture provider only — there is no discovery, no forecast model, no decision policy
implementation, and therefore no sample size, no metric, and no edge claim to report.

## Status

- `CODE_IMPLEMENTED`: yes (Phase 1 scope: Data DTOs, Contracts interfaces, deterministic fixture
  provider, `naive_last` forecast adapter, config, PHP/Python contract-parity tests).
- `UNIT_TESTED`: yes — see TEST_EVIDENCE.md for exact commands/output.
- `REAL_DATA_VALIDATED`: **no** — no real Binance data was read; all tests run against the
  fictitious `FIXTUREAUSDT`/`FIXTUREBUSDT` symbols in `DeterministicMarketFixtureProvider`.
- `FORWARD_TESTED`: **no**.
- `DEPLOYED`: **NO**.
- `LIVE_TRADING_CONNECTED`: **NO**.
- Edge claim: **NONE**. No discovery ranking, forecast, or decision has been evaluated against any
  real-market outcome. There is nothing to be `REJECTED_NO_EDGE` or `INCONCLUSIVE` about yet —
  those verdicts apply once Phase 5 (validation) actually runs an experiment.

This file will gain real entries starting at Phase 5 (`PHASES_AND_TEST_GATES.md`), once a
preregistered experiment (`ExperimentRecord`) is actually executed against permitted historical
windows. Until then, any claim of "opportunity," "accuracy," or "edge" from this module would be
fabricated — none is made here.

## 2026-10-15, session 2 update

Phase 2 (real Binance adapter) and Phase 4 (decision policy) are now implemented and tested, and a
real opt-in smoke test confirmed the Binance adapter against live market data (see
`TEST_EVIDENCE.md` §10: 525 real symbols scanned, 10 ranked, real BTCUSDT candles retrieved).
**This is adapter/plumbing evidence, not a trading-edge result.** No discovery ranking has been
evaluated for predictive value, no forecast model has produced a verified real prediction yet
(Chronos-2 weight download did not complete this session), and `ResearchDecisionPolicy` is
currently incapable of emitting `LONG_CANDIDATE`/`SHORT_CANDIDATE` at all (by design, pending a
calibration validator and cost model that do not exist yet — see `DECISIONS.md`). Status remains:

- `REAL_DATA_VALIDATED`: still **no** for any discovery/forecast/decision claim (the Binance
  adapter itself is real, but no backtest/experiment has run against it).
- `FORWARD_TESTED`: still **no**.
- `DEPLOYED` / `LIVE_TRADING_CONNECTED`: still **NO**.
- Edge claim: still **NONE**.

## 2026-10-15, session 2 continued — Phase 3 completed

Chronos-2 produced real, genuine quantile output for real captured BTCUSDT data (p10=80519.48,
p50=80874.20, p90=81193.06 for a 4-bar/15m-ahead horizon), verified both standalone and through the
full PHP↔Python bridge (`TEST_EVIDENCE.md` §15). **This is proof the model adapter and bridge work
correctly end-to-end — it is NOT a backtest, NOT a calibration result, and NOT an edge claim.** No
quantile from this single real call has been checked against what BTCUSDT actually did afterward;
no sample size exists; `ResearchDecisionPolicy` still cannot act on this forecast (it is
`probability_calibrated: false`, same as every other adapter in this phase) and correctly returns
`WAIT: NO_CALIBRATION_EVIDENCE` if asked to decide on it. Granite TTM-R3 did NOT produce a usable
forecast this session — its published checkpoint has a real, confirmed weight-loading problem
(`DECISIONS.md` MOB-D-010) and the adapter honestly reports `MODEL_UNAVAILABLE` rather than
inventing a result. Status unchanged: `REAL_DATA_VALIDATED` / `FORWARD_TESTED` /
`DEPLOYED` / `LIVE_TRADING_CONNECTED` all still **no/NO**. Edge claim: still **NONE**.

## 2026-10-15, session 5 — Phase 5 evaluator (synthetic only)

`ExperimentRunner` verified on synthetic fixtures only (10 tests). Synthetic "CANDIDATE" verdicts prove the code path, not any market edge. Real-data experiment NOT run. Edge claim: still NONE. REAL_DATA_VALIDATED/FORWARD_TESTED/DEPLOYED/LIVE_TRADING_CONNECTED: no.

## 2026-10-15, session 6 -- mob-exp-v1: FIRST REAL-DATA EXPERIMENT -> REJECTED_NO_EDGE (both arms)

Plan frozen BEFORE data/forecasts (sha256 `ee71c1c1d8a4b2c708b79dca8d37ac1e52e768e05869ef98d6c7a4e1983236a7`, `D:\autofinx-ml\mob-experiment-v1\plan.json`). Data: Binance USDT-M public 1h klines, BTC/ETH/BNB/SOL/XRP, 2025-07-01..2025-12-31 (4,416 bars each, 0 gap windows), context 256, horizon 4 bars, step 4 (non-overlapping), 5,200 windows. Sealed holdout (>= 2026-01-04) never fetched: fetcher refuses it, builder excluded 0 windows because none reached it. Chronos-2 (`amazon/chronos-2`, CPU, 160.5 s for 5,200 forecasts, status OK). Cost: fee 5 bps + slip 1 bps per side = 0.12% round trip; stress x2 = 0.24%. Entry at bar-close with no latency (optimistic).

| Arm | trades | gross mean | net mean (1x) | net 95% CI (1x) | verdict 1x | verdict 2x |
|---|---|---|---|---|---|---|
| A momentum-sign (non-ML stand-in, NOT the fixed-five TradingView baseline) | 5,196 | +0.046% | -0.074% | [-0.113%, -0.036%] | REJECTED_NO_EDGE | REJECTED_NO_EDGE |
| C Chronos-2 p50 vs entry, threshold 0.12% else WAIT | 2,677 (2,523 WAIT) | -0.029% | -0.149% | [-0.204%, -0.090%] | REJECTED_NO_EDGE | REJECTED_NO_EDGE |

Descriptive Chronos-2 diagnostics (5,200 forecasts): p10-p90 coverage 78.1% (nominal 80%, reasonably calibrated interval); MAE of p50 vs naive_last = 1.046x (Chronos-2 is NOT better than "no change"); unthresholded p50 direction hit rate 48.7%, gross mean -0.018%. Conclusion: no forecast-derived directional edge after costs; the interval calibration is fine but carries no tradable direction information on this window. Arm A is gross-positive but cost-rejected.

Caveats (not validated): hypothetical replay only; single 6-month dev window; pooled coins are correlated and the bootstrap CI treats trades as independent (CI too narrow, but the sign is negative with wide margin); Chronos-2 pretraining may include 2025 data (contamination would favour the model, and it still failed); arm A is not the MASTER_SPEC fixed-five baseline (NOT_COMPARABLE); arms B and D not run; no funding cost modelled (0 bps); no latency/spread/depth. A 40-window smoke run (first 8 windows/symbol, July 2025, out dir `mob-experiment-v1-smoke`) was executed first to test plumbing and was NOT used to change any parameter. Edge claim: NONE. Promotion: STOP (consistent with D-046). REAL_DATA_VALIDATED (pipeline): yes; FORWARD_TESTED/DEPLOYED/LIVE_TRADING_CONNECTED: no.
