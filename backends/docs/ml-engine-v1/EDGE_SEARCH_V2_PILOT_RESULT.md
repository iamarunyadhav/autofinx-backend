# Edge Search v2 PILOT - result (2026-10-08)

Plan: `EDGE_SEARCH_V2_PILOT.md`, sha256 `3be7cf03502aa730015be1f0a31251b777b1979d491d5ce401f1db63cbea3924` (recorded in `PLAN_SHA256.txt`
before any computation; re-verified identical after the PC reboot and again in the report). Machine report and CSVs:
`D:\autofinx-ml-v4\edge_search_v2\results\` (REPORT.md, items.csv / items.json, benchmarks.csv, data_quality.json, summary.json).

## Verdict: INCONCLUSIVE (as pre-stated)
60 items (10 events x BTC/ETH x 15m/1h/4h): **48 INCONCLUSIVE, 12 FAIL, 0 LEAD**. Holdout reads 0, nothing after 2025-12-31 was
read. INCONCLUSIVE is not FAIL: the 4h and most 1h items simply cannot be decided with ~85 usable days.

## Data / pipeline facts
- aggTrades 2025-10..12: BTC 169.6 M rows, ETH 225.6 M rows; every zip checksum-verified; **0 missing aggTrade ids**, 100 % complete buckets
  (26,496 per coin), no zero-trade buckets, no out-of-range or non-monotone timestamps.
- Aggregation vs the existing 5m klines: volume and taker-buy volume agree to 4e-15 (median); last price = kline close in 100 % of buckets,
  first price = kline open in 92.5-95 % (the rest differ by the kline convention). A few buckets differ by up to 4-29 % in volume
  (boundary / kline-definition effect, max ETH 29 %); medians are exact.
- bookDepth: 184 daily files, 99.97 % of buckets have a snapshot (~9.6 snapshots per bucket).
- Pilot run time (this PC, connection 0.8-5 MB/s, one process): 6 months of aggTrades 5.4 GB downloaded, aggregate 36-92 s per month,
  184 depth days ~8 min; features on disk 29 MB; raw zips deleted (raw dir empty at the end, max one zip ~1.1 GB at a time).
  One network outage and one PC reboot (15:03) interrupted the run; it resumed from the markers / `.part` file with no repeated work.
- Events: THIN_BOOK_FLOW fired only 4-5 times per coin (z(LAM) >= 2 together with |z(D)| >= 1.5 is too rare); it is INCONCLUSIVE everywhere.
  Not changed (no retuning).

## Gross (before fee) vs net (after 0.12 % + actual funding), side by side
| Horizon | what the 12 powered FAIL items show | best gross seen among the INCONCLUSIVE items (NOT a result) |
|---|---|---|
| 15m | all 11 powered items (FLOW / LARGE follow+fade both coins, VOL_EXP BTC, DEPTH_IMB BTC+ETH): gross -0.029..+0.029 %, net -0.09..-0.15 % | ETH ABSORPTION gross +0.089 (N 26) |
| 1h | only BTC DEPTH_IMB_FOLLOW powered: gross +0.009, net -0.111 | BTC LARGE_VS_SMALL gross +0.108 / net -0.011 (N 34) |
| 4h | none powered | BTC FLOW_FOLLOW gross +0.235 / net +0.115 (N 135, t 2.1, MDE 0.31); ETH DEPTH_FLOW_CONFLUENCE gross +0.52 / net +0.40 (N 26) |
- At 15 minutes, order-flow signals have gross about zero: the effect, if any, is far below the 0.12 % cost wall (a powered negative at the pilot's depth).
- The 4h "follow the flow" direction is positive on BTC (+0.235 %) and its mirror FADE is negative by construction; ETH 4h is mixed
  (FLOW_FOLLOW +0.097, LARGE_FOLLOW -0.098). With MDE 0.3-0.5 % these are in-sample numbers inside the noise; no BH-FDR rejection occurred
  (0 items), so nothing qualifies as a lead.
- Benchmarks: always-long / always-short gross within +-0.05 %, net -0.07..-0.17 % (cost + funding), as in v1.
- Maker scenario (reported only, optimistic): maker net is better by roughly the 0.08 % fee difference but still negative for all
  powered 15m / 1h items.

## Minimum detectable edge and trade counts
- MDE_gross = 2.8 SD / sqrt(N). 15m: 0.04-0.12 % (powered for most coins). 1h: 0.11-0.68 %. 4h: 0.31-1.9 %. The 0.12 % cost wall is therefore only
  resolvable at 15m in the pilot.
- Independent (non-overlapping) trades per item in 92 days: 15m 26-820 (median ~520 on the five busy events), 1h 18-230, 4h 18-135.
- **Trades a full run needs** (to detect a 0.12 % gross edge, i.e. net break-even, with observed SD): 15m 50-800; **1h 320-1,830 (median ~870)**;
  **4h 900-4,300 for the busy events (up to 5,000 for rare ones)**. For a net +0.05 % edge (gross 0.17 %) multiply by ~0.5.
- At the observed trade rates this is, per item (median / range): 15m ~0.1 y (0.03-2.7); **1h ~1.0 y (0.2-8)**; **4h ~4.8 y (1.7-62)**.
  So a 4.25-year run (2021-10..2025-12) powers 15m and 1h for the busy events, and 4h only for the busier BTC / FLOW events; rare events
  (ABSORPTION, LARGE_VS_SMALL, DEPTH_FLOW_CONFLUENCE, THIN_BOOK_FLOW) stay INCONCLUSIVE at 4h even then and would need threshold-level
  redesign (a new pre-registration), not tuning.

## How long the full run takes (extrapolated from the measured pilot)
- aggTrades 2021-10..2025-12, BTC + ETH = 102 months. Pilot averaged 0.9 GB / month download at 2-3 MB/s (older months are smaller):
  roughly 8-12 h download + ~2 h aggregate, i.e. **about 10-14 h unattended**, raw never above ~1.1 GB, features ~0.2 GB.
- bookDepth 2023-01..2025-12 = 2 x 1,095 days at ~2.6 s per day: **about 1.5 h**.
- Total about **12-16 h wall clock**, resumable per month / day. Months after 2026-01-03 are holdout territory and are refused by the pipeline.
- Pilot months are now burned for these 10 events as confirmation data (plan section 0).

## What this does and does not say
Tested: 10 pre-registered order-flow / depth events with fixed thresholds, 3 horizons, 2 coins, 92 days, taker costs 0.12 % + funding.
Result: no powered item shows a gross edge above about 0.03 %; everything slower than 15 minutes is undecidable at this sample size.
Not tested / not proven: whether order flow has a net edge at 1h-4h over several years. That is what a full run would decide.
No lead was produced, so there is no hypothesis from this pilot that deserves special weight in the full run; the full run should
re-register the same 10 events (or a revised, again frozen, list) and use the 2021-10..2024-06 DEV / 2024-07..2025-12 VAL split.

## Files
Code `external-predictive-engine/edge_search_v2/` (flow, depth, pipeline, features, events, analysis, runner), launcher
`external-predictive-engine/run_edge_search_v2.py`, tests `tests/test_edge_search_v2.py` (11 tests; whole engine suite 169 passed),
runner script `D:\autofinx-ml-v4\run-edge-v2-pilot.sh`, log `D:\autofinx-ml-v4\logs\edge_search_v2.log`.
