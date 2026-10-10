# Experiment F POC v1 — Market Structure proof of concept (pre-registered, frozen before any result)

Owner instruction 2026-10-10 (supersedes the larger "Experiment F" build): proof of concept FIRST; **no API, no UI, no dashboard** (not started; the empty `api/` / `ui/` folders were removed).
Research only: local public Binance files, no keys, no orders, no server / DB, no existing code touched. Frozen by sha256 in `D:\autofinx-ml-v4\experiment_f_v1\poc\PLAN_SHA256.txt`;
the script refuses to run if this file changes. No real-data result existed when this plan was frozen (only synthetic-data unit tests were run).

## 0. HONESTY STATEMENT

**REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT.** 2023-2025 BTC data was examined by V4 / Edge Search / Regime / Quick Trade Replay / Experiment E / Rule Baseline,
and this hypothesis was chosen after those results (breakout-with-trend is close to the already-FAILED Rule Baseline v1, R-032). The sealed 2026 holdout (2026-01-04+) is NOT read
(reads = 0; the loader refuses any cut after 2026-01-01). A PROMISING verdict could only justify a pre-registered forward paper test, never live use. NOT PROMISING / INCONCLUSIVE are valid, safe outcomes.

## 1. Data

BTCUSDT primary; ETHUSDT and SOLUSDT robustness, report-only, run AFTER the main result, outside the FDR family and the verdict (SOL starts 2023-10). Local D: monthly 5m klines 2022-04..2025-12
through `regime_v1.data.build_coin` (refuses a cut after 2026-01-01); 15m / 1h / 4h built by closed-bar aggregation (`edge_search.core`: a bar exists only if every 5m bar inside it exists).
Warm-up from 2022-04; development window = signal bars with close >= 2023-01-01 and horizon end <= 2026-01-01. Funding: actual events (entry < event <= exit).

## 2. The rule (fixed, no tuning, three factors only)

Engine `experiment_f/core/structure.py` (independent, causal; prefix invariance is unit-tested on synthetic and real BTC data):
- Swing = fractal, strictly higher (lower) than k bars each side; **k = 3 on 15m, 3 on 1h, 2 on 4h**; occurrence bar j, **confirmed at the close of bar j + k**, invisible earlier.
  Label of a swing high vs the previous swing high: HH / LH (EH if equal); swing low: HL / LL (EL).
- Trend per timeframe: BULLISH = latest high HH and latest low HL; BEARISH = LH and LL; SIDEWAYS = mixed / equal; UNCERTAIN = fewer than 2 confirmed highs or lows; a CONFIRMED CHoCH
  (3 consecutive closes beyond the opposite reference level) against the trend makes the final trend UNCERTAIN until the next swing is confirmed.
- **BOS is close-based**: close[t] above the latest confirmed swing high (not yet broken) while the timeframe's trend is BULLISH = bullish BOS (mirror below the latest confirmed swing low
  while BEARISH). A wick above / below without a close beyond is not a BOS.
- Higher-timeframe values: the 4H and 1H trend of the last COMPLETED higher bar (higher close time <= the 15m close time).
- **Prediction at the close of 15m bar t**: **UP** if 4H trend BULLISH AND 1H trend BULLISH AND 15m bullish BOS on bar t; **DOWN** if 4H BEARISH AND 1H BEARISH AND 15m bearish BOS on bar t;
  otherwise **WAIT**. No RSI / MACD / volume / ML / scoring / probability. **Variant NOHTF** (declared in advance to answer "does HTF alignment help"): UP at any 15m bullish BOS, DOWN at any 15m
  bearish BOS, no higher-timeframe condition.

## 3. Target, eligibility, flat rule

- **Eligible bar** = every closed 15m bar in 2023-01-01..2025-12-31 whose horizon bars are complete (all of them, no sampling). Nothing is fitted, so there is no training set; the chronological
  checks are the per-year split (2023 / 2024 / 2025) and the halves 2023-24 vs 2025.
- Primary horizon **15 minutes**: entry = open of the next 15m bar, exit = its close; actual move g = close[t+1] / open[t+1] - 1. Secondary horizons (declared in advance, same decision rule):
  **1h** (g = close[t+4] / open[t+1] - 1) and **4h** (close[t+16] / open[t+1] - 1).
- **Flat-price rule**: g == 0 exactly = FLAT: excluded from the accuracy denominator (counted and reported), but kept as a (break-even gross) trade in the profitability check.
- Accuracy = hits / non-flat signals (WAIT excluded from the denominator); coverage = UP / DOWN / WAIT counts and WAIT share over eligible bars; UP accuracy and DOWN accuracy separately; Wilson 95 % CI.
  Horizon 1h / 4h signals overlap (every signal bar is scored); the day-block bootstrap below accounts for the dependence.

## 4. Baselines (all reported, same horizon)

On the SAME signal bars: always-UP, always-DOWN, majority direction (the more frequent non-flat sign over all eligible bars of the window; a hindsight, descriptive baseline), and **random sides
with 1,000 seeds** (accuracy mean / sd and p_dir = (1 + #seeds >= rule) / 1001). Also the NOHTF variant. The random-side accuracy expectation on the same bars is 0.5 x non-flat share, used as
the null in the accuracy items ("excess" = hit - 0.5 x non-flat, summed per day).

## 5. Profitability check (simple, no stops / targets / leverage / sizing)

Entry at the next 15m open, exit at the close of the horizon bar, fixed notional, non-overlapping (greedy: next trade enters at or after the previous exit). Costs per round trip: **primary 0.12 %**
(fee 0.05 % x 2 = 0.10 % + slippage 0.01 % x 2 = 0.02 %, measured, D-040) and **sensitivity 0.14 %** (0.10 % + 0.04 %, the code's QuickTradeCostModel). Reported: gross, fees, slippage, actual funding, net,
net expectancy, win rate, avg win / loss, profit factor, maximum drawdown (percentage points of notional, cumulative in trade order), per-year (2023 / 2024 / 2025) and halves. Matched random-side null
(same entries, 1,000 seeds). 15m: every signal is its own trade (consecutive bars never overlap).

## 6. Statistics and declared items (one BH-FDR family, q = 0.05, 15 items)

Day-block bootstrap (whole UTC days, 2,000 resamples, `quick_trade_replay_v1.stats`): SE, CI90 = effect +/- 1.645 SE, one-sided p = 1 - Phi(effect / SE), MDE = 2.8 x SE.
Per horizon H in {15m, 1h, 4h}: **ACC|RULE|H** and **ACC|NOHTF|H** (excess accuracy vs random sides > 0), **NET|RULE|H** and **NET|NOHTF|H** (mean net per trade at 0.12 % > 0),
**HTF|H** (ACC RULE excess minus ACC NOHTF excess > 0: does HTF alignment improve accuracy). 5 x 3 = **15 items**, one BH-FDR family. The 0.14 % rows, ETH / SOL, year / half splits and the
random-seed rows are report-only.

Item verdicts. Accuracy-type items (ACC, HTF): **LEAD** = q < 0.05 and CI90 lower > 0 and effect >= **1 percentage point** and MDE <= **2 pp** and effect > 0 in each of 2023 / 2024 / 2025;
**FAIL** = CI90 upper < +1 pp and MDE <= 2 pp; else INCONCLUSIVE. NET items: LEAD = q < 0.05, CI90 lower > 0, effect >= **0.03 %** per trade, MDE <= **0.06 %**, effect > 0 in each year;
FAIL = CI90 upper < 0.03 % and MDE <= 0.06 %; else INCONCLUSIVE.

## 7. Final verdict (exactly one; checked in this order)

1. **PROMISING** iff ACC|RULE|15m is LEAD AND at least one NET|RULE|H item (any horizon) is LEAD.
2. **NOT PROMISING** iff ACC|RULE|15m is FAIL OR all three NET|RULE|H items are FAIL (powered tests excluding a material effect).
3. Otherwise **INCONCLUSIVE** (typically underpowered; the MDE is reported).
The report answers: does structure beat the simple baselines (random / always-up / always-down / majority); does HTF alignment help (HTF items vs NOHTF); WAIT frequency; profitable after costs (gross vs
net at 0.12 / 0.14); consistent across 2023 / 2024 / 2025; is there evidence to justify a larger system (only a PROMISING verdict says yes).

## 8. Tests (before the real run; all green)

`experiment_f/tests/test_experiment_f.py`: swing confirmation delay and no early availability, planted look-ahead caught by prefix invariance, HH / HL / LH / LL, BOS close vs wick, CHoCH states,
S/R zone causality, multi-timeframe sync (completed higher bars only), missing / duplicate / invalid bars, replay prefix invariance on real BTC, rule mapping, accuracy / flat / profit accounting,
holdout refusal, isolation manifest. The existing engine suite must stay green.

## 9. Runtime and safety

Below-normal priority, 2 GB available-commit guard, STOP file `D:\autofinx-ml-v4\experiment_f_v1\poc\STOP`, outputs on D:, temp / caches / pycache on D: (C: had ~0.06 GB free), collector untouched,
no commit / push, no existing file modified (manifest proof), D: free space > 30 GB.

## 10. Not tested (stated up front)

Stops / targets / trailing / sizing / leverage, other pivot parameters (k), other timeframes, zones / CHoCH / volume / volatility in the rule (engine pieces exist but are not part of this POC),
order-flow data, the sealed 2026 holdout. Larger engine pieces written earlier (`experiment_f/core/brain.py`, `job.py`, `soft.py`, `params.py` arms) are NOT used here and were never run on real data.
