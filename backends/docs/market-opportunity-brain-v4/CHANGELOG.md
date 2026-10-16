# CHANGELOG.md — Market Opportunity Brain (append-only)

## 2026-10-15 — Phase 1: contracts, fixtures, naive baseline (session 1)

All paths relative to `autofinx-backend/backends` unless noted. All reviews below are
self-review by the implementing agent; **owner review is still pending** for all items.

| File | Action | Rationale | Test added |
|---|---|---|---|
| `app/Domain/MarketOpportunityBrain/Enums/ResearchDecision.php` | CREATE | LONG_CANDIDATE/SHORT_CANDIDATE/WAIT/REJECT enum, no BUY/SELL case possible | Covered via DecisionRecordContractTest |
| `app/Domain/MarketOpportunityBrain/Enums/ForecastStatus.php` | CREATE | OK/UNAVAILABLE/MODEL_UNAVAILABLE/STALE | Covered via ForecastRecordContractTest, NaiveLastForecastAdapterTest |
| `app/Domain/MarketOpportunityBrain/Enums/ModuleStatus.php` | CREATE | Status vocabulary from CONFIG_PROPOSAL.md:25 | none yet (not wired anywhere) |
| `app/Domain/MarketOpportunityBrain/Data/DiscoveryRecord.php` | CREATE | mob.discovery.v1 DTO | DiscoveryRecordContractTest |
| `app/Domain/MarketOpportunityBrain/Data/ForecastRecord.php` | CREATE | mob.forecast.v1 DTO, quantile/probability validation | ForecastRecordContractTest |
| `app/Domain/MarketOpportunityBrain/Data/DecisionRecord.php` | CREATE | mob.decision.v1 DTO, forbidden-field + execution_authorized/mode guards | DecisionRecordContractTest |
| `app/Domain/MarketOpportunityBrain/Data/ExperimentRecord.php` | CREATE | mob.experiment.v1 DTO, sealed-holdout-string guard | ExperimentRecordContractTest |
| `app/Domain/MarketOpportunityBrain/Data/Candle.php` | CREATE | Candle contract (MASTER_SPEC.md Layer D §44) | exercised indirectly via fixture/adapter tests |
| `app/Domain/MarketOpportunityBrain/Contracts/MarketUniverseProvider.php` | CREATE | Point-in-time universe interface | implemented+tested via DeterministicMarketFixtureProvider |
| `app/Domain/MarketOpportunityBrain/Contracts/MarketSnapshotProvider.php` | CREATE | Read-only candle interface | ditto |
| `app/Domain/MarketOpportunityBrain/Contracts/ForecastProvider.php` | CREATE | Forecast adapter interface | implemented+tested via NaiveLastForecastAdapter |
| `app/Domain/MarketOpportunityBrain/Contracts/DecisionPolicy.php` | CREATE | Decision policy interface (no implementation yet — Phase 4) | none (no implementation to test) |
| `app/Domain/MarketOpportunityBrain/Support/Clock.php` / `SystemClock.php` / `FixedClock.php` | CREATE then DELETE (same session) | Would have duplicated the already-existing `App\Support\Clock\{Clock,SystemClock,FrozenClock}` — found and reverted before anything consumed it; see MOB-D-005 in `DECISIONS.md` | none (never had a consumer) |
| `app/Domain/MarketOpportunityBrain/Fixtures/DeterministicMarketFixtureProvider.php` | CREATE | Fully offline, deterministic, no-RNG market provider for Phase 1 tests | DeterministicMarketFixtureProviderTest (6 tests) |
| `app/Domain/MarketOpportunityBrain/Forecast/NaiveLastForecastAdapter.php` | CREATE | `naive_last` baseline (always-available per MASTER_SPEC.md Layer F) | NaiveLastForecastAdapterTest (2 tests) |
| `config/market_opportunity_brain.php` | CREATE | All flags OFF/0 by default, independent namespace (`AUTOFINX_MOB_*`) | ConfigDisabledByDefaultTest |
| `tests/Unit/MarketOpportunityBrain/*.php` (8 files, 27 tests) | CREATE | Contract parity + safety-invariant + fixture-determinism coverage | self (these ARE the tests) |
| `external-predictive-engine/market_opportunity_brain/__init__.py` | CREATE | Python package marker, no imports from ml-engine-v1 | n/a |
| `external-predictive-engine/market_opportunity_brain/contracts.py` | CREATE | Stdlib-only Python mirror of the 4 PHP DTOs | `tests/test_contracts.py` (8 tests, stdlib unittest) |
| `external-predictive-engine/market_opportunity_brain/tests/__init__.py`, `test_contracts.py` | CREATE | Runnable without installing pytest | self |
| `autofinx-front/frontends/src/types/marketOpportunityBrain.ts` | CREATE | TS type mirror, **not imported anywhere** (no route/page/hook wiring) | `npx tsc -p tsconfig.app.json --noEmit` clean; `npx eslint .` clean (0 errors) |
| `docs/market-opportunity-brain-v4/{AUDIT_FINDINGS,DECISIONS,PROGRESS,RESULTS,CHANGELOG}.md` | CREATE | This session's own audit/decision/progress/results/changelog | n/a (docs) |

## 2026-10-15 — Phase 2, Phase 4, partial Phase 3 (session 2, same day)

| File | Action | Rationale | Test added |
|---|---|---|---|
| `app/Domain/MarketOpportunityBrain/Adapters/MarketDataDisabledException.php` | CREATE | Fail-closed exception for the network gate | covered by all adapter tests |
| `app/Domain/MarketOpportunityBrain/Adapters/BinancePublicDataGate.php` | CREATE | Single choke point enforcing CONFIG_PROPOSAL.md's "0 = disabled" semantics | `BinancePublicDataGateTest` (3) |
| `app/Domain/MarketOpportunityBrain/Adapters/BinancePublicRestClient.php` | CREATE | Read-only Binance public REST wrapper, weight budget + 429/418/5xx retry/backoff | exercised via the two provider tests |
| `app/Domain/MarketOpportunityBrain/Adapters/BinancePublicUniverseProvider.php` | CREATE | Real `MarketUniverseProvider` implementation (current-state-only, fails loudly on historical as-of) | `BinancePublicUniverseProviderTest` (6) |
| `app/Domain/MarketOpportunityBrain/Adapters/BinancePublicSnapshotProvider.php` | CREATE | Real `MarketSnapshotProvider` implementation | `BinancePublicSnapshotProviderTest` (5) |
| `app/Console/Commands/MarketOpportunityBrain/BinancePublicSmokeTestCommand.php` | CREATE | Opt-in real-network proof, never scheduled/wired | manually run once, real output in TEST_EVIDENCE.md §10 |
| `app/Domain/MarketOpportunityBrain/Decision/ResearchDecisionPolicy.php` | CREATE | Concrete `DecisionPolicy`; honestly WAIT/REJECT-only until calibration+cost model exist | `ResearchDecisionPolicyTest` (8) |
| `app/Domain/MarketOpportunityBrain/Forecast/ForecastGateway.php` | CREATE | PHP↔Python subprocess bridge, same pattern as `StrategyLabRunner` | `ForecastGatewayTest` (4, NOT_RUN this session — disk crisis) |
| `external-predictive-engine/market_opportunity_brain/model_registry.py` | CREATE | Lazy model-name → module map | exercised via cli.py |
| `external-predictive-engine/market_opportunity_brain/cli.py` | CREATE | Fixed-argument CLI entrypoint, the only way PHP reaches a Python model | manual run, real JSON output (naive_last), exit 0 |
| `external-predictive-engine/market_opportunity_brain/models/{__init__.py,naive.py,chronos2.py}` | CREATE | Python mirror of naive baseline + real Chronos-2 adapter | naive.py proven via cli.py; chronos2.py written+API-verified, inference UNVERIFIED (weight download stalled) |
| `config/market_opportunity_brain.php` | EDIT (additive keys only) | Phase 2/3 config: `binance_public_base_url`, `min_quote_volume_24h`, `universe_as_of_tolerance_seconds`, `engine_dir`, `python_bin`, `granite_python_bin`, `forecast_timeout_seconds` | `ConfigDisabledByDefaultTest` still green (no existing key changed) |
| `docs/market-opportunity-brain-v4/{DECISIONS,PROGRESS,CHANGELOG,TEST_EVIDENCE}.md` | EDIT (append-only) | Session 2 record, including the disk-space blocker | n/a (docs) |

## 2026-10-15 — Phase 3 completed after `C:` recovered (session 2, continued)

| File | Action | Rationale | Test added |
|---|---|---|---|
| `external-predictive-engine/market_opportunity_brain/models/chronos2.py` | EDIT | Fixed a real quantile-tensor indexing bug (`quantiles[0][-1]` → `quantiles[0][0][-1]`) found via real inference — see DECISIONS.md MOB-D-012 | real CLI + real `ForecastGateway` call, both verified OK with correct quantiles (TEST_EVIDENCE.md §15) |
| `external-predictive-engine/market_opportunity_brain/models/granite_ttm.py` | CREATE | Real Granite TTM-R3 adapter; programmatically detects and honestly reports the model's real checkpoint-loading problem (MOB-D-010) as `MODEL_UNAVAILABLE` rather than fabricating a result | real CLI + real `ForecastGateway` call, both verified correctly reporting `MODEL_UNAVAILABLE` |
| `app/Domain/MarketOpportunityBrain/Forecast/ForecastGateway.php` | EDIT | Pass `HF_HOME`/`TEMP`/`TMP` explicitly to the subprocess instead of relying on shell inheritance — fixed a real `INFERENCE_TIMEOUT` found via a real `php artisan tinker` call (MOB-D-011) | `ForecastGatewayTest` still green (4 tests); real bridge call re-verified OK afterward |
| `config/market_opportunity_brain.php` | EDIT (additive) | Added `python_env_overrides` (built from 3 new env vars, all unset by default); raised `forecast_timeout_seconds` default 60→120 | `ConfigDisabledByDefaultTest` still green |
| `docs/market-opportunity-brain-v4/{DECISIONS,PROGRESS,TEST_EVIDENCE}.md` | EDIT (append-only) | MOB-D-010/011/012, Phase 3 completion record with real inference evidence | n/a (docs) |

Not created this session: a revision-pinned fix for Granite TTM-R3's checkpoint-loading problem
(root cause not yet identified — see MOB-D-010; the honest `MODEL_UNAVAILABLE` fallback is
correct and safe in the meantime, not a placeholder to be embarrassed about).

### Protected-path changes this session

**None.** No file under `app/Domain/FutureTrade/`, `app/Domain/Risk/`, `app/Domain/Trading/`,
`app/Domain/Nexus/`, `app/Domain/StrategyLab/`, `config/future_trade.php`, `config/nexus.php`,
`config/strategy_lab.php`, `routes/api.php`, `routes/console.php`,
`autofinx-front/frontends/src/App.tsx`, or any sidebar/nav component was modified, read for
editing, or had its hash changed. See PROGRESS.md "Isolation proof" for the `git status --short`
evidence before/after this session's changes.

## 2026-10-15 session 5
- Added `app/Domain/MarketOpportunityBrain/Validation/ExperimentRunner.php` (Phase 5 offline evaluator).
- Added `tests/Unit/MarketOpportunityBrain/Validation/ExperimentRunnerTest.php` (10 tests).
- No existing file modified.

## 2026-10-15 session 6
- Added `Validation/ExperimentDatasetBuilder.php`, `Validation/ExperimentV1Orchestrator.php`, `Adapters/BinancePublicHistoryFetcher.php`, `Forecast/ChronosBatchRunner.php`, `app/Console/Commands/MobExperimentV1Command.php` (`php artisan mob:experiment-v1`, research-only, flags enabled in-process only).
- Modified (additive): `Adapters/BinancePublicRestClient::klines()` optional `startTime/endTime`; Python `models/chronos2.py` `predict_batch()`, `cli.py` `predict-batch` command.
- Added `tests/Unit/MarketOpportunityBrain/Validation/ExperimentDatasetBuilderTest.php` (8 tests).
- `.env`: added HF_HOME, HUGGINGFACE_HUB_CACHE, PIP_CACHE_DIR (D:\autofinx-ml\...); `.env.example`: commented MOB placeholders. Existing keys untouched.
- No trading/wallet/risk/webhook/route/frontend file touched; no migration.
