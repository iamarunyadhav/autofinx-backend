# V6 plan: richer data in two parallel tracks (agent research + recommendation, 2026-10-08)

Status: **PROPOSAL, not started.** Written for the owner (and any reviewer such as Codex). Everything below was
measured today with public, key-less requests (HEAD probes of data.binance.vision, a 100 s public websocket sample,
public REST). Nothing was collected or installed for a long run.

Owner proposal (summary): Track 1 "V6 research" now with the data that already exists; Track 2 a live collector (depth,
spread, trades, liquidations, OI) for about a month, observed separately and in parallel; the target is
"net return after cost of a specific event under exact execution rules", not "next-candle direction".

## 1. What history really exists for free (Binance USD-M public archive, probed 2026-10-08)
| Data | BTCUSDT / ETHUSDT history | Size | Verdict |
|---|---|---|---|
| aggTrades (every aggregated trade with aggressor side) | 2020-01 → 2026-09, no gaps, monthly | ~0.5 GB / month / coin (zip) | **6 years of exact order flow**: signed volume, trade counts, large-trade imbalance, impact. Already usable. |
| trades (raw) | 2020-01 → 2026-09 | ~0.9–1.2 GB / month | same information as aggTrades, heavier |
| bookDepth (±1..5 % depth snapshots) | 2023-01-01 → today, daily, **99.9 % of days** (longest gap 3 days) | ~0.5 MB / day | **3.75 years of coarse book liquidity / imbalance** |
| bookTicker (best bid / ask) | only 2023-05 → 2024-04 | 4.4 GB / month | short window, too heavy; not worth it |
| liquidationSnapshot | **not in the archive (0 days)** | – | must be recorded live |
| metrics (OI, long/short ratios) | BTC 2020-09 →, ETH 2021-12 → (≈ 90 % of days) | tiny | already used (R-024) |
| premiumIndexKlines, fundingRate, markPriceKlines | 2020-01 → today | small | already used |
| spot klines 1h | full | small | used for F7 carry |

Correction to the premise "full free history is difficult": order flow (6 y) and coarse depth (3.75 y) are available;
only fine-grained book, spread, liquidations and real fills must be collected.

## 2. Live collector facts (measured, public streams, 100–130 s samples)
- **Binance split the futures websocket paths.** The legacy `wss://fstream.binance.com/stream?...` URL delivers only
  *public* streams (bookTicker, depth); `aggTrade`, `markPrice` and `!forceOrder@arr` **never arrive on it, with no error**.
  They arrive on `/market/stream?streams=...` (and `/market/ws/<stream>`); bookTicker / depth on `/public/stream`.
  A collector must verify per stream that messages actually flow and alarm otherwise.
- Message rates (quiet window; volatility multiplies them):
  | stream | rate | raw JSON / day |
  |---|---|---|
  | bookTicker BTC + ETH | ~508 msg/s | ~8.9 GB (so aggregate to 1 s in the collector) |
  | depth20@500ms BTC + ETH | 3.5 msg/s | ~0.31 GB |
  | aggTrade, 6 coins | ~25 msg/s | ~0.43 GB |
  | markPrice@1s, 6 coins | ~5.5 msg/s | ~0.11 GB |
  | !forceOrder@arr (all symbols) | ~0.17 msg/s | negligible (~15 k events/day) |
- Public REST works from the PC (openInterest ~0.5 s per call).
- Estimated compressed storage with 1-second bookTicker aggregation: **< 0.5 GB / day, ≤ 15 GB / month** (D: has 96 GB free).
- Python deps: the research venv has no websocket library. Use a **separate** venv for the collector.

## 3. Assessment of the owner's two-track plan
**Good, with four corrections.**
1. **The target is right.** "P(net return > 0 | event, exact execution rules)" is what the Edge Search machinery tests.
2. **Track 1 has more data than assumed** (section 1), so it is the stronger track: 6 years of aggressor-signed flow
   beats a 1-month live book. It needs the aggTrades aggregation (download → aggregate per 5 min → discard, keep features only).
3. **One month of live data cannot give a statistical verdict.** Edge Search v1 showed that a 1h rule needs ≈ 1,050 independent
   trades and a 4h rule ≈ 3,200 to detect a 0.12 % edge. A month of events is a pilot: data quality, collector reliability, a
   pre-registered descriptive check, and real **execution** measurements (spread, depth, maker-fill adverse selection). The
   decisive test needs 6–12 months or a combination with the archive features.
4. **Pre-register before looking.** Same discipline as v1: a frozen plan (events, grids, thresholds, FDR, INCONCLUSIVE rule),
   sealed holdout (2026-01-04 → still never read), no tuning.

## 4. Recommended structure
**Track A (starts first, ~3–4 days): "Edge Search v2" on the archive.**
- Build 5-minute order-flow features from aggTrades (2021-10 → 2026-09, BTC / ETH): signed volume and delta, buy / sell trade
  counts, large-trade (top 1 % size) signed volume, trade-size skew, price impact per signed volume, realised variance.
- Build depth features from bookDepth (2023-01 →): imbalance at ±1 / 2 / 5 %, their changes. Dev window for those = 2023 →.
- Combine with funding, premium, OI, taker, cross-asset context into a small pre-registered event grid (volatility expansion,
  OI shock with delta divergence, breakout with absorption, extreme move reversion, ...), ≤ ~10 events × 2 coins × 3 horizons.
- Run with the v1 pipeline (FDR over all items, MDE rule, Stage 2–4, one holdout read per frozen candidate).

**Track B (parallel, separate process): live collector.**
- Streams: aggTrade (6 coins), !forceOrder@arr, markPrice@1s (6), bookTicker→1 s aggregate (BTC, ETH), depth20@500ms (BTC, ETH),
  REST openInterest every 60 s (6). Append-only gzip per hour, a heartbeat file, per-stream message counters, reconnect with gap log,
  periodic clock-offset check, daily integrity report.
- Where it runs: **not on the production trading server.** Best: a small separate VPS (about USD 5 / month), always on.
  Fallback: the PC with sleep / hibernate off, plugged in, auto-start at boot; gaps are logged (the PC restarted unexpectedly
  twice this week).
- After ~30 days: pilot analysis only (quality, descriptive event study, execution realism), then decide whether to continue to 6+ months.

## 5. Expectation
Order flow and depth improve short-horizon prediction in the literature, but after a 0.12 % round trip the chance of a
tradable edge stays modest and unproven; the honest outcome may again be "no edge". The value is that each track either finds
one or closes the question with proper evidence, and Track B's execution data makes any later cost estimate real.

## 6. Decisions needed from the owner
1. Approve Track A (Edge Search v2 pre-registration + aggTrades / bookDepth feature build). Download volume ≈ 60 GB over a few hours, nothing kept except features.
2. Approve Track B and choose where it runs (separate VPS recommended, or this PC).
3. Confirm the 30-day pilot is a pilot (no pass / fail claims from it).
