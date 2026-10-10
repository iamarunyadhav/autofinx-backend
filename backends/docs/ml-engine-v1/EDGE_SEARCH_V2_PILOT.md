# AutoFinX Edge Search v2 PILOT - FROZEN plan (registered 2026-10-08, before any result exists)

Registry: RESULTS R-028 - DECISIONS D-044. Track A of `V6_DATA_AND_COLLECTOR_PLAN_2026-10-08.md`, pilot approved by the owner.
The sha256 of this file is stored in `PLAN_SHA256.txt` (next to this file and in the results folder) BEFORE any feature
or result is computed, and printed in every report. Nothing below may change after the first result is computed.

## 0. Scope (owner decision)
- Coins: BTCUSDT, ETHUSDT. USD-M futures public archive (data.binance.vision), no API key, no server, no orders.
- Pilot window: **2025-10-01 00:00 UTC .. 2025-12-31 23:59 UTC** (aggTrades monthly 2025-10, 2025-11, 2025-12; bookDepth
  daily for the same 92 days). The pipeline is built to extend (aggTrades 2021-10.., bookDepth 2023-01.., resumable per month,
  one month = one marker) but ONLY the pilot window is run now.
- **Seal:** global seal 2026-01-03 (v1). The pipeline refuses any file whose last day is after 2026-01-03 (so the monthly
  2026-01 file is never opened), the pilot additionally stops at 2025-12-31. Nothing from 2026-01-04 on is read, the holdout
  gate is not opened (holdout reads = 0). Klines / funding are read only from the 2025-10..2025-12 monthly files.
- Disclosure: 2025-10..12 lies inside the v1 VAL segment (2024-09-24..2026-01-03), which earlier OHLCV experiments saw. These
  events and features were never fitted on it, but after this pilot these months are BURNED for these 10 events: a later full run
  keeps the same events and must treat the pilot months as exploratory, not as a clean confirmation segment.
- Context data in this pilot: 5m klines (prices, entry/exit) and actual funding (cost) from `D:\autofinx-ml-v4\data`.
  OI / taker / premium / cross-asset series are NOT used by any of the 10 events (kept for the full run).

## 1. Pre-stated honest expectation
About 85 usable days is one market regime. At 1h and 4h the number of independent (non-overlapping) trades per item is
a few dozen to a few hundred, while v1 showed ~1,050 (1h) and ~3,200 (4h) independent trades are needed to detect a 0.12 %
edge. **The expected outcome is INCONCLUSIVE for most items.** INCONCLUSIVE is not FAIL, and an in-sample best number is not a
result. The pilot's value is: pipeline proven on real data, feature quality, observed SD / trade rates (hence the trade count
a multi-year run needs), and a short list of LEADS (never PASS) worth a full run.

## 2. Causal 5-minute features (closed buckets)
Bucket = UTC 5-minute interval [b, b+5m) of `transact_time`. A bucket is used only at its close (time b+5m).
Aggressor side: `is_buyer_maker == false` -> buyer aggressor (BUY), `true` -> SELL. Per bucket and coin (stored per month):
- counts n_buy, n_sell; base volume and quote volume (price*qty) per side; first / last / high / low trade price;
- delta_q = quote volume buy - sell (signed volume); delta_base likewise;
- size moments (n, sum q, sum q^2, sum q^3) -> trade-size skewness; max trade size;
- size histogram: 80 log-spaced bins of base size, edges `logspace(-4, 4, 81)`, per bin buy quote volume, sell quote volume,
  trade count;
- **large trades = top 1 % by size, causal:** the threshold bin for UTC day D is the largest bin index k with
  (trades in bins >= k)/(all trades) >= 1 % computed on day D-1 ONLY. Day D without a complete day D-1 in the data (the first
  day of a run) -> large-trade features NaN. Large signed volume = sum over bins >= k of (buy - sell quote volume). Resolution =
  one histogram bin (about 10 % wide in size), documented approximation;
- realised variance rv = sum of squared log returns of last-trade-price-per-second sampled at seconds with a trade (returns are
  attributed to the bucket of the later second; carried across bucket boundaries);
- impact: lambda = sum(ret_i * delta_i) / sum(delta_i^2) over the trailing 12 buckets (ret_i = log(last/first trade price) of
  bucket i, delta_i = delta_q_i / 1e6); a regression slope, pure past;
- id_gap: number of missing aggTrade ids (agg_trade_id must be consecutive) attributed to the bucket of the trade after the gap;
- depth (bookDepth snapshots, irregular ~30-60 s): per bucket the mean over snapshots with timestamp inside the bucket of
  bid / ask notional at +-1 %, +-2 %, +-5 %; imbalance_p = (bid - ask)/(bid + ask). No snapshot in the bucket -> NaN.
  Assumption stated: the snapshot timestamp is the time the snapshot was taken (a snapshot stamped inside a bucket is known at
  its close); the full-run check compares depth against price moves to look for a stamp offset.
- A bucket is COMPLETE if the day has all 288 buckets present in the file range and id_gap == 0 for it; incomplete buckets
  (and anything depending on them) are NaN. Buckets with zero trades inside a complete day are valid (delta 0).

Derived at bucket close t (1h = trailing 12 buckets t-11..t): D1h = sum delta_q; L1h = sum large signed volume; S1h = D1h - L1h
(small-trade delta); R1h = log(last_t / first_{t-11}); RV1h = sum rv; LAM = lambda above; DI1, DI2 = mean over the 12 buckets
of imbalance at 1 % / 2 % (needs >= 6 non-NaN buckets); require all 12 buckets complete for the flow features.
**z(x)** = (x_t - mean(x_{t-2016..t-1})) / sd(...) over the previous 7 days of the 5-minute series of x, EXCLUDING t,
needing >= 1440 finite values (so the first signals are ~5 days into the window). Std 0 -> NaN.

## 3. The 10 events (fixed thresholds, no grids, no tuning; side +1 long, -1 short)
Evaluated at every bucket close t; a signal is the state "condition true at t".
1. FLOW_FOLLOW: z(D1h) >= 2 -> +1; <= -2 -> -1.
2. FLOW_FADE: z(D1h) >= 2 -> -1; <= -2 -> +1.
3. LARGE_FOLLOW: z(L1h) >= 2 -> +1; <= -2 -> -1.
4. LARGE_FADE: opposite of 3.
5. ABSORPTION: z(D1h) >= 2 and R1h <= 0 -> -1 (buying absorbed); z(D1h) <= -2 and R1h >= 0 -> +1.
6. VOL_EXPANSION_BREAKOUT: z(RV1h) >= 2 and |z(R1h)| >= 1 -> sign(R1h) (follow).
7. THIN_BOOK_FLOW: z(LAM) >= 2 and |z(D1h)| >= 1.5 -> sign(D1h).
8. LARGE_VS_SMALL: z(L1h) >= 1.5 and z(S1h) <= -1.5 -> +1; z(L1h) <= -1.5 and z(S1h) >= 1.5 -> -1 (follow the large trades).
9. DEPTH_IMB_FOLLOW: z(DI2) >= 2 -> +1; <= -2 -> -1.
10. DEPTH_FLOW_CONFLUENCE: z(DI1) >= 1.5 and z(D1h) >= 1.5 -> +1; both <= -1.5 -> -1.
Buy/sell counts, size skew and the +-5 % depth level are computed and stored (feature set) but no event uses them; they are not
tested, so they do not enter N_trials.

## 4. Execution, horizons, costs (v1 conventions)
- **Entry:** signal on the close of bucket t, fill at the OPEN of the next 5-minute kline (t+1) (kline from the existing data).
- **Horizons (3):** 15 min (H=3 bars, the "short" one), 1h (H=12), 4h (H=48). **Exit:** time exit at the CLOSE of bar t+H. No
  stop, no trailing (the v1 ATR exit is not used in the pilot).
- One position per item: the greedy non-overlapping rule of v1 (`select_trades`): the next trade's signal bar is >= the
  previous exit bar. The window for trades: entry and exit both inside [2025-10-01 00:00, 2026-01-01 00:00].
- **Cost (base, long and short, 1x):** taker 0.05 %/side + slippage 0.01 %/side = **0.12 % round trip**, plus ACTUAL funding for
  events with entry < event <= exit (long pays positive rates, short receives).
  - GROSS = side * (exit close / entry open - 1) in %, before fees, slippage and funding.
  - NET = GROSS - 0.12 - side * funding.
- **Maker scenario (reported only, never used for a verdict):** entry is a limit at the next open, filled only if the entry bar's
  low < open (long) or high > open (short) (trade-through; unfilled = skipped, adverse selection included); the exit is assumed a
  limit at the horizon close, always filled (optimistic bound); cost 2 x 0.02 % = 0.04 % and no slippage, plus funding.
- Items = 10 events x 2 coins x 3 horizons = **60 = N_trials**. Benchmarks per coin x horizon (context only): always-long and
  always-short entered on the same non-overlapping grid, and the same gross / net accounting.

## 5. Statistics, MDE rule, verdicts (all stated before any result)
Per item: N (non-overlapping trades), mean GROSS, mean NET, SD of gross, t (gross), one-sided p (gross mean > 0),
hit rate, long-only / short-only means, first-half / second-half mean gross (halves split at 2025-11-15 12:00 UTC by entry time).
- **MDE_gross = 2.8 * SD / sqrt(N)** (80 % power, two-sided 5 %, as v1). Cost wall = 0.12 %.
- **INCONCLUSIVE:** N < 30, or MDE_gross > 0.12 % (an edge as large as the whole cost could not be detected).
- **LEAD (never PASS):** not inconclusive AND BH-FDR (5 %) rejects over ALL 60 items' p-values AND mean gross >= 0.06 %
  AND mean gross > 0 in both halves. Leads additionally get: the shift test (signal delayed one more bar must not improve mean
  gross by more than one SE), the 1000-run random-entry null (same N, same long share, same H) and a Bonferroni factor 60.
  A lead is a hypothesis for the full multi-year run, not evidence of an edge, and is never traded.
- **FAIL:** powered (not inconclusive) and not a lead.
- **Overall verdict:** any LEAD -> `LEADS_FOR_FULL_RUN (not a result)`; else if at least half of the 60 items are INCONCLUSIVE ->
  `INCONCLUSIVE`; else `NO_EDGE_IN_PILOT`. No PASS exists in the pilot.
- Reported next to every item, side by side: GROSS (before fee) and NET (after fee), plus the maker scenario columns.
- **Required trade counts (full run):** N_req(e) = (2.8 * SD / e)^2 for a gross edge e = 0.12 % (net break-even) and e = 0.17 %
  (net +0.05 %), using the observed SD; observed independent trades per year = N / 0.2521 (92 days); years needed = N_req /
  that rate. The wall-clock of a full run is extrapolated from the measured pilot throughput (MB/s download, seconds per
  month aggregated) and reported.

## 6. Pipeline rules
- Source `https://data.binance.vision/data/futures/um/{monthly|daily}/{aggTrades|bookDepth}/<SYM>/...`; every zip verified against
  its `.CHECKSUM` (sha256) before use; the zip is streamed to disk, aggregated in streaming batches (pyarrow CSV reader), then
  DELETED. Raw files live under `D:\autofinx-ml-v4\edge_search_v2\raw` and never exceed 15 GB (a guard checks size + next
  content-length before each download; free disk >= 20 GB required).
- One process, one download at a time, BELOW_NORMAL priority, waits when available commit memory < 4 GB, STOP file pauses.
- Resumable per month (aggTrades) and per day (bookDepth): a finished unit is never repeated (marker holds sha256, rows, counts).
- Output: `D:\autofinx-ml-v4\edge_search_v2\features\<SYM>\flow\<YYYY-MM>.parquet` (+ size histograms `.npz`),
  `...\depth\<YYYY-MM>.parquet`; results in `D:\autofinx-ml-v4\edge_search_v2\results\`.
- Tests: causality (a future trade never changes an earlier closed bucket; features at t unchanged when data after t is
  removed), bucket completeness, no holdout leakage (refuses post-seal files and dates), event engine vs brute force, shift test
  catches a planted lookahead.
- The v1 code, plan and results are not modified. The collector (`D:\autofinx-collector`) is not touched.
