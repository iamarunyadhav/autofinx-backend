# AutoFinX V5 Final Operational Acceptance Report

Date: 2026-10-16  
Status: **PARTIAL**

## Isolated database

The acceptance database was explicitly created at:

`C:\xampplast\htdocs\auto_finx\autofinx-backend\backends\storage\v5-acceptance-20261016.sqlite`

Only these research migrations were applied:

```text
php artisan migrate --database=sqlite --path=database/migrations/2026_10_16_120000_create_v5_research_shadow_tables.php --force
php artisan migrate --database=sqlite --path=database/migrations/2026_10_16_130000_create_v5_research_scanner_tables.php --force
```

The scanner was run with `DB_CONNECTION=sqlite`, the exact database path above, and `CACHE_STORE=array`. No default or production connection was used.

## Live persistence evidence

Bounded live command:

```text
php artisan v5:research-scan --live --limit=1 --persist
```

Binance public data returned BTCUSDT with closed 15m/1h/4h/24h candles and valid taker-buy base-volume ratios. Funding and open interest were explicitly unavailable. The command returned `execution_authorized=false` and a persisted `run_id`.

The isolated database then contained:

```text
v5_research_scanner_runs       2 (live) + 1 (fixture) = 3
v5_research_scanner_candidates 3
v5_shadow_predictions          3
```

The fixture refresh was deliberately repeated:

```text
php artisan v5:research-refresh --fixture --as-of=2026-05-01T00:00:00Z --limit=3
php artisan v5:research-refresh --fixture --as-of=2026-05-01T00:00:00Z --limit=3
```

Both returned the same `run_id`; row counts did not increase on the second run. This verifies idempotent run/candidate/prediction keys. Prediction authorization is represented by the research-only contract and all scanner outputs are `execution_authorized=false`; the shadow prediction table itself has no execution authorization column and is not connected to trading tables.

## Outcome maturity evidence

The deterministic historical fixture uses a forecast timestamp more than 24 hours before evaluation. Unit tests verify:

- pre-maturity evaluation is rejected;
- post-horizon close is required before an outcome is evaluated;
- UP/DOWN/SIDEWAYS outcome comparison is deterministic;
- missing market data remains pending/missing rather than fabricated;
- repeated evaluation is idempotent and linked to the original prediction.

The current live BTCUSDT prediction is not yet 24 hours old. It remains pending; no live outcome was invented.

## API and frontend

Authenticated API feature tests cover overview, latest, candidates, prediction history, and source/model status routes, including authentication rejection, a persisted candidate response, and `execution_authorized=false`. The React page consumes the same overview contract at `/v5-research`.

```text
npm run build
exit code 0
✓ tsc typecheck
✓ 3489 modules transformed
✓ built in 14.62s
```

No browser automation is available, so no screenshot or pixel-level rendering claim is made.

## Regression tests

```text
php artisan test tests/Feature/MarketIntelligence/V5ResearchApiTest.php \
  tests/Feature/MarketIntelligence/V5ResearchRefreshCommandTest.php \
  tests/Feature/MarketIntelligence/V5ResearchScannerPersistenceTest.php \
  tests/Unit/MarketIntelligence/V5 \
  tests/Unit/MarketOpportunityBrain/ModuleIsolationTest.php --compact

12 passed, 262 assertions
```

## Remaining limitations

1. API authentication requires the normal application user/auth tables, which were intentionally not created in the minimal research-only SQLite file. API behavior is verified in the isolated Laravel test database, while the live rows are verified in the minimal SQLite file.
2. The live refresh command was executed once with an isolated SQLite connection (`source_mode=binance_public`); the repeated idempotency check was performed on the deterministic fixture refresh.
3. No matured live 24-hour outcome exists yet by design.

Because these limitations remain, the correct verdict is **PARTIAL**, not COMPLETE. Production scheduling, live trading, migrations, and execution authorization remain disabled.
