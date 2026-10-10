# Rule Baseline v1 — pre-registered plan (frozen before any result)

Owner request 2026-10-10 (D-050, run under D-051 / R-032): ONE new, separate, explainable rule-based baseline for Quick Trade. Research only: local public Binance files,
no keys, no orders, no server / DB, no Laravel change. Frozen by sha256 in `D:\autofinx-ml-v4\rule_baseline_v1\PLAN_SHA256.txt`; the runner refuses to run if this file changes
(a later change must be an AMENDMENT section written before the affected result is seen).

## 0. HONESTY STATEMENT (read first)

**This hypothesis was chosen AFTER the owner and the agent had seen the 2023-2025 results of many other experiments on the same data (V4 / P8, Edge Search v1 / v2 pilot / M1,
Regime v1, Quick Trade Replay v1, Experiment E).** Every number in this study is therefore a **REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT.** The data is the same
development period everybody looked at; a trend-breakout rule family (M1 was a momentum-candle breakout, REJECTED; Edge Search v1 included breakout / trend rules, 0 survivors)
is not a new idea there. A LEAD here would NOT be a trustworthy edge: it would only justify a forward PAPER test (live data, no money, pre-registered), never any live use.
The sealed 2026 holdout (2026-01-04+) is NOT read (reads = 0) and is NOT used to "confirm" this either; it stays sealed.

## 1. Scope and data

- Development window 2023-01-01 .. 2025-12-31 only (warm-up from 2022-04), data cut 2026-01-01 (earlier than the 2026-01-03 global seal), through the existing restricted
  loader `regime_v1.data` (monthly files 2022-04..2025-12; `check_cut` refuses a later cut; unit-tested). Holdout reads = 0.
- Quick Trade live presets (verified in the code, `config/quick_trade.php:131-135`, `BinanceTimedFuturesProvider.php:50-55`; see `QUICK_TRADE_REPLAY_V1.md` section 0):
  **P1 = 5m chart / 3600 s (12 bars)**, **P2 = 5m / 1800 s (6 bars)**, **P3 = 15m / 3600 s (4 bars)**. All three primary.
- Coins: **BTCUSDT, ETHUSDT, BNBUSDT, SOLUSDT** = the live symbols = primary pool (whole-UTC-day blocks across coins). **XRPUSDT, SUIUSDT** = robustness, report-only (not live-supported).
  SOL / XRP / SUI start in 2023-10 (2023 is partial for them; stated limitation).
- Engine reused, no duplicate: `edge_search.core` (Coin, bars, outcomes_time validity, funding, bh_adjust, MDE_FACTOR), `regime_v1.data` / `regime_v1.features.pivots`
  (the confirmed-fractal definition), `quick_trade_replay_v1.paths` (worst-case 5m path simulator: gap / SL / TP / AMBIGUOUS_INTRABAR), `.job` (day arrays, seed sides, random-side
  variant), `.stats` (day-block bootstrap, p, MDE, BH, thresholds), `v4.strategy_v13` (EMA / highest / lowest / ATR). New code only for the rule, the candidate -> chain pipeline and the report.

## 2. The primary rule set (fixed; no tuning, no second primary)

All on the closed bars of the preset's chart timeframe (5m for P1 / P2, 15m for P3). Bar t is closed when its close time has passed; only bars <= t are used.

- **LONG** at the close of bar t when **close[t] > EMA200(close)[t]** AND **close[t] > max(high[t-20 .. t-1])** (the highest high of the previous 20 closed bars, the current bar excluded).
- **SHORT** is the mirror: close[t] < EMA200[t] AND close[t] < min(low[t-20 .. t-1]).
- **Entry** at the OPEN of the next bar (= the open of the 5m bar that starts at the signal close).
- **Stop-loss** = the price of the **most recent CONFIRMED swing**: swing low for a long, swing high for a short. Fixed fractal definition: bar j is a swing low if its low is
  strictly below the lows of the 3 bars before and the 3 bars after it (swing high mirrored); it is **confirmed only at the close of bar j+3** (`regime_v1.features.pivots`,
  k = 3) and never used earlier. At signal bar t the swing is the latest fractal with confirmation index <= t.
- **Distance guard**: stop distance (as a % of price) must be within **[0.25 %, 1.50 %]**: floor 0.25 % so the 0.12 % round-trip cost is at most 0.48 R (no cost-dominated stops),
  cap 1.50 % as the declared hard maximum (wider = no trade, never widened). Checked twice: at the signal close (strictly causal, distance to the close) and at the actual entry
  price (a gap guard: if the next open breaks the floor / cap, or is beyond the stop, the order is not placed; the count is reported). A missing / invalid swing (none yet, or on the wrong
  side of the price) = no trade. The simulation uses the distance measured at the actual entry price E: R = |E - swing| / E.
- **Take-profit** = entry +/- **2 R** (fixed 2:1).
- **Timeout exit** at the preset expiry: the close of the last 5m bar of the expiry (identical to `edge_search.outcomes_time`; unit-tested).
- **Non-overlapping** trades per (coin, preset, variant): greedy, the next trade enters at or after the previous exit time.
- **Intrabar / fills**: 5m bars; worst case: same 5m bar reaches both stop and take-profit = AMBIGUOUS_INTRABAR counted as the **stop**; a bar that OPENS beyond the stop fills
  at that open (worse than the stop, GAP); the take-profit fills at its level exactly (a favourable gap is not credited); a stop is never widened.
- **Costs**: gross and net side by side. Primary net = gross - **0.12 %** round trip (measured, D-040) - actual funding (side-signed events with entry < event <= exit).
  Also reported: **0.14 %** (QuickTradeCostModel taker) and, report-only, the **maker-entry variants 0.11 %** (code) and **0.08 %** (D-035 definition); they ignore missed fills and
  the code refuses maker entry in live, so they are optimistic. Net is % of notional; leverage scales gross and cost together and is not an edge source (not simulated).

## 3. Controls, ablations, sensitivity (all fixed now)

- **Random-direction null (matched)**: for EVERY variant, the SAME entries (the variant's own non-overlapping chain), the SAME stop distance R, take-profit 2 R, timeout, the SAME
  costs and funding model, but a **random side** (coin flip per entry, **100 seeds**, seed = crc32(coin|preset|variant), reproducible). The question it answers: does the rule choose a
  direction better than chance on the same entries and the same geometry? (A causal rule cannot beat a martingale by geometry alone; a null mean near -0.12 % is expected.)
- **Ablations** (own chain, own matched null, counted in FDR): **AB_NOEMA** (no EMA filter, swing stop), **AB_NOSWING** (EMA200 filter, stop = **1.5 x ATR14** of the signal bar as a
  fraction of the entry price instead of the swing), **AB_PLAIN** (plain 20-bar breakout, no EMA, ATR stop). Same floor / cap, 2 R, timeout.
- **Sensitivity (report-only, clearly NON-primary, counted in FDR)**: **SENS_EMA100** (EMA100 instead of EMA200), **SENS_BRK50** (50-bar breakout instead of 20). Nothing is promoted
  from these; no other parameter is varied anywhere.
- No other variant exists. No parameter is changed after a result is seen.

## 4. Statistics and verdict rules (v1 conventions, frozen)

- Unit = one trade; pool = BTC + ETH + BNB + SOL with whole-UTC-day blocks (entry day) resampled jointly; 2,000 day-block bootstrap resamples; SE_boot; CI90 = effect +/- 1.645 SE;
  one-sided p = 1 - Phi(effect / SE); MDE = 2.8 x SE (80 % power, two-sided 5 %, `MDE_FACTOR`). Effective independent count = the number of distinct (coin, day) blocks with a trade
  (the trades themselves are non-overlapping inside a coin; coins and neighbouring bars are not independent), reported next to n.
- Metrics per variant x preset (pool, per year 2023 / 2024 / 2025, per coin incl. XRP / SUI report-only): trades, gross and net mean per trade, win rate (net > 0), average win,
  average loss, profit factor (sum wins / sum losses), maximum drawdown of the cumulative net in trade order (percentage points of notional), mean R-multiple (gross / R), mean stop
  distance, exit-reason shares, funnel counts (signals -> window -> distance guard -> gap-cancelled -> trades).
- **Items.** F1 (profit): mean net per trade at 0.12 % > 0, one-sided, 6 variants x 3 presets = **18 items**. F2 (vs null): mean net of the rule minus mean net of its matched random-side null
  (paired on the same entries, day-block bootstrap jointly), 18 items. **BH-FDR q = 0.05 separately within F1 and within F2** (ablations and sensitivity are in the families). p_dir =
  (1 + #seeds with pooled mean >= the rule) / 101 is reported next to F2 (not used in the verdict).
- **Item verdict.** **LEAD** only if ALL hold: BH q < 0.05 in its family; CI90 lower > 0; effect >= **0.03 %** per trade (materiality: 0.25 x cost); MDE <= 0.06 %; effect > 0 in each of
  2023 / 2024 / 2025; effect > 0 on BTC and on ETH separately and on at least 3 of the 4 primary coins. **FAIL** when the CI90 upper bound < +0.03 % per trade and MDE <= 0.06 % (a
  meaningful effect is excluded by a powered test). Everything else **INCONCLUSIVE**. No item is promoted by a weaker rule after the results are seen.
- **Primary verdict per preset** = LEAD only if both the PRIMARY profit item (F1) AND the PRIMARY vs-null item (F2) are LEAD; FAIL if the profit item is FAIL; else INCONCLUSIVE.
  **Overall** = LEAD if any preset is LEAD (3 presets, BH already corrects), FAIL if all three are FAIL, else INCONCLUSIVE.
- A LEAD (if any) is labelled "LEAD on a reused development window; needs forward paper evidence before any trust" and is never a recommendation to trade.
- Expected outcome stated up front: a randomly-directed rule with these costs loses about 0.12 % per trade; the rule needs a gross edge above ~0.15 % per trade over its own random
  null to be net positive. FAIL / INCONCLUSIVE is a valid, safe outcome.

## 5. Tests required (before any real-data run)

Causality (prefix invariance of the signals, swing levels, stop distances; planted look-ahead caught), swing confirmation not early (a fractal is invisible before bar j+3 and visible
at j+3), SL / TP ordering and ambiguity (same bar = SL, gap at the open, TP exact, timeout), fill timing (entry at the next open, exit time, equals the engine's time exit), distance guard,
non-overlap, random-null planted-effect power (a planted trend effect is detected by the rule-vs-null item; no-effect data does not reject), no holdout read (loader refuses a 2026 cut /
file), plan-hash check, and the whole engine suite green.

## 6. Runtime and safety

Unattended, below-normal priority, one heavy process at a time, 2 GB available-commit guard (waits), STOP file `D:\autofinx-ml-v4\rule_baseline_v1\STOP`, resumable per-coin markers,
atomic outputs, D: free space > 30 GB checked before each job. The live collector on `D:\autofinx-collector` is not touched. No Laravel / Ratio Bot / Quick Trade code is modified,
no commit / push, no keys, no server, no DB. Other experiments' code and results are not modified.

## 7. Not tested (stated up front)

Other EMA / lookback / fractal / RR / floor / cap values (except the two report-only sensitivities), leverage and liquidation, real 1 s polling and exchange stop orders, partial fills,
maker fill rates, order-book impact, stake sizing, trailing / break-even exits, other presets or expiries, other coins, the 2026 holdout (sealed), and any regime / volatility gate
(the volatility finding of D-047 is a separate risk topic).

## 8. Pre-freeze feasibility look (disclosed)

Before freezing the numbers, the agent computed ONLY the distribution of the stop distance (fractal swing to close) and the signal counts for BTC and SOL, 2023-2025 (no return, no
P&L, no win rate): BTC 5m median 0.59 % (77 % inside [0.25 %, 1.50 %], 24,128 raw signals), BTC 15m median 1.14 % (62 % inside), SOL 5m median 1.34 % (57 % inside), SOL 15m median
2.46 % (19 % inside). The floor 0.25 % / cap 1.50 % were fixed on the cost argument above and were NOT changed after this look; the cap penalises the more volatile coins (a
disclosed limitation, not a tunable).
