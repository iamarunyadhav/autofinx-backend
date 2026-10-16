# AutoFinX V5 Phase 5 — Research Infrastructure and Model Report

Date: 2026-10-16  
Status: evidence-gated; no Top-Three engine and no production changes.

## Environment

Added:

- `audit/v5_phase5/requirements-research-py310.lock`
- `audit/v5_phase5/validate_environment.py`

The lock specifies pandas 2.2.3, numpy 2.2.2, scipy 1.15.1, scikit-learn 1.6.1, statsmodels 0.14.4,
pytest 8.3.4, chronos-forecasting 2.3.2, torch 2.4.1 and transformers 4.48.3. This is a research-only lock;
it does not alter Laravel or production Python dependencies.

An isolated venv was created at `D:\autofinx-v5-phase5-venv`. Package installation did not complete in this
environment (the pip download stalled and was stopped). Validation therefore reports the packages as unavailable.
The previously verified Chronos environment remains separate and was not modified. CPU-only execution is the safe
default; GPU compatibility is unverified in this host.

Validation command:

```powershell
D:\autofinx-v5-phase5-venv\Scripts\python.exe `
  autofinx-backend\backends\audit\v5_phase5\validate_environment.py
```

## Data expansion status

No new archive was accepted as evidence in this phase. The only validated manifest remains:

- BTCUSDT, ETHUSDT, SOLUSDT
- Binance Vision Futures 1h OHLCV and funding
- October–December 2025
- 6,624 OHLCV rows and 828 funding rows, with zero malformed rows and zero detected within-archive gaps

15m, 4h and 1d archives, 90/180/365-day windows, and representative broader symbol coverage were not available
in the verified local manifest. No OHLCV proxy was used for order-book, taker, open-interest or liquidation data.

Microstructure status:

| Dataset | Point-in-time historical evidence | Phase 5 status |
|---|---|---|
| Funding | Verified 2025 Oct–Dec archives | usable for existing 1h replay |
| Open interest | no validated archive/hash in manifest | unavailable |
| Taker buy/sell | not separately validated | unavailable |
| Liquidations | no validated archive | unavailable |
| Order-book/depth | no validated historical snapshots | unavailable |
| Long/short ratios | no validated point-in-time archive | unavailable |

## Model benchmark status

The Phase 3 identical-timestamp benchmark remains the latest numerical benchmark:

| Model | 1h balanced accuracy | 4h balanced accuracy | 24h balanced accuracy |
|---|---:|---:|---:|
| Naive SIDEWAYS | 33.33% | 33.33% | 33.33% |
| Persistence | 33.74% | 29.87% | 30.13% |
| Existing AutoFinX | 33.49% | 27.84% | 28.97% |

Scikit-learn was unavailable in the isolated environment, so Logistic Regression, Random Forest and Gradient
Boosting were **not run**. XGBoost/LightGBM were also not run. Chronos-2 was previously verified and repeatable,
but a new multi-horizon run was not claimed without the expanded data and completed environment.

## Calibration and statistical significance

No new calibrated probabilities were produced. Brier score, ECE and reliability intervals are unavailable. No
statistically significant improvement can be claimed. The prior fresh Chronos/AutoFinX cost-aware experiment
remains negative and is not reinterpreted here.

## Feature and ablation status

Existing causal features and factor artifacts remain available: returns, ATR/volatility, RSI, volume, candle body,
candle range and trend slope. Prior test ablation did not show positive after-cost edge; candle body was least
negative but still not a validated directional model. Historical microstructure features were not fabricated.

## Tests and exact files

Added:

- `audit/v5_phase5/requirements-research-py310.lock`
- `audit/v5_phase5/validate_environment.py`
- `docs/market-intelligence/V5_PHASE5_RESEARCH_INFRA_AND_MODEL_REPORT.md`

No production database migration was applied. No scheduler, wallet, risk or execution code changed. No new model
benchmark test was run because the required environment did not finish installing; this is recorded as a blocked
evidence gate, not a pass.

## Recommendation

**NO-GO for Top-Three ranking.** The next safe step is operational: complete the pinned environment installation
using an approved package mirror/cache, validate hashes/versions, then acquire and checksum-validate 15m/4h/1d
and longer manifests before running the requested ML comparison. Do not tune thresholds or claim improvement until
those prerequisites are reproducibly green.
