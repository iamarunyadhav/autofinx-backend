# AutoFinX V5 Live Data End-to-End Verification

Date: 2026-10-16  
Status: PARTIAL (live read-only scan verified; matured outcomes and production persistence remain intentionally bounded)

## Scope and safety

This verification used the isolated V5 research module only. No production migration was run, no scheduler was enabled, and no order, wallet, risk, webhook, or execution service is referenced. Every scanner candidate returned `execution_authorized=false` and is labelled `UNVALIDATED_RESEARCH_CANDIDATES`.

## Files changed

- `app/Console/Commands/V5ResearchScanCommand.php` — added explicit `--live` mode using the existing Binance public universe and closed-candle adapters; fixture mode remains the default.
- `app/Domain/MarketOpportunityBrain/Adapters/BinancePublicSnapshotProvider.php` — corrected Binance kline mapping: index 9 is taker-buy **base** volume (index 10 is quote volume), matching the candle base-volume denominator.
- `app/Domain/MarketIntelligence/V5/Application/MarketAssessmentService.php` — records causal reference close and taker buy ratio/imbalance when present; unavailable sources remain explicit.

The prior isolated V5 services, models, routes, migrations, React page, tests, and report were preserved.

## Real Binance run

Command (process-local safety/configuration only):

```powershell
$env:AUTOFINX_MOB_ENABLED='true'
$env:AUTOFINX_MOB_PUBLIC_DATA_ENABLED='true'
$env:AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS='10'
$env:AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE='500'
php artisan v5:research-scan --live --limit=3 > storage/v5-live-scan.json
```

The request completed against Binance public REST endpoints. The bounded universe contained ten liquid eligible symbols; the scanner returned the requested top three: **BTCUSDT, ETHUSDT, ZECUSDT**. The raw JSON artifact is `storage/v5-live-scan.json` (generated locally; not a production record). All three had 15m/1h/4h/24h closed-candle assessments. The reported 15m taker-buy ratios were approximately 0.4501, 0.5420, and 0.5583 respectively, with imbalance defined as `2 * ratio - 1`.

The adapter enforced completed-candle timestamps. Funding and open interest were returned as `unavailable`; no values were fabricated. The assessment remains uncalibrated and non-probabilistic.

## Persistence and API evidence

The isolated SQLite feature tests apply the V5 research migrations and verify idempotent scanner-run/candidate and shadow-prediction persistence. Authenticated read-only routes are:

`/api/v5/research/overview`, `/latest`, `/candidates`, `/predictions`, `/status`.

The API feature test confirms unauthenticated access is rejected and authenticated responses expose `execution_authorized=false`. Persistence tests confirm repeating the same fixture run does not duplicate rows. No live run was persisted into a production database.

## Shadow outcomes

Predictions are stored with a 24-hour `outcome_available_at`; the evaluator contract refuses evaluation before maturity and stores explicit pending/missing-data status. The current live run is newer than 24 hours at verification time, so there is no legitimate matured live outcome to report. This is a time-based pending state, not a success claim.

## React verification

`src/pages/V5ResearchPage.tsx` is wired to the authenticated V5 overview endpoint and displays candidate status, directions, stale/error state, and the research-only execution flag. `/v5-research` is protected in `src/App.tsx`. `npm run typecheck` passed. `npm run build` reached Vite production transformation after the typecheck; the command was bounded by the local execution timeout, so a clean process exit is not claimed here. The generated `dist/` directory exists, but this is not treated as proof of a completed build.

## Tests

```text
php artisan test tests/Feature/MarketIntelligence/V5ResearchApiTest.php \
  tests/Feature/MarketIntelligence/V5ResearchRefreshCommandTest.php \
  tests/Feature/MarketIntelligence/V5ResearchScannerPersistenceTest.php \
  tests/Unit/MarketIntelligence/V5 \
  tests/Unit/MarketOpportunityBrain/ModuleIsolationTest.php --compact

12 passed, 262 assertions
```

The isolation test remains green. Fixture scanner, refresh lock/idempotency, API authorization, prediction maturity, and research-only assertions all passed.

## Remaining blockers / verdict

This is **PARTIAL**, not COMPLETE: (1) no verified matured 24-hour live outcome exists yet; (2) funding/open-interest collectors are not implemented because the current public snapshot contract does not supply validated point-in-time values; (3) full frontend Vite completion needs a longer bounded build run. The live read-only data path itself is reproducibly verified, and scheduled execution remains disabled.
