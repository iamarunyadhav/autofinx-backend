# Edge Search v1 — result (2026-10-08)

Written for: the owner. The full machine report is `D:/autofinx-ml-v4/edge_search_v1/results/REPORT.md`
(leaderboard.csv, by_family/, survivors/, stage files, data coverage, holdout log).

**Decision: (B) no tradable directional edge in the tested families after real costs; (C) the slow
timeframes are INCONCLUSIVE (not enough independent trades), they are not proven negative.**

- Plan sha256 `d6485a10de852eb89658bf9fe29bc89424e397caf57306596e0f3c582f197125` (EDGE_SEARCH_V1.md, frozen before the run).
- Run: 527 s, 0 job errors; 149 tests passed; the engine matched an independent brute-force implementation exactly
  on real data (4,347 / 4,347 trades).
- Sealed: all data cut at 2026-01-03; **holdout reads: 0**. Nothing reached Stage 4, so the holdout stays unopened.

| Funnel | Count |
|---|---|
| Stage-1 items (config x coin x tf x H) | 1,844 |
| Verdicts: FAIL / INCONCLUSIVE | 938 / 906 |
| BH-FDR(5 %) rejects | 5 (4 with gross < 0.045 %, i.e. below the 0.06 % floor; 1 underpowered with a VAL sign flip) |
| Stage-1 survivors | **0** |
| Stage 2 / 3 / 4, F8 ML | not reached (F8: NOT_RUN_NO_STAGE1_SURVIVORS) |
| F7 carry cells | 12: 2 FAIL, 10 INCONCLUSIVE, 0 PASS |
| N_trials | 1,856 (Bonferroni factor for any winner) |

## What the data says
1. **5m / 15m (powered, FAIL):** average moves are only 0.9-1.9 x the 0.12 % cost and no rule has a gross edge
   anywhere near it. The only FDR-significant effect is BTC 5m Bollinger reversion (t 4.8, gross +0.007..+0.025 %): real but
   about a fifth of the cost at best.
2. **1h:** mostly FAIL (434 of 913); 479 items underpowered.
3. **4h / 1d (INCONCLUSIVE):** moves are 6-20 x the cost, so a rule only has to be modestly right, but the history yields
   only ~150-300 independent trades per rule, versus ~3,200-6,300 needed to detect a 0.12 % edge. Several
   in-sample 'winners' (funding extreme, OI shock, ETH/BTC ratio: +0.8..+1.2 % gross, N 40-90) have BH-adjusted
   p >= 0.2 and flip sign on VAL, i.e. look like noise.
4. **F7 funding carry (delta-neutral):** below 0.02 % funding it loses (costs 0.34 % > funding received). At
   0.02 %+ it earned +0.9..+1.3 % per trade (all six coins agree in sign) but only in three euphoric rallies
   (2021-Q4, 2023-10..2024-04, 2024-Q4); the latest episode lost money. ~11 independent episodes, ~3 % a year on one leg
   at best. INCONCLUSIVE, an interesting but thin, regime-dependent and operationally risky lead.
5. Always-long / always-short lose -0.04..-0.2 % per trade at every timeframe (cost + funding); the funding paid by
   longs is visible on the slow timeframes.

## What would change the answer (list C)
- More independent observations for 4h / 1d: more coins (all 6 are already cheap to add as 'discovery') and, above
  all, more years. Five years of BTC / ETH cannot test a 4h or daily rule at the 0.12 % level.
- Information that is not OHLCV / funding / OI: order-book depth and liquidations (recording them live starts the clock),
  real fill data (spread, slippage, latency).
- For carry: all six coins with several more years, and a pre-registered episode-level test.

Files: `EDGE_SEARCH_V1.md` (frozen plan), `edge_search/` code, `tests/test_edge_search.py`.
