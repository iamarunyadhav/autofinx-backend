# Market Context module: plan

Status: plan + Phase 1 spec. Shadow-only. Nothing in trading reads this module's output.

## 1. What it is

An independent module that turns public news, macro events and market-structure
metrics into a **point-in-time, evaluated context snapshot per instrument**, for
crypto first and designed so forex / gold are configuration plus a few adapters.

It is deliberately NOT a "LLM says BUY/SELL" agent. Its job is:

1. **Collect** public data (fixed registry, lawful sources, bounded).
2. **Normalize + dedupe + timestamp** it (event time vs the time we first saw it).
3. **Classify** it (rules first, LLM only where rules cannot; schema-validated).
4. **Aggregate** it into a snapshot: sentiment, risk level, drivers, freshness.
5. **Evaluate** it against what the market actually did next (forward returns,
   volatility, adverse moves), net of costs, with honest `INSUFFICIENT_DATA`.
6. **Serve** it read-only (API, Nexus observation, ML feature export).

Consumers (Ratio Bot veto, ML features) are a later phase and only after step 5
shows the signal is real. The reference project (CrewAI stock-research-agent-api)
has steps 1, 3 and a recommendation, but no evaluation; step 5 is the point of this
module.

## 2. Input / output contract

Input

```
ContextQuery {
  assetClass: CRYPTO | FOREX | METALS
  instrument: canonical key ("BTC", "ETH", "XAU", "EURUSD")
  asOf:       UTC instant. Only data first seen <= asOf may be used (point-in-time).
  lookback:   duration (default 24h)
}
```

Output (`schema_version` 1, pure function of stored rows and `asOf`, so a backtest
reproduces the live value exactly)

```
ContextSnapshot {
  instrument, assetClass, asOf, schemaVersion
  freshness:  { state: fresh|stale|unavailable, newest_item_age_s, sources: [{id, status, age_s}] }
  sentiment:  { score: -1..1 | null, confidence: 0..1, n_items, method }
  risk:       { level: LOW|MEDIUM|HIGH|CRITICAL, flags: [...], veto_hint: bool, reasons: [...] }
  metrics:    { fear_greed, funding_rate, open_interest_change_pct, long_short_ratio, ... }   // whatever is available
  drivers:    [ { event_id, title, category, severity, impact, published_at, source, url } ]   // top N
  provenance: { classifier_versions: [...], snapshot_computed_at }
}
```

`veto_hint` and `risk` are advisory data. Nothing here places or blocks an order.

## 3. Principles (non-negotiable)

- **Shadow-only.** No trading path reads this module until an evaluation report
  passes pre-registered criteria and a human enables a consumer.
- **Point-in-time correctness.** Every row has `published_at` (source's claim)
  and `first_seen_at` (when we stored it). Backtests filter on `first_seen_at`.
  Backfilled history uses the source's publish time as `first_seen_at` only for
  sources whose history is genuinely timestamped (documented per source).
- **Untrusted text.** Titles and bodies are data. Never executed, never an
  instruction. LLM input is delimited, the model has no tools, output is parsed
  against a fixed schema and discarded if invalid.
- **Fixed source registry.** HTTPS only, no user-supplied URLs, no credentials in
  URLs, 3 s connect / 8 s total timeout, 256 KiB body cap, bounded item count,
  redirects off unless the registry explicitly allows a host. Same limits as
  `Nexus\NexusSourceService`.
- **Independent.** Own namespace (`App\Domain\MarketContext`), own tables
  (`mctx_*`), own config (`config/market_context.php`), own feature flag (default
  OFF). It does not modify Ratio Bot, ML, Intelligence, FutureTrade or Nexus core.
  The one allowed touch is an additive Nexus observation hook.
- **Honest outputs.** Missing data is `unavailable`, never a fabricated neutral.

## 4. Architecture

```
collectors (adapters)  ->  normalizer/deduper  ->  mctx_events / mctx_metrics
                                                      |
                              classifier (rules -> LLM) -> mctx_classifications     [Phase 2]
                                                      |
                                      snapshot builder (pure, as-of) -> mctx_snapshots
                                                      |
                evaluator (forward returns, lift, IC)  -> mctx_evaluations          [Phase 2]
                                                      |
              API (read-only) | Nexus observation | ML feature export (featuresAsOf)
```

Events and metrics are **global public data** (not workspace-scoped): one fetch
and one classification serve every workspace. Workspace scoping applies only to
API access and settings.

### Asset-class neutrality

An `AssetProfile` (config-driven) maps a canonical instrument to what is relevant
to it: aliases/keywords, macro drivers, relevant central banks, which collectors
apply, quote/base decomposition.

| Instrument | Aliases / entities | Macro drivers | Extra collectors (later) |
|---|---|---|---|
| BTC, ETH, SOL ... | name, ticker, chain | Fed, CPI, ETF flows, regulation | derivatives metrics, Fear & Greed, exchange announcements |
| XAU (gold) | gold, XAU, bullion | Fed, real yields, USD, geopolitics | FRED yields, CFTC COT |
| EURUSD | EUR, USD, ECB, Fed | ECB + Fed decisions, CPI/NFP both sides | economic calendar, COT |

Adding an instrument = a profile entry (+ collectors if its sources are new).
`EURUSD` decomposes to `[EUR, USD]` so one central-bank event feeds many pairs.

### Efficiency (the production host is small: 4 cores, ~7.5 GB, load already high)

- No new daemon. Scheduled commands, interval >= 10 min, `withoutOverlapping(N)`
  with a SHORT expiry (the default 24 h lock once froze the Ratio Bot schedules).
- Conditional GET (ETag / If-Modified-Since) and per-source refresh intervals.
- Dedupe by `dedupe_key = sha256(source|external_id or normalized title)`; unique index.
- Classify once per event globally; cache by content hash; relevance prefilter so
  most items never reach an LLM; batch calls; daily token budget; circuit breaker
  per source and per provider.
- Chunked queries, bounded memory, indexes on `(instrument, first_seen_at)`.
- Retention: raw body purged after N days, normalized rows and classifications kept.

### Database rules (learned the hard way on this repo)

- Use `dateTime()` columns, **not** `timestamp()`: on the production MariaDB a
  first `TIMESTAMP NOT NULL` silently gets `ON UPDATE CURRENT_TIMESTAMP` and its
  value is rewritten on every UPDATE (this corrupted `market_ohlc.ts`).
- Store `published_at`, `first_seen_at`, `as_of` as UTC wall time with
  `App\Casts\UtcDateTime`; bind UTC Carbons (`->utc()`) in `where()` clauses.
  The app timezone is Asia/Colombo and the host is +08, so a bare Carbon is wrong.
- Models extend `BaseModel` (UUIDv7 ids, so id order is time order).
- Migrations are written and reviewed but never applied by the agent.

## 5. Data sources

Crypto, Phase 1 (verify reachability and terms with curl before enabling; leave
`enabled=false` and say so if a source is unreachable or its terms forbid use):

| Source | Kind | History | Notes |
|---|---|---|---|
| alternative.me Fear & Greed | metric, daily | back to 2018 | free; backtestable immediately |
| Binance public futures: open interest, long/short ratio, taker ratio, funding | metric | limited (~30 d for ratios); funding already in `ml_funding` | reuse `BinanceRestCircuitBreaker` throttle |
| Binance announcements (listings/delistings/maintenance) | events | live only | exchange-incident / delisting risk |
| Fed, ECB press RSS | events | live only | already in Nexus registry; reuse the URLs |
| CoinDesk / Cointelegraph RSS | events | live only | headline-level only |

Phase 2+: GDELT (free global news with a native tone score and years of history,
the main way to backtest news sentiment now instead of waiting weeks), FRED
(yields), CFTC COT (weekly, FX and gold), an economic-calendar adapter.

## 6. Classification (Phase 2)

Stage 1, deterministic: alias/entity match -> instruments; keyword taxonomy ->
category (reuse the `ExternalContextTaxonomy` constants read-only); severity prior.
Stage 2, LLM, only for relevant items that rules cannot settle: strict JSON schema
`{instruments[], category, sentiment -1..1, impact, horizon, confidence, rationale<=200 chars}`,
cheapest suitable model (provider abstraction over the existing `services.ai.*` /
`intelligence.ai_trading.*` config; Claude Haiku-class for routine classification),
`temperature 0`, `classifier_version` and prompt hash stored so results are
reproducible and re-runnable.

## 7. Evaluation and backtest (Phase 2, the missing piece)

For each instrument and horizon (1h, 4h, 24h):

- Label every snapshot (hourly `asOf`) with forward return, realized volatility and
  maximum adverse excursion from existing candles (`market_ohlc`, `ml_candles`).
- **Risk/veto value:** do HIGH/CRITICAL windows show higher forward volatility or
  worse adverse moves than baseline? Report lift, sample size, confidence interval.
- **Sentiment value:** rank correlation (IC) of the score vs forward return, hit
  rate, net of the real cost assumption (0.18 % round trip), chronological
  walk-forward folds, t-statistic.
- **Pre-registered pass criteria** are written down BEFORE looking at results
  (config), reuse the spirit of `ClassifierPromotionPolicy`.
- `INSUFFICIENT_DATA` below minimum sample size. Never a fabricated PASS.

Expectation to set honestly: news sentiment on liquid crypto is usually weak and
short-lived. The realistic win is risk context (veto / widen thresholds around
scheduled macro events and incidents), not alpha. The evaluator decides.

## 8. Delivery

- Read-only API: `GET /api/v1/workspaces/{workspace}/market-context/{assetClass}/{instrument}`
  (workspace membership check copied from `RatioBotController`).
- Additive Nexus observation: current snapshot summary + latest evaluation report.
- ML feature export: `featuresAsOf(instrument, ts)` for the Python training
  pipeline (Phase 3, only if evaluation passes).
- Ops: `market-context:verify` health command in the style of `ratio-bots:verify`
  (PASS / WARN / FAIL, exit code 1 on FAIL).

## 9. Phases

| Phase | Scope | Trading effect |
|---|---|---|
| 1 | Foundation: config, migrations, contracts, collectors (F&G, Binance metrics, announcements, RSS), ingestion, rules-only snapshot, API, Nexus hook, verify command, tests | none |
| 2 | Classification (rules + LLM), snapshot upgrade, evaluator + backtest, historical backfill (F&G, funding, GDELT), reports | none |
| 3 | Frontend panel, ML feature export, evaluation review | none |
| 4 | Forex / gold profiles and collectors (central banks, COT, FRED, calendar) | none |
| 5 | Optional consumer toggles (Ratio Bot event veto), default OFF, only if Phase 2 criteria pass | opt-in, human enabled |

## 10. Phase 1 acceptance

- Full backend suite green (baseline at time of writing: 1930 passed); frontend untouched.
- Every new class has tests; collectors are tested against recorded fixtures with
  `Http::fake`, no live network in tests.
- Dry-run safe: with the flag OFF the module does nothing and costs nothing.
- Point-in-time test: a row first seen after `asOf` never appears in that snapshot.
- Timezone test under Asia/Colombo (config + `date_default_timezone_set`) for every
  timestamp read/write.
- `market-context:verify` reports PASS/WARN/FAIL correctly on fixtures.
- Zero diff in Ratio Bot, ML, Intelligence, FutureTrade; Nexus diff is the single
  additive observation hook.
