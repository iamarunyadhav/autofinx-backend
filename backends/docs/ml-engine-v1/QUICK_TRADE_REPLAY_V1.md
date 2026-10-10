# Quick Trade Replay v1 — pre-registered plan (frozen before any result)

Owner approval: "start", 2026-10-10. Research only: local public Binance files, no keys, no orders, no server / DB, no Laravel change (read only).
Holdout (2026-01-04 on) stays sealed, reads = 0. Frozen by sha256 in `D:\autofinx-ml-v4\quick_trade_replay_v1\PLAN_SHA256.txt`; the runner refuses to run if
this file changes (a later change must be an AMENDMENT section written before the affected result is seen).

## 0. Verified Quick Trade facts (read from the real code, 2026-10-10; the prompt was not trusted)

Paths are under `autofinx-backend/backends/`.

| Fact | Code (file:line) |
|---|---|
| Live presets (the only ones the UI recommends / lists): **5m chart / 3600 s (recommended, 12 candles)**, **5m / 1800 s (6 candles)**, **15m / 3600 s (4 candles)** | `config/quick_trade.php:131-135` |
| Default live timeframe / duration when omitted: 5m / 3600 s | `TradingModuleSettingsService.php:39-40`; used at `QuickTradeService.php:65-74` |
| Binance live provider offers timeframes 1m..1d and durations 60 / 300 / 600 / 900 / 1800 / 3600 / 7200 / 14400 / 86400 s (the presets are a subset; a manual request may use any listed duration) | `BinanceTimedFuturesProvider.php:56-57`; enforced `QuickTradeService.php:133` |
| Live symbols: **BTCUSDT, ETHUSDT, BNBUSDT, SOLUSDT only** (XRP / SUI are not supported live) | `BinanceTimedFuturesProvider.php:50-55` |
| Min expiry rules (live): `min_horizon_candles` = 3 enforced for manual live (`enforce_min_horizon_candles` true); chart timeframe must not exceed expiry; expiries < 900 s hidden unless `allow_short_live_expiry`; min stake max(10, 20 USDT Binance min notional) | `config/quick_trade.php:75,103,106,142-145`; `QuickTradeService.php:123-175`; `FuturesOrderSizingService.php:7` |
| SL / TP are optional, **live only**, entered as **percent of STAKE** (SL negative, TP positive); position notional = stake x leverage (default leverage off = 1), so the price-move threshold = pct / leverage | `QuickTradeService.php:85-111,176-199`; `BinanceTimedFuturesProvider.php:181-190,254` |
| SL / TP are **software-monitored, not exchange stop orders**: `quick-trade:monitor-stop-loss` loop (default 1 s) reads a fresh live-feed price snapshot (not bar high / low), computes raw pnl% of stake, closes with a MARKET reduce-only order through `settleTrade()`; SL is checked first, TP second, never both; stale / missing price = skip the tick | `MonitorQuickTradeStopLossCommand.php:22-47`; `CheckQuickTradeStopLossJob.php:110-149`; `BinanceTimedFuturesProvider.php:320-342` |
| Timed exit at expiry: scheduler `quick-trades:settle` every 10 s settles trades with `expires_at <= now` (so the exit is 0-10 s after expiry, MARKET order) | `routes/console.php:119-121`; `QuickTradeService.php:503-533` |
| Live P&L = (exit - entry) x qty - entry fee - exit fee - funding (real exchange fee when available, estimate otherwise); status won / lost / draw with draw only if |net| <= 1e-8 (never in practice) | `QuickTradeService.php:1197-1222,1351-1361` |
| Leverage: must be >= 1; **> 20x requires a stop_loss_pct**; exchange max-leverage validation; estimated liquidation distance must be >= 3 % | `QuickTradeService.php:200-208`; `LeverageValidationService.php:32,51`; `BinanceTimedFuturesProvider.php:184-250` |
| Cost model: taker 0.05 % x 2 + slippage buffer 0.04 % = **0.14 %** round trip; maker_entry = (0.02 + 0.05) % + 0.04 % = **0.11 %** (the prompt's "maker_entry variant" is 0.11 % in code, NOT 0.08 %); `required_move_pct` = cost x `live_min_edge_cost_multiplier` 2.0 = **0.28 %** (taker); maker_entry is refused in live | `QuickTradeCostModel.php:61-87,272-282`; `config/robot_trade.php:26-28` |
| Expected-move formula: `rangePct = min(5.0, atrPct * sqrt(horizonCandles))`, max achievable move = rangePct x **0.55**, duration is "available" live only if max >= required | `QuickTradeCostModel.php:36,97-104,221`; `TradeForecastService.php:126,145` |
| Decision is on CLOSED candles only | `config/quick_trade.php:27` |
| **Paper mode**: binary payout. win = stake x payout_ratio, loss = -stake, **tie (exit price exactly equal entry) = refund (0)**. payout_ratio = symbol meta `payout_rate` else 0.91; **the default paper catalog lists BTCUSDT at 0.86** (not 0.91; GOLD 0.88, GOLD_OTC 0.91, EURUSD_OTC 0.89). Paper durations: 5 / 15 / 30 / 60 / 300 / 900 s only (the 1800 / 3600 s live presets are not paper durations); SL / TP / leverage are ignored in paper | `QuickTradeService.php:728-752,477`; `TradingModuleSettingsService.php:44,50,59-64` |
| Fees real account: maker 0.02 % / taker 0.05 % (D-040); measured round trip 0.12 % (0.05 + 0.01 slippage per leg x 2) | DECISIONS D-040 |

Caveats: production workspaces can override some settings through the `settings` table (not visible from code); the env values on the server were
not read. Prompt corrections found: BTC paper payout is 0.86 by default; maker_entry round trip is 0.11 % in code; SL / TP are % of stake and
software-polled; the live symbol list excludes XRP / SUI.

## 1. Scope

- Development window 2023-01-01 .. 2025-12-31 (warm-up from 2022-04), data cut 2026-01-01 (earlier than the global seal 2026-01-03), via the existing
  restricted loader `regime_v1.data` (monthly files 2022-04..2025-12 only; `check_cut` refuses a later cut). Holdout reads 0; nothing from 2026 is opened.
  The months 2025-10..12 were used as order-flow events in the v2 pilot (not a problem for a rule / replay study; disclosed).
- Coins: BTCUSDT + ETHUSDT = primary (all verdicts), pooled with day blocks across both coins. BNB / SOL / XRP / SUI = robustness, report-only
  (SOL / XRP / SUI start 2023-10; XRP / SUI are not live-supported).
- Expiry configs (exactly the three live presets in config): **P1 = 5m chart / 3600 s (h = 12 bars)**, **P2 = 5m / 1800 s (h = 6)**,
  **P3 = 15m / 3600 s (h = 4)**. All three are primary. For the paper binary view only, two extra paper-native expiries are added:
  PP1 = 5m chart / 300 s (h = 1) and PP2 = 5m / 900 s (h = 3) (report-only, labelled PAPER).
- Engine reused, no duplicate: `edge_search.core` (Coin / Bars / Outcomes / select_trades / outcomes_time / funding / bh_adjust / MDE_FACTOR / write_json),
  `edge_search.rules` (tsmom, bb_z signals), `regime_v1.features` (regime, tradeability T, atr %), `regime_v1.data`, `regime_v1.trades.gate`,
  `edge_search.runner` (low priority, memory guard). New code only for the SL / TP / trailing path simulator, the replay arms, per-day aggregation and the report.

## 2. Execution realism (all arms)

- Signal on a CLOSED signal-timeframe bar; entry = OPEN of the next 5m bar (the open of the bar after the signal close); no look-ahead.
- Path is simulated on the 5m bars for every preset (P3 signals use 15m bars, its path uses the 5m bars inside the 60 minutes).
- Expiry exit = close of the last 5m bar of the horizon (entry + expiry), identical to `edge_search.core.outcomes_time` (unit-tested equal).
- Non-overlapping trades: greedy chain on the X1 (expiry-only) exit times; every other exit arm is evaluated on the SAME entries and sides ("entry-matched"),
  so an early exit does not change which trades exist. (A standalone own-chain variant is not run; stated limitation: early exits would free capital
  for more trades per day, that frequency effect is not measured.)
- Funding: actual funding events with entry < event <= exit, side-signed (long pays when the rate is positive).
- Stops: for a position PnL path in price-% relative to the entry open: bar k, in order: (1) gap: if the bar OPEN is at or beyond the stop, exit at the open
  (worse than the stop); (2) stop hit if the bar's adverse extreme reaches the stop level, take-profit hit if the favourable extreme reaches the TP level;
  both in one bar = **AMBIGUOUS_INTRABAR, counted as the stop (the worse)**; stop fill = the stop level; TP fill = the TP level exactly (a favourable gap is
  not credited). (3) trailing / break-even: the stop level used in checks (1)-(2) of a bar is the level fixed at the previous bar close. After the checks, if the bar's
  favourable extreme arms the stop or raises the trailing peak, the new stop level is computed; if the same bar's adverse extreme already reaches that NEW level,
  the bar is counted AMBIGUOUS_INTRABAR and the position is stopped at the new level (adverse ordering "up then down" assumed; the share of such trades is reported);
  otherwise the new level applies from the next bar.
- A stop may only tighten: stop level (PnL space) never decreases; the trailing peak never decreases; once armed the stop never loosens (tested).
- No oracle exits: every stop / TP distance is known at the signal bar (X2, X3 from the signal-bar ATR; X4 from a quantile table that only uses windows
  completed before the start of the signal's UTC day). MFE / MAE of the trade itself are never used as exits.
- Costs: primary **C_meas = 0.12 %** round trip (D-040) + funding. Also reported for every variant (linear, derived from gross): **C_code = 0.14 %**
  (QuickTradeCostModel taker); report-only **C_maker_code = 0.11 %** (maker_entry in code) and **C_maker_meas = 0.08 %** (D-035 definition, no fill-rate or
  missed-fill modelling, so optimistic). Gross and net side by side everywhere. Net is % of notional; at leverage L the stake-% figure is L x (cost and
  gross scale together, the sign cannot change; leverage is therefore not an edge source and is not simulated beyond that statement).
- SL / TP are modelled in price-% (= stake-% at leverage 1). The live system polls a 1 s feed price and sends a MARKET close; the simulation uses the 5m bar
  extremes with the worst-case ordering and the 0.04 % / 0.01 % slippage inside the cost. A real 1 s poll is therefore sometimes better (resolves ambiguity)
  and sometimes worse (polling latency, no exchange stop) than the model; not measured.

## 3. Arms (all fixed now; no tuning afterwards)

Definitions use the signal timeframe bars (tf = 5m for P1 / P2, 15m for P3) and h = expiry bars on that timeframe (12, 6, 4).

**Direction arms**
- **A random**: coin-flip side for every entry, **100 seeds** (seeds 1..100). Run on the always-signal chain (every non-overlapping entry), all exit x gate
  combinations (cross is computed for A only).
- **B momentum**: side = sign of the h-bar return (`edge_search.rules` tsmom with n = h). Signal every bar.
- **C mean reversion**: Bollinger z, 20 bars, k = 2.0 (`edge_search.rules` bb_z): short above, long below, no trade between. Sparse.
- **D best existing V4 / V3 model**: NOT AVAILABLE. Checked: only fitted models exist under `D:\autofinx-ml-v4\models`, trained on the dev_train part of the same
  window; no persisted out-of-fold causal predictions for 2023-2025 exist. Scoring them in-window would be leakage, so the arm is not run (and D-046 closed
  directional modelling).
- **E always-long / always-short** benchmarks on the always chain.
- Matched random null for B and C: the SAME chain (entries, exits) with random sides, 100 seeds, X1 / G0 only (does the direction signal beat random direction
  on the same entries).

**Exit arms** (entry-matched to the X1 chain)
- **X1** expiry-only (no SL, no TP). Base for every paired comparison.
- Range unit R = min(5.0, atr% x sqrt(h)) in % of price, atr% = ATR14 of the signal bar / close x 100 (the same rangePct as the code's cost model); R is known
  at the signal close.
- **X2** SL / TP grid, 9 cells: SL = k x R with k in {0.5, 1.0, 1.5}; TP = r x SL with r in {none, 1, 2}. (name `X2_k{k}_r{r}`)
- **X3** trailing / break-even, 4 cells, initial SL = 1.0 R, arming when the favourable excursion reaches a x R:
  `X3_BE_a0.5`, `X3_BE_a1.0` (stop moves to break-even = entry, 0 PnL, once armed);
  `X3_TR_a0.5_d0.5`, `X3_TR_a1.0_d0.5` (stop = running peak - 0.5 R once armed, never loosens).
- **X4** SL / TP from rolling historical MAE / MFE quantiles, 3 cells: for the symbol, h and side, take every 5m-grid entry whose full horizon window completed
  strictly before the start of the signal's UTC day, over the previous 30 days; adverse excursion (MAE) and favourable excursion (MFE) of that side over the horizon;
  SL = q_s quantile of MAE, TP = q_t quantile of MFE; cells (q_s, q_t) = (0.75, 0.50), (0.90, 0.50), (0.75, 0.75). (name `X4_q{qs}_{qt}`)
- Total 17 non-base exit arms + X1.

**Gate arms** (a boolean on the signal bar, applied as a subset of the unfiltered X1 chain; avoided losers / wrongly skipped winners are exact)
- **G0** none.
- **G1a** volatility gate k = 2.0 (the code): 0.55 x R >= 2.0 x 0.14 % (= 0.28 %).
- **G1b** volatility gate declared alternative k = 1.0: 0.55 x R >= 0.14 %.
- **G2** tradeability gate: `regime_v1.features` T >= 0.50 on the signal timeframe (the v1 "T50").
- **G2m** regime-matched gate (arms A and B use the trend class: trend regime and side aligned; arm C uses the mean-reversion class: range regimes) from
  `regime_v1.trades.gate`. Not defined for E.
- **G3** low-vol compression pause: skip when the signal-timeframe regime is LOWVOL_COMPRESSION.
- Gate x exit crossing is computed for arm A (the null) only; for B, C, E gates are tested under X1 and exits under G0 (main effects). Interactions are not
  pre-registered for B, C, E.

## 4. Statistics

- Unit = one trade; pool = BTC + ETH, whole-day blocks (UTC day of entry, pooled across the two coins); 2,000 day-block bootstrap resamples; p from
  the bootstrap standard error with a normal approximation (resolution fine enough for BH): z = mean / SE_boot, one-sided p = 1 - Phi(z) (Phi = standard normal CDF).
  MDE = 2.8 x SE_boot (80 % power, two-sided 5 %; v1 convention `MDE_FACTOR`).
- **Item types.** (P) profit: mean net per trade > 0, one-sided. (D) paired delta vs the same direction arm under X1 / G0: for exits the mean of
  (net_arm - net_X1) over the same trades; for gates mean(kept) - mean(all), bootstrap with the same resampled days; one-sided "improves". (N) direction vs
  matched random seeds: p_dir = (1 + #seeds with mean >= the arm mean) / 101 on the pooled BTC + ETH mean over the same chain (B, C only, X1 / G0).
- **FDR.** Benjamini-Hochberg q = 0.05 over family F1 = all (P) and (D) items of arms B, C, E_long, E_short, A (A as an ordinary arm), exits and gates in sec. 3 across
  P1..P3 on the pooled BTC + ETH pool (expected about 650 items; the exact count is written to the result), and a separate family F2 = the A-cross null grid (A x exit x gate,
  P items, expected near 330). (N) items: separate family F3. Any FDR rejection in F2 (a "random direction + SL/TP/gate makes money") is treated as a suspected bug
  first and investigated before it is reported.
- **Verdict convention (v1 style), per (P) or (D) item, effect = mean net per trade (P) or mean delta per trade (D), CI90 = effect +/- 1.645 x SE_boot.**
  LEAD (HELPS for D, PROFITABLE for P) only if ALL hold: BH q < 0.05 in its family, CI90 lower bound > 0, effect > 0 in each of 2023 / 2024 / 2025, effect > 0 on BTC and on
  ETH separately, and MDE <= 0.06 %. FAIL when the CI90 upper bound is < +0.03 % per trade and MDE <= 0.06 % (a meaningful effect is excluded by a powered test).
  Everything else = INCONCLUSIVE (not enough power or not enough evidence). Zero tolerance: no item is promoted by a weaker rule after the results are seen.
- Per year (2023 / 2024 / 2025) and per coin (all six) for every variant; robustness coins are report-only.
- Trade counts, retention, avoided losers vs wrongly skipped winners (count and sum net), win rate, PF, mean gross, mean net, mean funding, share of
  AMBIGUOUS_INTRABAR, share exiting by SL / TP / trail / gap / time.
- **Paper binary view** (report-only, labelled PAPER): on the X1 chain of each arm and expiry (P1..P3, PP1, PP2): wins (side-correct, strictly), losses, ties (price equal,
  refund); hit rate = wins / (wins + losses) with a Wilson 95 % interval; break-even = 1 / (1 + payout) for payout 0.86 (code BTCUSDT) and 0.91 (default); mean
  result per unit stake = payout x win share - loss share. No FDR, no verdict beyond "above / below break-even".
- **Gate calibration (d)** (report-only): per preset, deciles of the predicted max move M = 0.55 R versus the realised |return| over the horizon and
  the realised best excursion; Spearman rank correlation; P(realised |return| >= 0.28 %) for gate-pass versus gate-fail bars; mean realised / predicted ratio.
  The model's direction score cannot be replicated, so only the direction-free max-achievable-move proxy is tested.

## 5. Questions and decision rules

(a) Does any exit or gate lift the RANDOM arm above 0 net? Expected NO (a martingale cannot be beaten by a causal rule; fills are worst-case). A yes means bug first.
(b) Does any exit / gate arm improve each direction arm versus its X1 / G0 version (item D, family F1)?
(c) Gates: trade retention, avoided losers versus wrongly skipped winners.
(d) Does the predicted expected move calibrate to the realised move?
(e) Which component is worth keeping as a risk / quality layer even if no edge (explicitly labelled EXPLORATORY: e.g. a gate that removes unprofitable-after-cost
    trades without a significant loss of winners, an exit that reduces drawdown / tail loss for the same mean).

## 6. Tests required (before any real-data run)

Causality (prefix invariance of the signals, features, R, gate masks and the X4 table), fill timing (entry at the next open, expiry exit = close of the last
horizon bar; X1 gross equals `edge_search.core.outcomes_time`; X1 chain equals `select_trades`), SL / TP / trailing mechanics (gap at the open, AMBIGUOUS_INTRABAR counted
as the worse, stop only tightens, trailing peak never falls), random-null planted-effect power (a planted direction effect is detected, a planted SL / TP-friendly
process is detected, no-effect data does not reject), no holdout read (loader refuses a 2026 cut; no 2026 file opened), whole engine suite green.

## 7. Runtime and safety

Unattended, below-normal priority, one heavy process at a time, 2 GB available-commit guard (waits), STOP file `D:\autofinx-ml-v4\quick_trade_replay_v1\STOP`,
resumable per-coin markers, atomic writes, D: free space > 30 GB checked before each job. The live collector on `D:\autofinx-collector` is not touched.
Hard constraints: no live trading, no keys, no server / DB, Laravel read only, no commit / push, no migrations, no v1 / v2 / M1 / regime code or result changes.

## 8. Not tested (stated up front)

Leverage dynamics and liquidation, real 1 s polling and latency, exchange stop orders, partial fills, maker fill rates, order-book impact, stake sizing,
production `settings` overrides, the Quick Trade forecast model's own direction score (not replicable without the production stack), 1m resolution of the intrabar
order, other expiries than the three presets (plus the paper view), the 2026 holdout (sealed), and arm D (no causal predictions).

## AMENDMENT 1 (2026-10-10, written after the code and tests existed, tested on SYNTHETIC data only; no real-data result of this study had been computed or seen)

Original plan sha256 `d6dc8a4ed188d03da28c0986a4b3963b49b26bb48c3df9ffd3480208b13ad00b` (kept in `PLAN_SHA256_original.txt`). The amended file is re-hashed into `PLAN_SHA256.txt`.
Reason: the key question is whether an exit / gate adds edge beyond what it does to a random direction, and the synthetic runs showed that two items of the first
draft would not answer it cleanly. Nothing below changes the arms, grids, costs, presets, windows or the family-F1 / F2 / F3 items already defined.

- **A1 matched random-direction null for B and C on every exit x gate** (not only X1 / G0): the same entries, 100 random-side seeds (as arm A, but on the arm's own chain),
  keys `Bnull` / `Cnull` (exits under G0, gates under X1). New family **F4** (difference in differences, BH separately): for arm B or C and each exit x (17) and gate g
  (G1a, G1b, G2, G3, G2m): `[mean(arm, x) - mean(arm, X1)] - [mean(arm-null, x) - mean(arm-null, X1)]` (gate: kept minus all, the same difference for the null), pooled BTC + ETH,
  day-block bootstrap of all four terms jointly. F4 is the formal test "the component adds edge beyond reshaping the distribution". The F1 delta items
  ("improves versus its own X1 / G0") stay, but a LEAD there can be purely mechanical; only F4 (and F2 staying empty) supports the claim "adds edge".
- **A2 materiality**: a LEAD additionally requires effect >= 0.03 % per trade (0.25 x the cost). Reason: entry-matched paired deltas have a tiny standard error, and a
  fill-model artefact (a stop credited at its exact level) of about +0.005 % per trade was measured on synthetic martingale paths (0.0061 / 0.0046 / 0.0006 % at 10 / 30 / 100
  path steps per bar, i.e. it vanishes as the path gets finer; real fills are worse, never better). Such an effect is statistically detectable but meaningless.
- **A3 risk statistics (report-only, question e)**: for the deterministic arms (B, C, EL, ES), per coin, for every exit under G0 and every gate under X1: worst trade, 1 % and 5 %
  quantile of the net per trade, standard deviation, maximum drawdown of the cumulative net in trade order (percentage points of notional), at C_meas.
- **A4 trailing / break-even bias disclosure**: the declared "up then down" ambiguity convention penalises X3 on a random walk (measured about -0.015 % per trade for
  `X3_TR_a0.5_d0.5` on synthetic data); this is a conservative bias, not a bonus, and it is reported as such.
