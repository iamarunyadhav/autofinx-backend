# AutoFinX Edge Search v1 — FROZEN plan (registered 2026-10-08, before any run)

Registry: RESULTS R-027 · DECISIONS D-043. The sha256 of this file is recorded in every report
(`results/PLAN_SHA256.txt`). After the first holdout read nothing in Part A or Part B may change.

- **Part A** is the owner's plan exactly as approved ("seivom, autowa run pannu, naan approved paniden").
- **Part B** is the agent's pre-registered resolution of every ambiguity (exact grids, definitions, windows,
  tests). It was written before any result existed and is binding.

---------------------------------------------------------------------------------------------------
## PART A — owner plan (verbatim)

```text
# AutoFinX Edge Search v1 — full pre-registered test, 5m / 15m / 1h / 4h (+1d low-turnover only)

Goal: find out, ONCE and for good, whether any rule family has a real edge on BTC/ETH (and
BNB/SOL/XRP/SUI as consistency checks) AFTER real costs. Run unattended in the background on the
PC (D:\autofinx-ml-v4 work dir). No orders, no API keys, public data only, never the production
server. Negative results are first-class results: record every one.

## 0. Freeze first
Write the whole plan, grids and pass rules into docs/EDGE_SEARCH_V1.md BEFORE running anything and
record its sha256 in every report. After the first holdout read, no rule/grid/threshold may change.
Reuse the existing experiment registry (R-numbers), data in D:\autofinx-ml-v4 and the existing
dev_val/holdout split if one exists; otherwise use:
  dev      2021-10-01 .. 2024-06-30
  val      2024-07-01 .. 2025-06-30
  HOLDOUT  2025-07-01 .. latest   (sealed: read through one function that logs access; ONE read per
                                   frozen candidate, never again)
Embargo between segments = longest holding horizon. SOL/XRP/SUI start 2023-10 (dev/val shorter).

## 1. Data
5m closed candles -> resample to 15m, 1h, 4h, 1d with the closed-candle rule (bar is complete only
when its last 5m bar is in). Funding history (actual, per 8h), and whatever OI / long-short ratio /
taker-flow metrics are already downloaded: report coverage and gaps per series BEFORE use. Any
feature must be causal: known at the close of the signal bar. Signal on bar close, fill at the NEXT
bar open. Run the existing leakage tests plus a new shift test (shift every feature +1 bar; a real
causal edge must not improve).

## 2. Costs (all trades, long and short, 1x leverage)
Base: taker 0.05%/side + 0.01%/side slippage = 0.12% round trip + actual funding while holding.
Stress A: slippage x2.  Stress B: entry delayed one extra bar.
Maker scenario (reported separately, never used for pass/fail): 0.02%/side, limit fills only if
price trades through the limit; unfilled signals are skipped (adverse selection included).
Report long-only and short-only separately too (Ratio Bot is spot = long only).

## 3. Rule families (small FIXED grids, max 3 values per parameter, count every config = N_trials)
F1 Trend: EMA cross, Donchian breakout, Supertrend, time-series momentum (sign of N-bar return),
   price vs MA filter.                                   (1h, 4h, 1d; 15m as stress)
F2 Mean reversion: RSI extremes, Bollinger/z-score reversion, VWAP deviation, range-edge fade
   with a regime filter.                                 (5m, 15m, 1h)
F3 Volatility: squeeze -> expansion breakout, ATR breakout, opening/session-range breakout.
F4 Time effects: hour-of-day, day-of-week, funding-settlement windows (00/08/16 UTC), weekend.
F5 Derivatives information: funding extreme (contrarian) and funding change, OI shock + price
   direction, long/short ratio extreme, taker-flow imbalance, basis/premium dislocation.
F6 Cross-market: BTC leads ETH/alts (lagged return), relative-strength rank across the 6 coins
   (long top / short bottom), ETH/BTC ratio mean reversion.
F7 Funding carry (delta-neutral spot+perp): needs spot data; model both legs' fees, basis move and
   funding paid/received; report as a separate non-directional family.
F8 ML (only on features that passed the stage-1 scan): LightGBM tradeability model first
   ("will the next H bars move more than cost?"), direction second. Same walk-forward + purge.
Benchmarks (always report): never trade, always long, always short, buy-and-hold, random entries
with the SAME trade count and holding time (1000 Monte-Carlo runs = null distribution), and the
existing v13 / v11 Pine logic (reuse R-019, do not redo).
Exits (pre-registered only): time exit after H bars; ATR stop (1.5) + time exit. No trailing.
H in {1,3,6,12,24} bars of the signal timeframe, capped at 1 day.

## 4. Stages (cheap -> expensive)
Stage 1 SCAN (vectorised, no strategy yet): for every feature/rule x coin x timeframe x H on DEV:
  Spearman IC, mean gross forward return by bucket, hit rate, effective sample size.
  Keep only items with gross edge >= 0.5x the cost wall (0.06%) AND FDR(5%, Benjamini-Hochberg over
  ALL scanned items) AND the same sign on VAL. Everything else is closed as FAIL or INCONCLUSIVE.
Stage 2 BACKTEST survivors: non-overlapping trades only (one position per symbol/timeframe),
  walk-forward folds on DEV (purged), then VAL with frozen parameters. Report per trade: gross, net,
  fees, funding, trades, win rate, PF, max drawdown, avg hold.
Stage 3 STRESS: stress A, stress B, parameter neighbours (a spike with losing neighbours = reject),
  concentration (no single month > 25% of profit), regime split (BTC 200d trend bull/bear/range),
  coin consistency (positive net on >= 3 of the coins that have enough data, or a written reason).
Stage 4 HOLDOUT: one read per frozen candidate. Then paper-forward only (4-8 weeks), no live.

## 5. Pass rules (all required, stated before running)
DEV: net/trade > 0, t >= 3.0, N >= 100 non-overlapping trades (1d: >= 40), positive in >= 60% of
folds and >= 2 of 3 calendar years, block-bootstrap 95% CI lower bound of net/trade > 0, and beats
the random-entry null at p < 0.01.
VAL: same sign, net > 0, t >= 2.0, at least half the DEV net/trade.
Stress A and B: net/trade still >= 0.
HOLDOUT: net > 0 and t >= 1.65.
Underpowered (minimum detectable edge > the edge needed to pass) = INCONCLUSIVE, never PASS or FAIL.
Report N_trials and the multiple-testing adjusted p-values next to every "winner".

## 6. Automation
Resumable background runner: a job queue on disk, one job at a time, low CPU priority, progress.json
(done/total, ETA, current job), STOP file to pause, atomic result files, skip finished jobs on
restart, log to D:\autofinx-ml-v4\logs. Do a dry run first and print the ETA. Never touch the .env,
the production DB or any server. Do not stop the runner on a single job failure.

## 7. Outputs
results/leaderboard.csv (every config, every verdict, reason code), results/by_family/*.md,
results/survivors/*.md (equity curve, trades, stress table), and REPORT.md with:
- one table family x timeframe x coin: best net/trade, verdict (PASS / FAIL / INCONCLUSIVE)
- how many configs were tested, how many survived each stage
- the cost wall per timeframe (avg |move| vs 0.12%)
- final decision: (A) at least one PASS -> paper-forward plan; (B) none -> "no tradable edge in the
  tested families" and stop; (C) the INCONCLUSIVE list and what extra data would resolve it.
Do not tune anything after seeing results. Save the result and the plan hash in the registry.
```

---------------------------------------------------------------------------------------------------
## PART B — agent's pre-registered resolution (binding)

### B1. Windows, embargo and seal (resolves §0)
- The existing sealed split exists (`v4/splits.py`, never opened for holdout), so it is reused, per-symbol:
  DEV = dev_train, VAL = dev_val, HOLDOUT = newest 15 % of anchors. BTC: DEV 2021-10-01 → 2024-09-23,
  VAL 2024-09-24 → 2026-01-03, HOLDOUT 2026-01-04 → 2026-10-06.
- The owner's alternative dates would put the HOLDOUT on 2025-07 → latest, a period that development-stage
  reports (P4A / P5) already looked at, so they would not be an untouched holdout. They are not used.
- **Global seal:** every coin is physically truncated at (min over coins of holdout start) − 1 day for all
  normal stages. No array contains a bar after that time, so no stage 1–3 code can read holdout outcomes.
  A coin's VAL ends at min(its own dev_end, the global seal).
- A trade belongs to a segment only if its entry time AND its exit time are inside it (embargo ≥ 1 day is
  already inside the existing split).
- The holdout is read only by `HoldoutGate.read(candidate_id)`: it logs plan sha + time to
  `results/holdout_reads.json` and refuses a second read of the same candidate.
- Prior exposure, stated honestly: VAL (2024-09 → 2026-01) was seen by earlier ML experiments (P4A / P5 smoke);
  none of the rule families below were ever fitted or chosen on it.

### B2. Bars, signals, fills, costs (resolves §1–2)
- 5m closed klines → one full 5m grid per coin (NaN where missing) → timeframe bars by reshaping aligned to
  UTC; a bar exists only if ALL its 5m bars exist. Funding: actual events; long pays positive rate; a trade
  pays the sum of rates with entry < event ≤ exit.
- Signal on the close of bar t → entry at the OPEN of bar t+1. Time exit at the CLOSE of bar t+H.
  ATR exit: stop 1.5 × ATR14 (of the signal bar, in % of close) from the entry price, time exit at t+H; stop is
  checked on bars t+1..t+H, stop wins within a bar, an opening gap through the stop exits at the open.
- One position per symbol × timeframe × rule: the next signal bar must be ≥ the exit bar.
- Cost per trade: 0.12 % (2 × (0.05 + 0.01)). Stress A: 0.14 %. Stress B: entry one extra bar later.
  Maker scenario reported only for survivors, never used for pass/fail.
- Leverage 1×; results in % of notional.

### B3. Rule grids (≤ 3 values per parameter; every config counts in N_trials)
H set per timeframe: 5m/15m/1h {1,3,6,12,24}; 4h {1,3,6}; 1d {1} (cap 1 day).
Discovery coins: BTCUSDT, ETHUSDT. BNB/SOL/XRP/SUI are used only for the Stage-3 consistency check.
- **F1** (tf 15m,1h,4h,1d), side = signal state, re-entered when flat:
  - ema_cross pair ∈ {8/21, 12/26, 20/50};
  - donchian n ∈ {20,55,100} (close beyond the previous n-bar high/low);
  - supertrend factor ∈ {2,3,4}, period 10;
  - tsmom n ∈ {10,30,90} (sign of the n-bar return);
  - price_vs_ma n ∈ {20,50,200} (SMA).
- **F2** (tf 5m,15m,1h):
  - rsi_extreme RSI14 lo ∈ {30,25,20}, hi = 100 − lo (long below lo, short above hi);
  - bb_z k ∈ {2,2.5,3} (20-bar z-score; long below −k, short above k);
  - vwap_dev k ∈ {1.5,2,3} ((close − UTC-day VWAP)/ATR14; long below −k, short above k);
  - range_fade n ∈ {24,48,96} (close within 10 % of the n-bar range edge while ADX14 < 20; long at the
    low edge, short at the high edge).
- **F3** (squeeze, atr_break: tf 15m,1h,4h; session_range: tf 15m,1h):
  - squeeze p ∈ {10,20,30} (20-bar Bollinger width percentile over 100 bars < p within the last 3 bars,
    then close beyond the previous 20-bar high/low);
  - atr_break k ∈ {1,1.5,2} (one-bar close change beyond k × previous ATR14);
  - session_range r ∈ {1,2,4} hours (break of the first r hours of the UTC day, after that range closes).
- **F4:**
  - hour_of_day (tf 1h, H ∈ {1,3}): hour ∈ 0..23 × side ∈ {long, short}, entry at that UTC hour's open;
  - day_of_week (tf 1d, H = 1): day ∈ Mon..Sun × side;
  - funding_settlement (tf 1h, H ∈ {1,3}): kind ∈ {pre (entry 23/07/15 UTC), post (entry 00/08/16 UTC)}
    × side. "Weekend" is covered by Saturday and Sunday in day_of_week.
- **F5** (tf 1h,4h,1d; need ctx.parquet for BTC/ETH; z = trailing 30-day z-score):
  - funding_extreme thr ∈ {1.5,2,3} (z of the last published funding over 90 events; contrarian);
  - funding_change thr ∈ {1.5,2,3} (24 h change of last funding, contrarian to its z);
  - oi_shock mode ∈ {cont, contra} × thr ∈ {1.5,2,3} (z of 4 h OI change; price direction of the last 4 h);
  - ls_global thr ∈ {1.5,2,3} and ls_top thr ∈ {1.5,2,3} (long/short ratio z; contrarian);
  - taker_flow mode ∈ {cont, contra} × thr ∈ {1.5,2,3} (z of taker-buy share of volume);
  - premium thr ∈ {1.5,2,3} (24 h z of the premium index; contrarian).
- **F6** (single-leg directional signals; pair legs are not modelled):
  - btc_leads n ∈ {1,3,6} (ETH follows the sign of the last n BTC bars; tf 15m,1h);
  - ratio_mr thr ∈ {1.5,2,3} (z of log ETH/BTC over 30 days; rich asset short, cheap asset long; tf 1h,4h);
  - rs_rank n ∈ {6,24,72} (long the coin that is the top-1 of the six by n-bar return, short the bottom-1;
    needs ≥ 4 coins with data; tf 1h,4h).
- **F7 carry** (spot + perp, tf 1h events, both legs' costs):
  - decision at a funding event k with the just-published rate r_k;
  - enter (long spot, short perp) at the NEXT hourly open if r_k ≥ in ∈ {0.01 %, 0.02 %, 0.04 %};
  - receive the later events' rates; exit at the next hourly open after an event with r < out ∈ {0, 0.005 %},
    or after 30 days;
  - cost: perp 2 × (0.05 + 0.01) + spot 2 × (0.10 + 0.01) = 0.34 % round trip (spot taker 0.10 % assumed);
  - PnL = funding + Δspot − Δperp − costs, in % of one leg's notional;
  - N min 40 per DEV, same statistics as Stage 2.
- **F8 ML** runs only for families that have ≥ 1 Stage-1 survivor, on that family's feature set, per
  (coin, tf, H) cell of the survivors. LightGBM tradeability classifier (target |H-bar return| > 0.12 %),
  trained on expanding purged folds of DEV only; trade the top 20 % of the OOF tradeability score in the sign
  of a direction classifier. No threshold is tuned. The Stage 2–4 pass rules apply unchanged.
  If there are no survivors F8 is closed as `NOT_RUN_NO_STAGE1_SURVIVORS` (a reported result).

### B4. Stage 1 (resolves §4)
- Item = (rule config, coin, tf, H). Trades = greedy non-overlapping time-exit trades inside the DEV window
  (and separately VAL). Gross edge = mean of side × forward return (%).
- p-value = one-sided t-test p for mean gross > 0 on the non-overlapping DEV trades (N < 30 → p = 1).
  BH-FDR at 5 % over ALL scanned items (every config × coin × tf × H in every family, including N < 30 items).
- Survivor = FDR-rejected AND mean gross DEV ≥ 0.06 % AND VAL mean gross > 0 with VAL N ≥ 30.
- Reported per item: N, mean gross / net, Spearman IC of side vs the forward return, hit rate,
  long-only / short-only means, SD, MDE.
- Verdicts of non-survivors: `INCONCLUSIVE` if N_dev < 30 or MDE (2.8 × SD / √N) > 0.12 % (a real edge as large
  as the whole cost could not be detected); otherwise `FAIL`.

### B5. Stage 2 (resolves §4–5)
- Each Stage-1 survivor is tested with BOTH pre-registered exits (time, ATR 1.5 + time); both count in N_trials.
- Folds: 5 equal-length time folds of the DEV window by entry time; a fold counts if it has ≥ 10 trades;
  need ≥ 3 counted folds and ≥ 60 % of them with mean net > 0.
- Years: calendar years of DEV with ≥ 15 trades; need ≥ 2 such years, with net > 0 in ≥ 2 of them.
- Block bootstrap: 2000 resamples of whole UTC-day blocks of trades; the 95 % lower bound of mean net must be > 0.
- Null: 1000 random-entry runs with the same N, the same long share, the same H and exit rule, entries thinned to
  spacing H inside the DEV window; p = (1 + #null ≥ observed) / 1001; require p < 0.01.
- N min 100 (1d and F7: 40). t = mean net / SE ≥ 3.0, mean net > 0.
- VAL (frozen parameters): mean net > 0, t ≥ 2.0, mean net ≥ 0.5 × DEV mean net.
- Failing a pass rule with N < N min or MDE_net > 0.12 % → INCONCLUSIVE, otherwise FAIL.

### B6. Stage 3 (resolves §4)
- Stress A (cost 0.14 %) and Stress B (entry one extra bar later) on DEV+VAL pooled: mean net ≥ 0 in both.
- Shift test: the signal delayed by one more bar must NOT improve mean gross by more than one SE (else
  `SHIFT_ANOMALY` = fail). The code is also unit-tested with a deliberate lookahead that must be caught.
- Parameter neighbours: configs in the same family / rule / tf / H whose ordered parameter differs by one grid
  step; ≥ 2/3 of the existing neighbours must have DEV mean net > 0 (no neighbours = not applicable).
- Concentration: DEV+VAL total profit > 0 and no calendar month's net sum > 25 % of the total.
- Regime split: BTC daily close vs its 200-day SMA at entry: bull > +5 %, bear < −5 %, else range; among regimes
  with ≥ 20 trades at least 2 must have mean net > 0 (fewer than 2 evaluable regimes → reported, not failed).
- Coin consistency: the same rule / tf / H / exit on all six coins (DEV+VAL, N ≥ 30); mean net > 0 on ≥ 3 of the
  coins that have enough data. A coin without the needed data is excluded with a written reason.

### B7. Stage 4 and decisions (resolves §4–5)
- Only candidates that pass Stages 2 and 3 are frozen and read once on the HOLDOUT: mean net > 0 and t ≥ 1.65.
- Any PASS → paper-forward plan only (4–8 weeks); no live use is implied.
- N_trials and BH-adjusted p-values (Stage 1) plus a Bonferroni-adjusted p over all Stage-1 + Stage-2 trials are
  printed next to every winner.
- Final decision (A) at least one PASS, (B) none → "no tradable edge in the tested families", (C) list of
  INCONCLUSIVE items and the extra data that would resolve them.

### B8. Runner and files
- Code: `external-predictive-engine/edge_search/` (core, rules, stages, special, reporting, runner), tests in
  `tests/test_edge_search.py`; the existing V4 tests must stay green.
- Work dir `D:\autofinx-ml-v4\edge_search_v1\` (`results/`, `progress.json`, `STOP`), logs in
  `D:\autofinx-ml-v4\logs\edge_search_v1.log`. The runner lowers its CPU priority, waits for free memory,
  and never reads the .env, the production DB or any server.
- Spot klines for F7 come from data.binance.vision (public, checksum-verified).
