# AutoFinX V5 Shadow Dashboard Final Verification

Date: 2026-10-16  
Verdict: **PARTIAL — core research application verified; two operational gates remain explicitly unclaimed**

## Build

From `autofinx-front/frontends`:

```text
npm run build
✓ typecheck (tsc -p tsconfig.app.json --noEmit)
✓ 3489 modules transformed
✓ built in 14.62s
exit code 0
```

The existing Browserslist and large-chunk messages are warnings only. The V5 route is included in the production bundle.

## API and dashboard wiring

`/v5-research` is a protected React route in `src/App.tsx`, rendered by `src/pages/V5ResearchPage.tsx`. The page calls the authenticated `/api/v5/research/overview` endpoint and displays candidate direction/status, unavailable-source state, and the execution-authorized flag.

The authenticated API feature test verified unauthenticated rejection and read-only responses with `execution_authorized=false`. The five V5 routes are present:

```text
GET /api/v5/research/overview
GET /api/v5/research/latest
GET /api/v5/research/candidates
GET /api/v5/research/predictions
GET /api/v5/research/status
```

No browser automation or screenshot capture is available in this environment, so a pixel-level rendered screenshot is not claimed. The route, component, API contract, and production bundle were verified.

## Scanner and persistence

`v5:research-scan --live` completed against Binance public REST with a bounded ten-symbol universe and returned BTCUSDT, ETHUSDT and ZECUSDT as the requested three candidates. The raw output is `storage/v5-live-scan.json`. Closed candles and taker-buy base-volume mapping were validated; funding and open interest remain explicitly unavailable.

`ResearchScanner::persist()` uses the isolated V5 tables and unique run/prediction keys. Fixture persistence tests verified duplicate runs do not create duplicate records. Every prediction stores a 24-hour maturity timestamp and remains pending until maturity.

## Refresh commands

Fixture refresh:

```text
php artisan v5:research-refresh --fixture --as-of=2026-05-01T00:00:00Z --limit=3
{"run_id":"...","execution_authorized":false,"candidate_count":1,"source_mode":"fixture"}
```

The command now supports `--live`, uses the same bounded Binance adapters, and has a cache lock that rejects overlapping runs. A live refresh was not persisted against the developer's default database because production-like persistence must not be attempted without an explicitly isolated database connection. This is an intentional safety blocker, not a fabricated success.

## 24-hour outcome handling

The shadow prediction contract rejects pre-maturity evaluation (`outcome_available_at`) and distinguishes pending/missing-data from evaluated outcomes. Existing unit tests cover before-horizon rejection and post-horizon direction evaluation. The newly created live run is less than 24 hours old at this verification, so no matured live outcome exists and none is invented.

## Tests

```text
php artisan test tests/Feature/MarketIntelligence/V5ResearchApiTest.php \
  tests/Feature/MarketIntelligence/V5ResearchRefreshCommandTest.php \
  tests/Feature/MarketIntelligence/V5ResearchScannerPersistenceTest.php \
  tests/Unit/MarketIntelligence/V5 \
  tests/Unit/MarketOpportunityBrain/ModuleIsolationTest.php --compact

12 passed, 262 assertions
```

This includes API authorization, scanner persistence/idempotency, fixture refresh, outcome maturity, research-only flags, and MarketOpportunityBrain isolation.

## Remaining blockers

1. A live refresh persisted into a separately configured isolated database was not run; doing so against the default connection would violate the production-safety boundary.
2. No matured live 24-hour outcome is available yet because the live prediction horizon has not elapsed. The system correctly leaves it pending.
3. No browser screenshot was captured; UI evidence is route/component/build and authenticated API test evidence.

Scheduled execution remains disabled, and no trading path was changed. Therefore the operational milestone is **PARTIAL**, not COMPLETE.
