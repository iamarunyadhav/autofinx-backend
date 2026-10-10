# Quick Trade Replay v1 — result (2026-10-10)

Written for: the owner. Plan `QUICK_TRADE_REPLAY_V1.md` (frozen sha256 `d6dc8a4e…ad00b`, then AMENDMENT 1 before any real-data result, final sha256
`ec434d43cad9ecc5eb4cf2e7f2b3f9e605464fa6e16052b4587406c404885bc7`, both in `D:\autofinx-ml-v4\quick_trade_replay_v1\`). R-030, D-048. Machine report with every table:
`D:\autofinx-ml-v4\quick_trade_replay_v1\results\REPORT.md` (+ `items.csv`, `by_coin.csv`, `gates.csv`, `reasons.csv`, `tails.csv`, `binary.csv`, `calibration.csv`, `summary.json`).

**Headline: in a historical replay of Quick Trade decisions on 2023-2025, no exit rule (stop / take-profit / trailing / break-even / MAE-MFE-quantile stops) and no gate
(volatility gate in the code's form, a looser one, tradeability, regime-match, low-vol pause) produced a profitable futures trade or a material improvement over what it does
to a random direction. Every one of the 2,337 variant x coin rows with >= 1,000 trades has negative net P&L, including at the maker-entry cost. The random-direction null stays at
about -0.12 % per trade (= the round-trip cost) under every exit and gate. Stops reshape the loss distribution (they cut the worst trades by 60-80 %) but not the mean; the
code's volatility gate removes 29-60 % of entries without changing the per-trade result. The only above-break-even number is in the PAPER binary view: a Bollinger-fade
direction (mean reversion) hits 52-57 % versus a 53.8 % (payout 0.86) break-even, report-only, decaying 2023 to 2025, and it is a sign effect with ~zero gross magnitude, so it
does not carry over to the futures live mode.**

## 1. Verified Quick Trade facts (read from the code, quoted in `QUICK_TRADE_REPLAY_V1.md` section 0; the prompt was not trusted)

- **Live presets** (`config/quick_trade.php:131-135`): 5m chart / 3600 s (recommended, default, 12 candles), 5m / 1800 s (6), 15m / 3600 s (4). The Binance provider offers more
  durations (60 s .. 86,400 s; `BinanceTimedFuturesProvider.php:56-57`) but only these three are presets. Min rules: >= 3 candles inside the expiry, chart timeframe <= expiry,
  expiries < 900 s hidden, min stake 20 USDT (`config/quick_trade.php:75,103,106,142`; `QuickTradeService.php:123-175`).
- **Live symbols: BTC, ETH, BNB, SOL only** (`BinanceTimedFuturesProvider.php:50-55`); XRP / SUI are research robustness only.
- **SL / TP**: optional, live only, entered as **% of stake** (SL negative, TP positive; notional = stake x leverage, so price move = pct / leverage;
  `QuickTradeService.php:85-111,176-199`; `BinanceTimedFuturesProvider.php:184-190,254`). They are **software-polled**, not exchange stop orders: the `quick-trade:monitor-stop-loss`
  loop (default every 1 s) reads a fresh live-feed price (not bar high / low), SL first then TP, and closes with a MARKET reduce-only order (`CheckQuickTradeStopLossJob.php:110-149`,
  `MonitorQuickTradeStopLossCommand.php:22-47`). A stale price skips the tick. Expiry exit = `quick-trades:settle` every 10 s, MARKET (`routes/console.php:119`).
- **Leverage**: >= 1, **> 20x requires a stop-loss**, exchange-validated, estimated liquidation distance >= 3 % (`QuickTradeService.php:200-208`, `LeverageValidationService.php:32,51`).
- **Cost model**: taker 0.05 % x 2 + 0.04 % slippage buffer = **0.14 %**; `required_move_pct` = 2.0 x 0.14 = **0.28 %**; displacement 0.55; rangePct = min(5, atr% x sqrt(candles))
  (`QuickTradeCostModel.php:36,61-104`; `TradeForecastService.php:126,145`). **Correction to the prompt: the code's maker_entry round trip is 0.11 % (0.02 + 0.05 + 0.04), not 0.08 %**
  (0.08 % is the D-035 research definition); maker_entry is refused in live. Measured real cost 0.12 % (D-040) = the primary cost here.
- **Tie handling**: paper = exit price exactly equal to entry is a refund; live = P&L based, a draw only if |net| <= 1e-8 (`QuickTradeService.php:728-752,1351-1361`).
  **Correction: the paper payout for BTCUSDT in the default catalog is 0.86, not 0.91** (0.91 is the fallback; `TradingModuleSettingsService.php:44,63`; `QuickTradeService.php:477`).
  Paper durations are 5 / 15 / 30 / 60 / 300 / 900 s only, so the 1800 s and 3600 s live presets are not paper durations.
- Not verifiable from code: production workspace `settings` overrides and server .env values.

## 2. What was run

- Window 2023-01-01 .. 2025-12-31, data cut 2026-01-01 (before the 2026-01-03 global seal); **holdout reads 0**; restricted loader (`regime_v1.data`, monthly files up to 2025-12; unit-tested to refuse
  later cuts). The 2025-10..12 months were used as order-flow events in the v2 pilot (disclosed; not an issue for a rule replay).
- Coins: BTC + ETH (all verdicts, pooled with day blocks), BNB / SOL / XRP / SUI robustness (report-only; SOL / XRP / SUI begin 2023-10, first days burn-in-dropped for the X4 table).
- Presets: P1 5m/3600 s (12 bars), P2 5m/1800 s (6), P3 15m/3600 s (4); paper-native PP1 5m/300 s, PP2 5m/900 s for the binary view only.
- Entry arms: A random (100 seeds), B momentum (tsmom n = h), C Bollinger fade (z 20, k = 2.0), E always-long / always-short. **D (best V4/V3 model) not available**: only fitted models exist,
  trained on part of this window; no out-of-fold causal predictions were persisted, so using them would leak (and D-046 closed directional modelling).
- Exits: X1 expiry only; X2 9 SL/TP cells (SL 0.5 / 1.0 / 1.5 R, TP none / 1 / 2 x SL, R = min(5, atr% sqrt(h)) the code's rangePct); X3 4 BE / trailing cells; X4 3 causal rolling-30-day MAE / MFE quantile cells.
  Gates: G1a (code, k = 2), G1b (k = 1), G2 (T >= 0.5), G3 (LOWVOL_COMPRESSION pause), G2m (regime-matched). Fill: signal on a closed bar, entry at the next 5m open, expiry exit at the close of
  the last horizon bar (= `edge_search.outcomes_time`, unit-tested identical), path on 5m bars, gap fills at the worse open, same-bar SL + TP = AMBIGUOUS_INTRABAR counted as the stop,
  stops only tighten, actual funding, non-overlapping X1 chain with every exit evaluated on the SAME entries.
- Statistics: day-block bootstrap (2,000), one-sided p from the bootstrap SE, MDE = 2.8 SE, BH-FDR per family: **F1** 633 items (324 profit + 240 exit delta + 69 gate delta; arms A, B, C, EL, ES), **F2** 306 items
  (random arm x every exit x gate), **F4** 126 difference-in-differences items vs the matched random-direction null (B, C; AMENDMENT 1), **F3** 6 direction-vs-null items. Primary cost 0.12 %, also 0.14 % (code), 0.11 % and 0.08 % (maker, report-only).
- Engine reused (`edge_search.core`, `edge_search.rules`, `regime_v1.*`); new code `quick_trade_replay_v1/`, launcher `run_quick_trade_replay_v1.py`, tests `tests/test_quick_trade_replay_v1.py` (25 tests, whole engine suite 212 passed).
  Runtime: the whole pipeline took about 6 minutes (6 coin jobs of ~30 s, analysis 37 s).

## 3. Verdict per question

| Question | Verdict | Evidence |
|---|---|---|
| (a) Does any exit / gate lift the RANDOM arm above 0 net? | **NO** (as expected; no bug) | F2: 306 items, 0 net > 0, 0 FDR rejections; best net -0.110 %, worst -0.168 %. Gross of the random arm = +0.0001 %. Random-arm exit deltas: SL / TP / MAE-MFE cells -0.001..+0.0007 %, trailing / BE -0.0050..-0.032 % (the declared adverse-ordering convention penalises them, a conservative bias). |
| (b) Does any exit / gate improve a direction arm vs its own X1 / G0? | **NO material improvement** | F1: 633 / 633 FAIL (CI upper < +0.03 % with MDE <= 0.06 %); 0 LEAD. 7 exit deltas have q < 0.05 but are 0.002-0.005 % per trade (ES / B under P2 stops), i.e. 2-4 % of the cost. Best gate delta +0.0097 % (q 0.47). |
| (b2) Does it add edge beyond reshaping (DiD vs the matched random-direction null)? | **NO** | F4: 126 / 126 FAIL, 0 LEAD. 8 have q < 0.05; the largest are C-arm break-even +0.019 % (P1 / P3) and +0.011 % (P2) versus the null, but in absolute terms C with BE is WORSE than C at X1 (net -0.139 vs -0.116 %); the DiD is mostly the null's penalty. Nothing reaches the +0.03 % materiality bar. |
| Direction arms beat random direction on the same entries? | **NO after cost; sign effect only** | F3: B is below its matched null (hit 47.6 %, net -0.123 vs -0.120 %, p 0.86-0.93); C slightly above (net -0.116 vs -0.119 %, P2 p 0.020, q 0.12). Gross mean per trade is within +-0.01 % for every arm. |
| Is any arm profitable net? | **NO** | 324 / 324 profit items FAIL; 0 of 2,337 by-coin rows (n >= 1,000, non-random) have net > 0 at 0.12 %, 0 at the maker 0.08 %. Best row: SUI 15m/3600 s C fade, gross +0.047 %, net -0.073 %. |
| (c) Gate retention and avoided losers vs wrongly skipped winners | **Gates cut trades, not the per-trade loss** | G1a (code) keeps 64 / 40 / 71 % of B entries (P1 / P2 / P3; 62 / 38 / 71 % for C): avoided losers vs wrongly skipped winners 14,238 : 4,969 (P1 B), 48,607 : 14,619 (P2 B), i.e. the same ~2.9 : 1 as the unconditional loser : winner mix, and net per kept trade is unchanged (B P1 -0.1243 vs -0.1232 %). G1b keeps 82-96 %; G2 41-76 %; G3 87-93 %; G2m 18-34 %. |
| (d) Does the expected-move gate calibrate? | **Yes as a volatility size predictor, not as a profit predictor** | Spearman(M, realised abs return) 0.32-0.42 (best excursion 0.56-0.67); mean realised / predicted = 0.85-0.94 (BTC P1 0.91, over-predicts at the top decile 0.64 vs 0.83, under-predicts the bottom 0.113 vs 0.098). Gate pass vs fail: P(abs return >= 0.28 %) BTC P1 0.50 vs 0.21 (ETH 0.55 vs 0.23); P(>= 0.14 %) with G1b 0.65 vs 0.28. The model's direction score is not replicable here. |
| (e) Keep any component as a risk / quality layer? | **Stops yes, gate as a trade-count filter maybe, trailing / BE no (EXPLORATORY)** | See section 5. |

## 4. Gross vs net, per year, per coin (BTC + ETH pooled, X1, G0, % per trade)

| preset | arm | n | gross | net 0.12 | net 0.14 | net maker 0.08 | MDE | net 2023 / 2024 / 2025 |
|---|---|---|---|---|---|---|---|---|
| P1 5m/3600 | B momentum | 52,604 | -0.003 | -0.123 | -0.143 | -0.083 | 0.007 | -0.124 / -0.122 / -0.123 |
| P1 | C fade | 24,892 | +0.004 | -0.116 | -0.136 | -0.076 | 0.013 | -0.124 / -0.110 / -0.115 |
| P1 | always long | 52,608 | +0.007 | -0.114 | -0.134 | -0.074 | 0.009 | -0.111 / -0.113 / -0.120 |
| P1 | always short | 52,608 | -0.007 | -0.126 | -0.146 | -0.086 | 0.009 | -0.129 / -0.127 / -0.120 |
| P1 | A random (100 seeds) | 5.26 M | +0.0001 | -0.120 | -0.140 | -0.080 | 0.001 | -0.120 / -0.120 / -0.120 |
| P2 5m/1800 | B / C / A | 105,200 / 31,283 / 10.5 M | -0.002 / +0.007 / +0.0002 | -0.122 / -0.113 / -0.120 | | | 0.004 / 0.009 / 0.0004 | stable, none above -0.107 |
| P3 15m/3600 | B / C / A | 52,600 / 12,472 / 5.26 M | -0.004 / -0.004 / -0.0002 | -0.124 / -0.124 / -0.120 | | | 0.008 / 0.021 / 0.0007 | stable (C 2025 -0.141) |

Every year is negative for every arm and preset. The other four coins (SOL / XRP / SUI start 2023-10) are the same: net -0.073 .. -0.136 %, no positive row. 0.14 % (code cost) is worse by exactly 0.02 %.
Exit-arm effects on net (P1): SL / TP cells within +-0.007 %; MAE / MFE-quantile cells within +-0.008 %; BE / trailing -0.005 .. -0.035 % (worse). Power: delta MDE median 0.0035 % (exit), 0.0027 % (gate), 0.0048 % (DiD) versus a 0.12 % cost,
so the study could see effects of a few hundredths of a percent; it saw none that matter.

## 5. Risk-layer view (exploratory, BTC P1, per-trade net %, `tails.csv`)

| arm | exit / gate | net | worst trade | 1 % quantile | 5 % quantile | sd |
|---|---|---|---|---|---|---|
| B | X1 (expiry only) | -0.124 | -7.35 | -1.51 | -0.79 | 0.498 |
| B | X2 SL 0.5 R + TP 2 SL | -0.121 | -2.62 | -0.89 | -0.61 | 0.378 |
| B | X2 SL 1.0 R | -0.124 | -3.98 | -1.24 | -0.77 | 0.468 |
| B | X4 SL q90 MAE + TP q50 MFE | -0.124 | -1.45 | -1.04 | -0.76 | 0.293 |
| B | X3 trailing a1.0 d0.5 | -0.133 | -3.98 | -1.23 | -0.77 | 0.421 |
| EL | X1 / X4 q90 | -0.113 / -0.118 | -6.38 / -1.45 | -1.65 / -1.08 | -0.82 / -0.79 | 0.500 / 0.299 |

- **Stops cut the tail and leave the mean alone.** A causal, volatility-scaled stop (X2 / X4) cuts the worst hour from -7.4 % to -1.5..-2.6 % of notional and the standard deviation by 25-40 %,
  at an unchanged mean (the exit-arm net is within 0.01 % of X1). The cumulative drawdown barely moves (3,260 vs 3,260 % points over 26k trades) because it is dominated by the 0.12 % cost bleed per trade.
  Worth keeping as a RISK layer (never as an edge). Take-profit adds nothing. Trailing / break-even lowers the mean by 0.005-0.035 % (convention-penalised) and is not worth it.
- **The code's volatility gate (G1a)** removes 29-60 % of entries and every removed trade has the same expected net, so the per-trade result is unchanged (-0.124 vs -0.125 %); what it changes is the NUMBER of
  trades paying 0.12 % each, i.e. it reduces total bleed, and it does remove the flat-market hours where the realised move is below the required 0.28 % (P(>= 0.28 %) 21 % vs 50 %). Keep as a quality / risk filter,
  not as a profit gate. G2 / G3 / G2m add nothing (G2m, the regime-match gate, even removes 66-82 % of trades with no gain; for C it makes net worse -0.128 vs -0.116 %).
- The only "WAIT is right" statement the data supports: because every trade has an expected net of about -cost, the best policy of these arms is to trade less.

## 6. Paper-mode binary view (REPORT-ONLY, labelled PAPER; payout 0.86 = BTCUSDT code default, 0.91 = fallback; tie = refund; hit = wins / (wins + losses), Wilson 95 %)

| BTC + ETH | hit B momentum | hit C Bollinger fade | hit always-long | hit random (100 seeds) | break-even |
|---|---|---|---|---|---|
| 5m / 300 s (PP1) | 48.65 % | **56.42 %** [56.05, 56.78] | 50.14 % | 50.00 % | 53.76 % (0.86) / 52.36 % (0.91) |
| 5m / 900 s (PP2) | 47.87 % | **56.56 %** [56.08, 57.04] | 50.32 % | 50.01 % | same |
| 5m / 1800 s (P2) | 47.81 % | **56.64 %** [56.09, 57.19] | 50.81 % | 50.02 % | same |
| 5m / 3600 s (P1) | 47.64 % | **55.00 %** [54.38, 55.62] | 50.82 % | 50.03 % | same |
| 15m / 3600 s (P3) | 47.55 % | **55.82 %** [54.95, 56.69] | 50.82 % | 49.99 % | same |

- The C fade is above both break-evens in every preset (EV per unit stake +0.023 .. +0.054 at 0.86, +0.05 .. +0.08 at 0.91), positive for BTC and ETH, and above the matched-null seeds (null seeds 48.7-51.0 %). B momentum is below 50 %:
  short-horizon moves reverse more often than they continue. The same sign effect shows for BNB / SOL / XRP / SUI at 52-56 % (around the 0.86 break-even).
- **Caveats (important):** report-only and not in any FDR family (5 presets x 5 arms x 2 payouts looked at, and the presets are the same Bollinger events seen at different holds); the Wilson interval ignores clustering of extreme events
  (the true interval is wider); the C hit rate **decays** (P1 BTC + ETH 56.8 -> 54.9 -> 53.3 % over 2023 / 2024 / 2025; 2025 is below the 0.86 break-even for P1); a binary payout rewards the sign of any move, however small, while the
  fade's mean gross move is ~+0.004 % (it wins small and loses large), which is exactly why the same trades lose 0.12 % per trade as futures. Paper entry / exit price semantics (quote at placement, `priceAtOrAfter` at expiry) are not
  replicated bar-for-bar. This is a hypothesis for a pre-registered PAPER forward test, not a result.

## 7. What was tested / not tested

Tested: the three live presets (plus two paper-native expiries for the binary view), fixed causal arms and grids exactly as frozen, worst-case intrabar, actual funding, four cost levels, BTC + ETH primary and four robustness coins, 2023-2025 per year.
Not tested: leverage dynamics / liquidation (only noted: leverage multiplies gross and cost alike so it cannot create edge, and > 20x needs a stop), real 1 s polling latency and the market-close slippage of a software stop (the model uses the stop level and the
0.01 % slippage inside the cost; real fills can be worse), exchange stop orders, maker fill rates (maker rows ignore missed fills and are optimistic), partial fills, stake sizing, production setting overrides, the Quick Trade forecast model's own direction score,
1m intrabar resolution (same-bar conflicts are counted as the worse), other expiries, other exit parameters (the grids are fixed; a different grid was not searched), a D arm (no causal V4 predictions), and the 2026 holdout (sealed). A FAIL means these frozen
rules did not show the pre-registered effect; it does not prove that no stop / gate parameterisation or entry signal has value. Known test-found artefacts: a stop credited at its exact level is optimistic by about 0.005 % per trade on a 5 s synthetic path (vanishes with a finer
path; real fills are worse), and the declared "up then down" ambiguity convention penalises trailing / break-even by 0.005-0.03 % per trade; both are far below the cost. Pooled BTC + ETH are correlated (day blocks used); P1 and P3 share the same entry chain for the E arms; P2 (6-bar) rows overlap P1 information.

## 8. What to do next for Quick Trade

1. **Live (futures timed trade): do not rely on any direction + exit / gate combination for profit.** Every tested combination loses the round-trip cost (~0.12 % per trade, ~0.14 % at the code's own cost model) and no component changes that. Keep the existing safeguards
   (the fee-aware WAIT gate, `block_live_against_decision`) because they reduce the number of costly trades; do not market them as an edge.
2. **A stop-loss is worth recommending as a risk control** (it cuts the worst hour by 60-80 % at no cost to the mean): the code already supports `stop_loss_pct` (% of stake). A volatility-scaled value (about 1 R = atr% x sqrt(candles) in price terms = R x leverage in stake-%) is the replayed form;
   note that the code's stop is software-polled, so a gap or feed outage is not covered.
3. **Paper trading readiness:** the replay engine is ready to compare paper outcomes with a known baseline: the random-direction null at the paper payout is -4.5 % (0.91) / -7.0 % (0.86) of stake per trade (EV of a 50 % hit rate). The paper-mode mean-reversion hit rate (section 6) is the one
   hypothesis worth a pre-registered PAPER forward test (separate plan; the C fade fires ~690 times per month across BTC + ETH at 5m / 3600 s, i.e. a Wilson half-width of ~3.7 % per month and ~2.2 % per quarter, so a quarter is needed to tell 55 % from 53 %), but it must not be confused with a live futures edge.
4. Any change to a live path, any flag, any model install is NOT part of this study and needs your decision.

## Files

Plan `QUICK_TRADE_REPLAY_V1.md` (+ AMENDMENT 1) and `D:\autofinx-ml-v4\quick_trade_replay_v1\PLAN_SHA256.txt` (original in `PLAN_SHA256_original.txt`); code `external-predictive-engine/quick_trade_replay_v1/` (paths, arms, job, stats, analysis, report, runner), launcher
`run_quick_trade_replay_v1.py`, tests `tests/test_quick_trade_replay_v1.py`; results `D:\autofinx-ml-v4\quick_trade_replay_v1\results\` (arrays/ per coin, items.csv, REPORT.md, ...); log `D:\autofinx-ml-v4\logs\quick_trade_replay_v1.log`.
