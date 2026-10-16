# V5 Isolated Scanner Implementation Report

Date: 2026-10-16  
Status: **PARTIAL — RESEARCH/SHADOW APPLICATION IMPLEMENTED**

## Delivered implementation

The V5 scanner is isolated under `App\Domain\MarketIntelligence\V5\Application` and does not alter `MarketOpportunityBrain` or trading modules.

* Scanner discovery, bounded ranking, and up-to-three unvalidated candidates.
* Causal closed-candle multi-horizon observations for 15m, 1h, 4h and 24h.
* Explicit regimes, supporting evidence, unavailable taker/funding/open-interest status, and uncalibrated model status.
* `execution_authorized=false` on every candidate, prediction and API response.
* Idempotent scanner run/candidate storage and persisted 24-hour uncalibrated shadow predictions.
* Read-only authenticated endpoints: overview, latest run, candidates, prediction history and source/model status.
* Manual `v5:research-scan` and lock-protected `v5:research-refresh` commands. Neither is scheduled.
* React dashboard route at `/v5-research` with stale/unavailable and no-candidate states.

Files added or changed include the application scanner/assessment services, V5 scanner models, scanner migration, V5 controller/routes, commands, V5 page/route, and V5 unit/feature tests.

## Test and execution evidence

```powershell
php artisan test tests/Feature/MarketIntelligence/V5ResearchApiTest.php tests/Feature/MarketIntelligence/V5ResearchRefreshCommandTest.php tests/Feature/MarketIntelligence/V5ResearchScannerPersistenceTest.php tests/Unit/MarketIntelligence/V5 tests/Unit/MarketOpportunityBrain/ModuleIsolationTest.php --compact
```

Result: **12 passed, 262 assertions**. The test database is SQLite in-memory;
the scanner migration was tested there and was not applied to production.

```powershell
php artisan v5:research-scan --fixture --as-of=2026-05-01T00:00:00Z --limit=3
```

Result: `FIXTUREAUSDT` persisted as an `UNVALIDATED_RESEARCH_CANDIDATES` candidate with execution disabled. Re-running the same fixture refresh is idempotent.

The existing public Binance smoke command was additionally run with process-local read-only flags and a one-symbol bounded budget. It inspected 525 USD-M symbols, selected BTCUSDT, and retrieved only closed 15-minute candles. It reported no order placement.

Frontend check:

```powershell
npm run typecheck
```

Result: passed.

## Limitations

This is a shadow research surface, not a trading product. Its ranking is a transparent heuristic, no calibrated probabilities are emitted, and prior V5 research found no validated after-cost trading edge. Historical/live funding, open interest, taker pressure, and order-book depth are deliberately reported unavailable until collected through a separate valid data contract. Outcome maturity storage exists, but automatic actual-price settlement needs a separately verified historical/current price provider; it is not fabricated.

No live orders, production migrations, active scheduler, wallet, risk, webhook, or trading-execution code changed.

**Verdict: PARTIAL.** All safely implementable scanner application work is present and tested. The remaining items require legitimate additional data collection and outcome source verification, not a new model or threshold tuning.
