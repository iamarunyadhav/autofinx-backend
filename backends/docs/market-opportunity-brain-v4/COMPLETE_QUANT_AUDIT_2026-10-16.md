# AutoFinX Complete Quantitative Audit — Evidence Consolidation

Date: 2026-10-16  
Scope: research-only audit; no live trading authorization.

## Evidence already completed

- Source inventory and V4 ownership review: `CURRENT_REPOSITORY_AUDIT.md`, `ARCHITECTURE_AND_OWNERSHIP.md`.
- Fresh Binance Vision OHLCV and funding archives for BTCUSDT, ETHUSDT and SOLUSDT, 1h, Oct–Dec 2025.
  Archives were checksum-validated; each symbol contains 2,208 candles, zero detected gaps/malformed rows,
  and 276 funding events. Provenance and hashes are in `FRESH_V2_REPORT.md`.
- Official pinned Chronos-2 inference was reproduced through `Chronos2Pipeline`; repeatability delta was zero
  and quantile ordering was checked. Chronos output was kept research-only.
- Source-faithful AutoFinX trend scoring was replayed: 12/24/48 candle windows, ATR filter, 0.5/0.3/0.2
  weights and ±0.15 threshold. It was not replaced with a momentum approximation.
- Chronos-2, naive-last, persistence and AutoFinX were evaluated with causal windows, one-bar latency,
  fees, slippage and funding. Test results did not establish a profitable edge.
- Event simulator and causal factor package are isolated under
  `audit/autofinx_v4_multifactor_event_research/`; `execution_authorized` hard-fails if true.
- Direction-focused ablation artifact: `D:\autofinx-v4-factor-ablation-20261015.json`.
  Test TP rates were 25.00–34.85%; SL outcomes dominated every factor.
- Cost robustness artifact: `D:\autofinx-v4-robustness-20261015.json`.
  Frozen combinations remained negative at both 1x and 2x costs.
- Focused PHP policy/forecast tests: 12 passed / 30 assertions. Isolated Python event tests: 9 passed.

## Failure attribution

1. **Data quality:** the audited Binance archives did not show gaps or malformed candles; data quality is not
   the primary explanation for the negative result.
2. **Model inference:** Chronos-2 inference was repeatable and quantiles were ordered. Inference failure is
   not the primary explanation.
3. **Prediction accuracy:** direction/TP hit rates were weak; this is the main measured failure.
4. **Trade decisions:** the existing research policy correctly fails closed (`WAIT`/`REJECT`); it does not
   hide a proven profitable direction.
5. **Execution costs:** costs worsen an already-negative result, but are not the sole cause.

## Architecture status

The required separation is preserved: market direction, trade opportunity and execution permission are distinct.
`ResearchDecisionPolicy` remains WAIT/REJECT-only because a validated `CalibrationValidator` and fee-aware cost
contract are not available. No uncalibrated probability is promoted to a trade decision.

## Remaining work before any promotion

- Multi-year, fresh non-holdout manifests and walk-forward replay.
- Real calibration artifact (fit only on development/validation) and Brier/ECE/coverage checks.
- Order-book/depth/open-interest/liquidation data; OHLCV is not a substitute.
- Direction-vs-TP/SL paired analysis with confidence intervals and serial-dependence-aware bootstrap.
- Shadow-only current-market collector and read-only dashboard.
- Full PHP regression suite and frontend gates after any code change.

## Verdict

**NO-GO for live trading or execution integration.** The tested system has reproducible inference and clean
research safety boundaries, but no statistically defensible after-cost directional edge or TP-dominant behavior.
Historical reports remain preserved; no trading algorithm, wallet, risk, webhook, production configuration or
historical record was modified by this consolidation.
