# Regime + Tradeability v1 — result (2026-10-10)

Written for: the owner. Machine report with every table: `D:\autofinx-ml-v4\regime_v1\results\REPORT.md`
(`q1_regimes.csv`, `q23_units.csv`, `q23_by_coin.csv`, `q23_full.json`, `verdicts.json`). Plan (frozen before any computation):
`REGIME_TRADEABILITY_V1.md`, sha256 `a48e74061695a0a5a0c70d8d73ef25bf76584088b138bcdabc0bcbe34b91f966`. R-029, D-047.

**Headline: the regime / tradeability idea, as a set of fixed textbook rules, carries real VOLATILITY information but NO directional information and NO
after-cost trading edge on BTC / ETH 2023-2025. A tradeability (WAIT) filter did not improve ten baseline strategies at 15m / 1h (powered null); at 4h it is
INCONCLUSIVE (not enough independent trades to see a 0.12 % effect). Regime-matched strategy choice and the BUY / SELL / WAIT composites did not make money.**

Scope: BTCUSDT + ETHUSDT (all verdicts), BNB / SOL / XRP / SUI as robustness only; 15m / 1h / 4h; window 2023-01-01 .. 2025-12-31; data cut 2026-01-01
(global seal 2026-01-03, **holdout reads: 0**, nothing from 2026 loaded; unit-tested). Costs C0 = 0.12 % round trip + actual funding; GROSS and NET both shown.
No fitted model, so no training leakage. Engine reused (`edge_search.core`), no duplicate engine. 187 tests pass (14 new in `tests/test_regime_v1.py`).

## Verdict per question (pre-registered rules)

| Question | Verdict | Evidence |
|---|---|---|
| Q1 direction information | **FAIL** | 108 BTC+ETH tests (6 cells x 9 regimes x 2 coins), 0 FDR-significant on both coins; regime mean forward return is within noise (15m / 1h cells |mean| <= 0.09 %, versus 0.12 % cost; the larger 4h / H4 cells have N 29-350 and MDE 0.3-1.1 %); hit rates mostly 47-55 %, no stable sign. |
| Q1 magnitude / volatility information | **LEAD (descriptive, not an edge)** | LOWVOL_COMPRESSION: forward |move| is 22-34 % smaller than unconditional (R = 0.66-0.78 on BTC+ETH, 0.66-0.88 on all six coins, sign stable in 2023, 2024, 2025, FDR-significant on both). Other regimes (STRONG_BEAR R ~1.13-1.18, SIDEWAYS_RANGE R ~0.8-0.9) are significant but below the 20 % size rule. |
| Q2a tradeability filter helps the baselines | **FAIL (15m, 1h powered) / INCONCLUSIVE (4h)** | TM filter (T >= 0.5 and move gate) keeps ~40 % of trades. Mean delta net per trade: 15m +0.0004 %, 1h 0.000 %, 4h +0.038 % (13 of 20 units positive, best q = 0.13, none FDR-significant). Delta MDE: 15m 0.009 %, 1h 0.035 %, 4h 0.137 % (> C0, so 4h cannot be decided). 0 HELPS, 0 LEAD of 300 tests. |
| Q2b any filtered baseline profitable | **FAIL** | 0 of 300 PROFITABLE. 15m / 1h filtered net stays -0.07..-0.22 % per trade. |
| Q3a regime-matched gate helps | **FAIL** | 0 of 48. Matched gate vs unfiltered delta ~0 at 15m, negative at 1h for trend rules, +0.07..+0.09 % at 4h for trend rules (q >= 0.45, not significant). Mismatch control (trend rules in range regimes) is worse at 4h (-0.17..-0.28 %), i.e. the direction is plausible but not significant. |
| Q3b gated strategies / composites profitable | **INCONCLUSIVE (gated sets) / FAIL (composites)** | 31 of 60 units under-powered (small sets such as RSI-in-range, N = 4..30). Composites D_dir / D_meta: 15m / 1h net -0.11..-0.125 %; 4h D_dir net +0.049 % (time) / +0.055 % (atr), gross +0.176 / +0.181 %, N ~1,300, PF 1.06, CI95 [-0.12, +0.21], 2025 negative: not significant (q = 1.0). |
| Q4 yearly stability | **LEAD for volatility effects only; FAIL for every direction / profit effect** | The sign-stable effect is LOWVOL_COMPRESSION -> smaller forward moves. No direction or profit effect passed. |
| Q5 probability semantics / calibration | **NOT TESTED** | Rule states only (BUY / SELL / WAIT mutually exclusive, categorical) and a [0,1] score. No probability was fitted, so none is called a probability and no Brier / ECE is reported. |

## Gross vs net, unfiltered baselines (DISC, % per trade)

15m / 1h unfiltered baselines: gross -0.10..+0.05 %, net -0.07..-0.22 % (always-long net -0.114 % at 15m, -0.097 % at 1h). 4h: only the two breakout rules are
positive: donchian55 gross +0.359 / net +0.228 % (N 395, PF 1.25, positive in 2023 / 2024 / 2025 but CI95 [-0.14, +0.60], q = 1.0, MDE 0.43 %) and squeeze
gross +0.277 / net +0.152 % (N 250); on all six coins donchian 4h falls to net +0.076 % and SUI is -0.447 %. Not results (the 0.12 % bar is not
cleared with statistical support); they are the same slow-timeframe "interesting but underpowered" picture as Edge Search v1.

## Per year (Q4)

Unfiltered baselines, mean net over strategies: 15m -0.125 / -0.119 / -0.126 (2023 / 2024 / 2025), 1h -0.113 / -0.134 / -0.135, 4h -0.137 / -0.151 / -0.107
(time exit). The tradeability filter never moved any year out of negative territory at 15m / 1h. Details per unit in `q23_units.csv` (columns y2023_*, y2024_*, y2025_*).

## What was tested / not tested (research-integrity wording)

Tested: these specific fixed, causal rule-based regime labels (9 states) and this fixed 7-measure chop / tradeability score (textbook parameters, no
tuning) on BTC / ETH 15m, 1h, 4h for 2023-2025, against a circular-shift label null and as a skip / selector rule for ten fixed baseline strategies with
real costs, ATR and time exits, worst-case intrabar stops. The primary filtered set is the subset of the unfiltered trades (exact avoided / wrongly-skipped
counts); a re-selected variant is in `q23_units.csv` (reselected_*). Random-filter null (2,000 draws, same share skipped), day-block bootstrap, BH-FDR
(348 / 420 tests), MDE-based INCONCLUSIVE.
Not tested / not proven: other regime definitions or thresholds, a fitted (walk-forward) classifier, probabilities, 5m / 1d trading, order-flow / book
inputs, the 2026 holdout (sealed), live execution, sizing or pause rules inside a portfolio. FAIL here means "this combination of fixed rules did not
show the pre-registered effect", not "regimes carry no information". Caveats: the window lies inside the DEV+VAL range that earlier experiments examined for other
hypotheses (the rules here were never fitted on it); BTC and ETH are highly correlated, so pooled N is not 2x independent (day-block bootstrap used); S7 (squeeze) is
nearly identical to the BREAKOUT label by construction (gate keeps 100 %); H = 4 bars is a single declared value; SOL / XRP / SUI start in 2023-10.

## Exploratory, descriptive only (NOT a result)

- A pause / risk use of the label looks sensible descriptively: LOWVOL_COMPRESSION bars are followed by ~25-35 % smaller moves (a move that often cannot pay the 0.12 % cost: mean
  BTC |move| / C0 0.94 at 15m, 1.7 at 1h), so "do nothing" there loses little; and the volatility regime could drive position sizing. Neither was tested as a sizing / pause
  rule with P&L.
- The T filter's best-looking slice is 4h trend rules (+0.04..+0.15 % per trade, 13 of 20 units positive); that needs more history or more coins to resolve (MDE 0.137 %).

## Implication for Ratio Bot (advisory only, no change made)

Nothing here supports using these regime / WAIT rules as an entry-edge gate. If anything, use the volatility information as a risk control (skip or shrink
when LOWVOL_COMPRESSION, size by regime volatility) and treat it as a hypothesis for a separate pre-registered test.

## Files

Plan `REGIME_TRADEABILITY_V1.md` + `D:\autofinx-ml-v4\regime_v1\PLAN_SHA256.txt`; code `external-predictive-engine/regime_v1/` (data, features, q1, trades, stats, analysis, report,
runner), launcher `external-predictive-engine/run_regime_v1.py`, tests `tests/test_regime_v1.py`; results `D:\autofinx-ml-v4\regime_v1\results\`; log `D:\autofinx-ml-v4\logs\regime_v1.log`.
