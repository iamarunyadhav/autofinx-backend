# AutoFinX V5 Phase 2 — Historical Ingestion & Direction Research

Date: 2026-10-16  
Mode: offline research only. Phase 3 is not started.

## Implemented components

- `audit/v5_phase2/ingestion.py`: ZIP/CSV Binance Vision reader, SHA-256 provenance, OHLCV validation,
  1h interval-gap detection, duplicate-safe SQLite import and import summaries.
- `audit/v5_phase2/calibration.py`: direction confusion matrix, accuracy, balanced accuracy, and optional
  Brier/ECE calculations. Brier/ECE remain `None` when probabilities are absent; no probabilities are invented.
- `audit/v5_phase2/tests/test_phase2.py`: schema idempotency and calibration contract tests.

The adapter is isolated from Laravel production services. It does not call Binance live endpoints, place orders,
or mutate existing trading tables.

## Real dataset import

Input: `D:\autofinx-v4-fresh-research-v2-20261015-213345\raw`  
Symbols: BTCUSDT, ETHUSDT, SOLUSDT  
Period: October–December 2025  
Timeframe: 1h OHLCV plus funding-rate archives

Import summary:

| Dataset | Rows imported | Duplicates | Gaps | Malformed |
|---|---:|---:|---:|---:|
| OHLCV | 6,624 | 0 | 0 within each monthly archive | 0 |
| Funding | 828 | 0 | not asserted as candle intervals | 0 |

All three symbols had 2,208 candles and 276 funding rows. The previous fresh audit contains the individual
archive hashes; this adapter also records each source SHA-256 in SQLite. Re-running the same imports is idempotent
because `(symbol, timeframe, open_ms)` and `(symbol, funding_ms)` are primary keys.

## Existing algorithm integration status

The phase reuses contracts without rewiring production algorithms:

- `app/Domain/RatioBot/MultiWindowTrendScoringService.php`: source-faithful AutoFinX trend replay remains in
  the existing V4 harness.
- `app/Domain/Market/Indicators/Indicators.php`: existing indicator implementation remains the source of truth.
- `app/Domain/MarketOpportunityBrain/Forecast/ForecastGateway.php` and `ChronosBatchRunner.php`: existing
  fail-closed Chronos boundary remains research-only.
- `app/Domain/Ml/Shadow/ShadowFeatureBuilder.php` and `PatternFeatures.php`: not silently replaced.
- `app/Domain/MarketContext/Classification/RuleClassifier.php`: existing context classifier remains separate.

No simplified substitute is wired into a production decision path.

## Prediction/output status

This phase created ingestion and evaluation infrastructure; it did **not** claim new V5 forecast accuracy.
There are zero newly persisted V5 predictions from this offline test because the database migration was not applied
and no live/production scheduler was enabled. This is intentional and prevents fabricated results.

The prior fresh V4 evidence remains the only measured comparison: source-faithful AutoFinX and Chronos-2 both had
negative after-cost test results; Chronos quantiles were not converted into probabilities.

## Calibration and point-in-time rules

- Outcome evaluation must occur at or after `outcome_available_at`; the PHP `ShadowOutcomeEvaluator` rejects early
  labels.
- `probability_calibrated=true` requires supplied probabilities.
- Without probabilities, Brier and ECE are explicitly unavailable.
- The untouched final holdout from the prior research remains protected; this phase did not tune against it.

## Tests executed

```powershell
cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
python -m unittest discover -s 'autofinx-backend\backends\audit\v5_phase2\tests' -v
```

Result: **3 passed**.

Phase 1 PHP contracts remain green: **4 passed / 5 assertions**. The existing V4 research suite was not modified.

## Database changes

Pending migration only:

`database/migrations/2026_10_16_120000_create_v5_research_shadow_tables.php`

It defines `v5_market_observations` and `v5_shadow_predictions`. It was not executed against any real database.

## Known limitations

- Only the verified 1h archive is ingested; 5m/15m/4h/1d archives were not present in this manifest.
- Historical order book, open interest, liquidation and taker-volume datasets are not yet validated here.
- No 15-minute scheduler or live collector was added.
- No V5 model comparison, calibration score, or profitability claim is made.
- ECE implementation is a minimal research diagnostic and requires a predeclared binning protocol before use as a
  promotion gate.

## Next recommended phase (requires approval)

Add offline multi-horizon feature/label generation from the imported SQLite observations, using chronological
development/validation/final-holdout partitions. Then run existing AutoFinX, naive, persistence and pinned
Chronos-2 through the same labels and report accuracy, confusion matrices, and calibrated metrics where valid.
Only after that should a disabled shadow refresh command be proposed. Do not begin Top-Three ranking yet.

## Safety verdict

Phase 2 ingestion foundation is tested and idempotent. No live trading, production scheduler, wallet, execution,
risk, webhook, or production configuration changed. No fabricated probabilities or prediction performance were
reported.
