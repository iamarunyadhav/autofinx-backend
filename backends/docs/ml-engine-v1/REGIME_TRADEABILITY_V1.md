# AutoFinX Regime + Tradeability v1 — FROZEN plan (registered 2026-10-10, before any computation)

Registry: RESULTS R-029 · DECISIONS D-047. The sha256 of this file is stored in `D:\autofinx-ml-v4\regime_v1\PLAN_SHA256.txt`
and in every result file. The runner refuses to start if the hash does not match. No threshold, definition, grid or pass
rule in this file may change after the first result exists. Anything found later that needs a change is written as a
dated AMENDMENT at the end (never an edit), with the reason, and is listed in the result report.

Owner request (2026-10-10): "take 3 years of data, apply my regime idea, see how it performs over those 3 years; do it
yourself and give only the result; use the local training data on D:." The idea = sections 6 (market regime classification),
7 (noise / chop / tradeability, WAIT score) and 10-11 (BUY / SELL / WAIT semantics, thresholds) of the V5 prompt.

Research only. No live trading, no keys, no server, no DB, no migration, no commit. Not a directional prediction model:
there is NO fitted model in this plan (no training, so no training leakage). All thresholds are fixed textbook values chosen
BEFORE any data was looked at for this study. Nothing is tuned on results. If any ML classifier is ever added it must be a
new plan, expanding-window walk-forward only, reported separately.

---------------------------------------------------------------------------------------------------
## 1. Data, window, seal

- Source: local files only, `D:\autofinx-ml-v4\data\<SYMBOL>\5m\monthly\YYYY-MM.parquet` (Binance USD-M futures 5m klines)
  and `...\funding\monthly\YYYY-MM.parquet` (actual funding events). 1m is not needed (worst-case intrabar handling uses
  the existing v1 5m-bar engine: stop wins inside a bar, an opening gap through the stop exits at the open).
- Coins: BTCUSDT, ETHUSDT = PRIMARY (all verdicts). BNBUSDT, SOLUSDT, XRPUSDT, SUIUSDT = robustness (descriptive, separate
  BH family). SOL / XRP / SUI local history starts 2023-10, so their 2023 coverage is partial; stated, not hidden.
- Development window = 2023-01-01 00:00 UTC .. 2025-12-31 24:00 UTC (CUT = 2026-01-01 00:00 UTC). A trade / forward window
  belongs to the window only if its entry AND exit are inside it. Indicator warm-up may use bars from 2022-04-01 (older
  than the window, same file set).
- Only monthly files named 2022-04 .. 2025-12 are read. No file or row at or after 2026-01-01 is ever loaded (the loader
  asserts max open_time < CUT). The global seal is 2026-01-03; the sealed holdout (2026-01-04 on) is never read, reads = 0,
  no HoldoutGate call is made. A unit test proves a 2026 file is ignored and CUT > 2026-01-01 is refused.
- Bars: 15m / 1h / 4h / 1d aggregated from 5m with the v1 closed-candle rule (a bar exists only if all its 5m bars exist;
  otherwise open/high/low/close/volume are NaN and every feature there is NaN -> regime UNCERTAIN, tradeability NaN ->
  WAIT). Code reuse: `edge_search.core` (Coin, Bars, outcomes_time, outcomes_atr, select_trades, Trades.net, block_ci,
  bh_adjust, bh_reject, describe, random_null) and `v4.strategy_v13` indicators. No duplicate engine.
- Prior exposure, stated honestly: this 2023-2025 window lies inside the DEV+VAL range that earlier experiments (V4/P5,
  Edge Search v1, M1) already examined for other hypotheses. The regime / chop rules below were never fitted or chosen on
  it, but it is NOT an untouched sample. That is why only fixed textbook parameters, FDR, yearly stability and a
  circular-shift null are used.

## 2. Costs and execution (v1 conventions)

- Signal on the CLOSE of bar t (every feature uses only closed bars <= t), fill at the OPEN of bar t+1.
- C0 = 0.12 % round trip (taker 0.05 % + slippage 0.01 % per leg, both legs) + actual funding while holding (long pays
  positive funding; trade pays the sum of events with entry < event <= exit). Net = gross - 0.12 - side * funding.
- Maker proxy column (idealised, assumes every limit fills, no adverse selection, never used for a verdict):
  gross - 0.06 (2 x (0.02 + 0.01)) - funding.
- Leverage 1x, results in % of notional. Gross and net are reported side by side everywhere.
- Trades are non-overlapping per coin (greedy, first signal, next signal >= exit bar), one position per coin x timeframe
  x strategy. Exit rules (both pre-registered): TIME (close of bar t+H) and ATR (stop 1.5 x ATR14 of the signal bar from
  the entry, time exit at t+H, stop wins inside a bar = worst case, an opening gap through the stop exits at the open).
- Holding H = 4 bars of the signal timeframe for every strategy (15m -> 1 h, 1h -> 4 h, 4h -> 16 h). (v1's H grid had no 4;
  this single fixed value is declared here once and not varied.)

## 3. Causal features (per timeframe tf in {15m, 1h, 4h, 1d}; windows in bars; every value at bar t uses bars <= t only)

Textbook windows, fixed: ATR 14 (Wilder), ADX 14 (Wilder, v4.strategy_v13.dmi), EMA 20 and EMA 50, Bollinger 20 / 2
(bandwidth BBW = 4 x std20 / SMA20), Donchian 20, percentile rank window 100 bars (v1 squeeze convention; rank of the
current value inside the last 100 values, current included, in [0, 1]).

- ATRp = ATR14 / close x 100.  vol_pct = pct_rank100(ATRp).  bbw_pct = pct_rank100(BBW).
- Swing points: a pivot HIGH at bar j needs high_j strictly greater than the highs of bars j-3..j-1 AND j+1..j+3 (k = 3).
  It is only KNOWN from the close of bar j+3 (confirmation). Pivot LOW symmetrical. At bar t only pivots with j+3 <= t are
  used. Structure at t from the last two confirmed highs and last two confirmed lows: BULL = last high > previous high AND
  last low > previous low; BEAR = both lower; otherwise (or fewer than two of each) NEUTRAL.
- Donchian break at t: close_t > max(high_{t-20..t-1}) (up) or < min(low_{t-20..t-1}) (down).

### 3.1 Regime classification (section 6) — one label per bar, mutually exclusive, applied in this priority order

0. UNCERTAIN if any input is NaN (history < 100-bar windows, incomplete bar).
1. BREAKOUT: a Donchian-20 break occurred in bars t-2..t (direction = the latest break) AND bbw_pct <= 0.20 on at least one
   bar in t-10..t (a squeeze preceded it).
2. Direction components: d_ema = sign(EMA20 - EMA50), d_px = sign(close - EMA50), structure.
   BULL if d_ema > 0 and d_px > 0 and structure != BEAR. BEAR if d_ema < 0 and d_px < 0 and structure != BULL.
3. ADX >= 25: BULL -> STRONG_BULL; BEAR -> STRONG_BEAR; neither -> UNCERTAIN (conflicting evidence).
4. 20 <= ADX < 25: BULL -> WEAK_BULL; BEAR -> WEAK_BEAR; neither -> UNCERTAIN.
5. ADX < 20: vol_pct >= 0.80 -> HIGHVOL_RANGE; else bbw_pct <= 0.20 -> LOWVOL_COMPRESSION; else SIDEWAYS_RANGE.

Codes: 0 UNCERTAIN, 1 STRONG_BULL, 2 WEAK_BULL, 3 STRONG_BEAR, 4 WEAK_BEAR, 5 SIDEWAYS_RANGE, 6 HIGHVOL_RANGE,
7 LOWVOL_COMPRESSION, 8 BREAKOUT. TREND regimes = 1-4, RANGE regimes = 5-7.
HTF label (secondary): the same classifier on the next higher timeframe (15m -> 1h, 1h -> 4h, 4h -> 1d), read at the close
of the signal bar from the last COMPLETED higher bar only (higher bar j is usable at lower bar i iff close_time_hi[j] <=
close_time_lo[i]).

### 3.2 Noise / chop / tradeability (section 7)

Seven measures over the last 14 bars (ER over 10, the Kaufman textbook length), each mapped to a [0, 1] score by a fixed
linear ramp score = clip((x - bad) / (good - bad), 0, 1) with the anchors below (declared before any data was seen; the
reference points are textbook levels or the random-walk level):

| measure | definition | bad | good |
|---|---|---|---|
| ER | Kaufman efficiency ratio, 10 bars: abs(c_t - c_{t-10}) / sum abs(c_i - c_{i-1}) | 0.20 | 0.40 |
| CHOP | Choppiness Index 14: 100 log10(sum TR14 / (HH14 - LL14)) / log10(14) | 61.8 | 38.2 |
| PERSIST | share of the last 14 bar returns with the same sign as the 14-bar net move | 0.50 | 0.70 |
| REVERS | share of sign flips between consecutive bar returns in the last 14 returns | 0.60 | 0.40 |
| NETATR | abs(c_t - c_{t-14}) / ATR14 | 1.5 | 4.0 |
| OVERLAP | mean over 14 bars of (min(h_i,h_{i-1}) - max(l_i,l_{i-1}))+ / (max(h_i,h_{i-1}) - min(l_i,l_{i-1})) | 0.65 | 0.40 |
| WICK | mean over 14 bars of 1 - abs(c - o)/(h - l) (a zero-range bar counts 1) | 0.70 | 0.45 |

T = arithmetic mean of the seven scores (equal weights, no fitting) = "clean directional movement score" in [0, 1]. T is a
SCORE, not a probability.
Move gate M (can the move pay for the cost?): ATRp x sqrt(H) >= 2 x C0 = 0.24 (H = 4, so ATRp >= 0.12 %).
Tradeability decisions (declared grid, all counted in FDR): T40 = T >= 0.40, T50 = T >= 0.50 (primary threshold),
T60 = T >= 0.60, M, and TM = (T >= 0.50) AND M = the WAIT filter (primary). WAIT = not tradeable (or UNCERTAIN regime).

### 3.3 BUY / SELL / WAIT semantics (sections 10-11)

At each closed bar exactly one state: BUY, SELL or WAIT (mutually exclusive, categorical, rule output; no probabilities):
- D_dir: WAIT unless TM is true and the regime is not UNCERTAIN; BUY in STRONG_BULL / WEAK_BULL / BREAKOUT-up; SELL in
  STRONG_BEAR / WEAK_BEAR / BREAKOUT-down; WAIT in every RANGE regime.
- D_meta (regime-matched strategy choice): trend regimes -> BUY/SELL by regime direction when TM; BREAKOUT -> by the break
  direction when M; RANGE regimes -> fade with Bollinger z (20, k = 2.5: BUY if z < -2.5, SELL if z > +2.5) when M, else WAIT;
  UNCERTAIN -> WAIT.
Probability semantics (Q5): the rule version outputs states and scores only. No probability is fitted, so none is called a
probability and no calibration (reliability, Brier, ECE) is produced. Q5 = NOT TESTED (not applicable); stated as such.

## 4. Strategies (one fixed config each; exits TIME and ATR; 3 timeframes; 6 coins)

Reuse the v1 rule implementations (`edge_search.rules`), mid-grid parameters, fixed:
- S1 always_long, S2 always_short (signal on every bar; the non-overlap rule makes them back-to-back H-bar trades).
- TREND: S3 ema_cross 12/26 (state), S4 tsmom n = 30, S5 supertrend x3 (period 10).
- BREAKOUT: S6 donchian n = 55, S7 squeeze p = 20 (v1 F3 definition).
- MEAN-REVERSION: S8 bb_z k = 2.5, S9 rsi_extreme 25/75, S10 range_fade n = 48.
- References: these are the v1 reference families (v1 had 0 survivors; the only FDR-significant v1 effect was BTC 5m bb_z
  reversion at about a fifth of the cost). The v13 / v11 Pine logic is not re-run (R-019 rejected it standalone).
- Composites D_dir, D_meta (3.3), evaluated in Q3 only.

## 5. Questions, tests, pass rules (every rule fixed here)

C0 = 0.12 %. MDE = 2.8 x SE (80 % power, 5 % two-sided). All p-values one-sided unless stated; BH-FDR at 5 %.
Sample for forward statistics: positions idx % H == 0 (non-overlapping windows), forward window = open of bar t+1 to
close of bar t+H; entry and exit inside the window; windows touching an incomplete bar are dropped.

### Q1 — does the regime label carry information about FORWARD behaviour?
Cells = label timeframe {15m, 1h, 4h} x forward horizon H in {1, 4} bars (horizons 15m, 1h, 1h, 4h, 4h, 16h). Per coin,
per cell, per regime (own-timeframe label = primary; HTF label = secondary, separate BH family):
N (independent windows), share of bars, mean forward return g (long-signed %), hit rate P(g > 0), mean absolute move
|g|, mean forward realised volatility (sqrt of the sum of squared 5m returns in the window), share of windows with
|g| > C0, mean |g| / C0. Unconditional values on the same windows are reported next to them.
- DIRECTION information: dD = mean g(regime) - mean g(all windows). Magnitude/volatility information: ratio
  R = mean |g|(regime) / mean |g|(all) (and the same for realised vol).
- Null = circular-shift label null: the label array of the cell is rolled by a random lag (>= 7 days, 1,000 lags, fixed seed),
  inside the coin's valid window, the statistic is recomputed; this keeps the label autocorrelation and regime frequencies
  and destroys the alignment with the future. p = (1 + #{|stat| >= |obs|}) / 1001 (two-sided for dD; for R two-sided on
  log R). Also reported against the unconditional mean directly (the dD above).
- FDR family Q1-primary: BTC + ETH x 6 cells x 9 regimes x {direction, magnitude}. Other 4 coins and HTF labels: separate
  families, descriptive.
- Pass rule, DIRECTION information LEAD: for some (cell, regime) BH-adjusted p < 0.05 on BTC AND on ETH, same sign, the
  sign of dD equal in each of 2023, 2024, 2025 on both coins (years with >= 30 windows; all three must be evaluable), AND
  |mean g| >= C0 (the information is large enough to pay a round trip). Statistically present but < C0 is reported as
  "information below cost", never as LEAD. Underpowered (MDE of the cell > C0 for every cell/regime) = INCONCLUSIVE;
  else FAIL.
- Pass rule, MAGNITUDE information LEAD: BH-adjusted p < 0.05 on BTC AND ETH, same direction (R > 1 or R < 1), the same
  side of 1 in each of the three years, and R >= 1.20 or R <= 0.80 on both coins. This is descriptive volatility /
  risk information; it is NOT a directional edge and says nothing about profit.

### Q2 — tradeability filter on fixed baselines (unfiltered vs filtered)
Unit = (strategy S1-S10, timeframe, exit). Filter variants: T40, T50, T60, M, TM (3.2). The filtered trade set is the SUBSET
of the unfiltered non-overlapping trades whose signal bar passes the filter (primary; the unfiltered sequence is untouched,
so "avoided" and "wrongly skipped" are exact). A re-selected filtered strategy (signals filtered before the greedy
selection) is reported as secondary (net only).
Metrics (DISC = BTC + ETH pooled = primary; ALL6 and each coin = robustness): trades, retention, gross and net mean, PF,
max drawdown of the time-ordered cumulative net (percentage points), skipped losers (count, summed net avoided), skipped
winners (count, summed net lost), per year 2023 / 2024 / 2025 (N, gross, net), per-coin net, t, MDE.
- Random-filter null: 2,000 random subsets of the pooled unfiltered trades with the same size (same share skipped);
  p = (1 + #{null Delta >= observed Delta}) / 2001, Delta = mean net kept - mean net all. SD of the null gives
  MDE_Delta = 2.8 x SD.
- Day-block bootstrap (whole UTC days, 2,000 resamples, seed 11) of Delta -> CI90, and of the kept net -> CI95 and a one-sided
  bootstrap p (share of resampled means <= 0).
- HELPS (Q2a) = Delta > 0 AND BH-adjusted random-filter p < 0.05 (family F-help: every Q2 filter-variant test and every Q3
  gated test of DISC) AND day-block CI90 lower bound of Delta > 0 AND Delta > 0 in each of 2023, 2024, 2025 AND
  Delta > 0 on >= 4 of the 6 coins with >= 30 trades.
- PROFITABLE (Q2b) = kept N >= 100 AND net mean > 0 AND BH-adjusted bootstrap p < 0.05 (family F-profit: every kept set of
  Q2, Q3, the unfiltered baselines and the composites, DISC) AND CI95 lower bound > 0 AND net > 0 in each year AND PF >= 1.15
  AND net > 0 on >= 4 of the 6 coins with >= 30 trades.
- Unit LEAD = HELPS and PROFITABLE. Q2a verdict: LEAD if any unit HELPS and PROFITABLE; a unit that HELPS but is not
  PROFITABLE is listed as "FILTER REDUCES LOSS (exploratory)". Q2b verdict: LEAD if any unit PROFITABLE.
- Unit INCONCLUSIVE (when not LEAD): kept N < 100 or MDE (net) > C0 (Q2b), MDE_Delta > C0 (Q2a). Question verdict if no unit
  is LEAD: INCONCLUSIVE when more than half of the units are INCONCLUSIVE, else FAIL.

### Q3 — regime-matched strategy choice
Gates (a mask on the signal bar; trades are the subset of the unfiltered trades): TREND strategies (S3-S5) only when the
regime is a TREND regime AND the signal direction equals the regime direction (bull = long, bear = short); BREAKOUT
strategies (S6, S7) only in BREAKOUT with the signal direction equal to the break direction; MEAN-REVERSION (S8-S10) only in
RANGE regimes; otherwise WAIT. Mismatch control (descriptive only, not tested): the complement sets (trend strategies in RANGE
regimes, mean-reversion in TREND regimes). The composites D_dir and D_meta are separate strategies, evaluated with the
PROFITABLE rule and compared with the average of the member strategies; they have no Delta test. Same metrics, same
HELPS / PROFITABLE / INCONCLUSIVE rules and families as Q2.

### Q4 — yearly stability
Every Q1 / Q2 / Q3 effect that is a candidate (FDR-significant) is shown per year 2023, 2024, 2025 with sign. The year rules
are already part of the LEAD definitions above; Q4 verdict = LEAD if at least one effect of Q1-direction, Q1-magnitude,
Q2 or Q3 is LEAD and holds its sign in all three years; else FAIL, or INCONCLUSIVE when no year-level cell is powered
(< 30 windows / < 30 trades in a year).

### Q5 — probability semantics
See 3.3: no probabilities are produced; NOT TESTED.

## 6. Count of tests (multiple testing is acknowledged)

Q1-primary 2 x 6 x 9 x 2 = 216 tests; Q1-HTF secondary 216 (own family); robustness coins descriptive (own families).
Q2: 10 strategies x 3 tf x 2 exits x 5 filters = 300 tests; Q3: 8 strategies x 3 x 2 = 48 gated tests; F-help family = 348;
F-profit family = 60 unfiltered + 300 + 48 + composites 12 = 420 (DISC). Nothing selected in-sample is a result; the best cell
of any table is not reported as a result unless it satisfies the full LEAD rule.

## 7. Implementation, files, safety

- Code `external-predictive-engine/regime_v1/` (data, features, regime, tradeability, q1, trades, stats, runner, report),
  launcher `external-predictive-engine/run_regime_v1.py`, tests `tests/test_regime_v1.py`.
- Work dir `D:\autofinx-ml-v4\regime_v1\` (results in `results\`, progress.json, STOP file, job markers per coin, logs in
  `D:\autofinx-ml-v4\logs\regime_v1.log`). One heavy process, below-normal priority, memory guard (wait while available commit
  < 2 GB), resumable (finished coin jobs skipped), STOP file pauses, a failing job is logged and retried on restart.
- Never touches the collector (`D:\autofinx-collector`), never the .env / production server / DB. D: free space must stay
  above 30 GB (the run writes < 1 GB).
- Existing v1 / v2 / M1 code and results are not modified.

## 8. What this plan does and does not test (research-integrity wording)

Tested: whether these specific fixed rule-based regime labels and this fixed chop / tradeability score (textbook parameters)
carry forward-return, hit-rate and magnitude information on BTC / ETH 15m, 1h, 4h in 2023-2025, and whether using them as
a skip filter or as a strategy selector improves ten fixed baseline strategies after real costs, relative to a random
filter of equal strength.
Not tested / not proven: any other regime definition, other thresholds, other timeframes (5m, 1d trading), a fitted
classifier, order-flow / order-book inputs, 2026 data (sealed), live execution quality, position sizing or pause rules
in a portfolio context. A FAIL here means "this combination of fixed regime / tradeability rules did not show the
pre-registered effect", not "regimes carry no information".

## AMENDMENTS
(none yet)
