# TEST_EVIDENCE.md — Market Opportunity Brain (session 2026-10-15)

Per `PHASES_AND_TEST_GATES.md` evidence discipline: every test executed lists the exact command,
tool version, test count, result, and files. No "all pass" claims without the actual command
output quoted below.

## 1. Baseline (before any Market Opportunity Brain file existed)

Command:
```
cd autofinx-backend/backends && php artisan test --compact
```
PHP version: `PHP 8.2.12 (cli)`. Result (tail of real output):
```
Tests:    1 failed, 1 skipped, 2216 passed (16777 assertions)
```
The 1 failure is `Tests\Feature\QuickTradeManual\ManualEntryTest > validation: stop-loss is
mandatory, bounded, and take-profit must be positive`, specifically the `DOGEUSDT` sub-assertion
expecting HTTP 422 `invalid_symbol` but receiving 201. Re-run in isolation to rule out test-order
flakiness:
```
cd autofinx-backend/backends && php artisan test --filter=ManualEntryTest
```
Result: same failure, reproducible in isolation (`Tests: 1 failed, 22 passed (149 assertions)`),
confirming it is a stable, pre-existing condition in the unrelated `QuickTradeManual` module (most
likely `DOGEUSDT` having since been added to `config/future_trade.php`'s symbol registry, making a
symbol the test expects to be rejected now valid) — **not** touched or caused by this session, and
**not fixed** by this session per the "no drive-by refactors" / zero-touch rule (it belongs to a
different module than the one in scope).

**Baseline for "pass count must only increase": 2216 passed.**

## 2. New Market Opportunity Brain unit tests (isolated run)

Command:
```
cd autofinx-backend/backends && php artisan test tests/Unit/MarketOpportunityBrain --compact
```
Result:
```
Tests:    27 passed (265 assertions)
Duration: 4.67s
```
All 8 test classes passed: `ConfigDisabledByDefaultTest` (2), `DecisionRecordContractTest` (6),
`DeterministicMarketFixtureProviderTest` (6), `DiscoveryRecordContractTest` (3),
`ExperimentRecordContractTest` (2), `ForecastRecordContractTest` (4), `ModuleIsolationTest` (2),
`NaiveLastForecastAdapterTest` (2).

## 3. Full backend suite after adding the module (proves pass count only increased)

Command:
```
cd autofinx-backend/backends && php artisan test --compact
```
```
Tests:    1 failed, 1 skipped, 2243 passed (17042 assertions)
```
Delta vs. baseline: **2243 − 2216 = +27 passed**, exactly matching the 27 new
`tests/Unit/MarketOpportunityBrain` tests — no other test's pass/fail status changed. The same
single pre-existing `ManualEntryTest` failure (identical assertion, identical line 189) is still
the only failure, confirming this session introduced zero regressions. **Pass count increased from
2216 to 2243, as required; the full suite did not go green only because of the one pre-existing,
independently-reproduced, out-of-scope `QuickTradeManual` failure documented above.**

## 4. Python contract-parity tests (stdlib `unittest`, no pytest install required)

Command:
```
cd autofinx-backend/backends/external-predictive-engine && python -m unittest market_opportunity_brain.tests.test_contracts -v
```
Python: `3.10.11` (`C:\Users\PC\AppData\Local\Programs\Python\Python310\python.exe`). Result:
```
Ran 8 tests in 0.005s
OK
```
Note: the repo's documented research Python (`docs/ml-engine-v1/AGENT.md`) uses a dedicated venv
under `external-predictive-engine/.venv` for `requirements-v4.txt` (LightGBM/numpy/etc.) — that
venv does not exist in this environment and was **not created** (no network install performed).
This Python test module was deliberately written using only the standard library (`dataclasses`,
`unittest`) specifically so it does not depend on that venv or on `pytest` being installed.

## 5. Frontend typecheck and lint (new types file only; no routing/wiring)

Commands:
```
cd autofinx-front/frontends && npx tsc -p tsconfig.app.json --noEmit
cd autofinx-front/frontends && npx eslint .
```
Node/TS toolchain: project's pinned `package.json` devDependencies (not modified this session).
Results: `tsc` produced **no output** (clean, exit 0). `eslint .` reported **0 errors, 29
warnings**, all 29 warnings pre-existing in files this session did not touch (e.g.
`react-hooks/exhaustive-deps` in `QuickTradePage.tsx`, `FutureTradeBotPage.tsx`, etc.); the new
`src/types/marketOpportunityBrain.ts` produced zero warnings and zero errors on its own
(`npx eslint src/types/marketOpportunityBrain.ts` → no output).

`npm run build` (the full `vite build` step) was **NOT_RUN** this session — the typecheck half of
that script (`tsc -p tsconfig.app.json --noEmit`) was run directly and is clean, and the new file
is a type-only module with zero imports/importers, so a bundler-level regression is extremely
unlikely, but this is an honest gap: a future session touching the frontend further should run the
full `npm run build` before relying on this claim for anything beyond the types file itself.

## 6. Isolation proof (`git status --short`)

Backend repo (`git -C autofinx-backend status --short`) before this session had pre-existing
unrelated changes (see AUDIT_FINDINGS.md "Additional facts"). After this session's work, the
**new** entries are exactly:
```
?? backends/app/Domain/MarketOpportunityBrain/
?? backends/config/market_opportunity_brain.php
?? backends/docs/market-opportunity-brain-v4/
?? backends/external-predictive-engine/market_opportunity_brain/
?? backends/tests/Unit/MarketOpportunityBrain/
```
`git diff --stat` on tracked files confirms exactly 3 pre-existing modified files, all outside this
module (`RobotSessionService.php` +24, `RobotSessionController.php` +6, `RobotSession.php` +1 — all
present before this session started; never opened for editing in this session). No file under
`app/Domain/MarketOpportunityBrain/` is tracked yet (all new, hence `??` not `M`), by definition.

Frontend repo (`git -C autofinx-front status --short`): only new entry added is
```
?? frontends/src/types/marketOpportunityBrain.ts
```
on top of the pre-existing unrelated `M` entries for `ManualOrderForm.tsx`,
`ManualQuickTradeSection.tsx`, `QuickTradePage.tsx` (also pre-existing, untouched).

## 7. Static safety-invariant coverage (PHASES_AND_TEST_GATES.md checklist, this phase's subset)

| Invariant | Status this session |
|---|---|
| `test_opportunity_brain_cannot_submit_order_or_modify_execution_config` | PARTIAL — `ModuleIsolationTest` greps the module's own source for execution-capable symbols and confirms no route yet mentions the module; full route/binding scan deferred to Phase 2+ when routes/jobs exist. |
| `test_all_new_feature_flags_default_disabled` | DONE — `ConfigDisabledByDefaultTest`. |
| `test_unlisted_or_delisted_historical_symbol_excluded_at_asof` | DONE — `DeterministicMarketFixtureProviderTest::delisted_symbol_is_excluded_from_the_universe_after_its_delisting_time` + `not_yet_listed_symbol_is_excluded`. |
| `test_strategy_only_signals_unchanged_when_brain_disabled` | NOT_APPLICABLE YET — no strategy integration point exists in Phase 1. |
| `test_stale_or_unavailable_prediction_yields_wait` | PARTIAL — `NaiveLastForecastAdapterTest::reports_unavailable_rather_than_fabricating_a_forecast_with_no_candles` covers the adapter side; the actual WAIT decision is Phase 4 (`DecisionPolicy` has no implementation yet). |
| `test_no_future_feature_known_at_after_decision` | DONE (fixture layer) — `DeterministicMarketFixtureProviderTest::closed_candles_never_extend_past_the_as_of_timestamp`. |
| `test_existing_ml_holdout_unread` | DONE (by construction) — nothing in this module reads any ml-engine-v1 data path; `ExperimentRecord` hard-rejects any near-miss "sealed" string that isn't the exact `SEALED_NO_ACCESS` literal. |
| `test_no_retrain_of_frozen_v3_v4` | DONE (by construction) — no model training code exists in this module. |
| `test_forecast_quantiles_ordered_and_probabilities_well_formed` | DONE — `ForecastRecordContractTest::rejects_out_of_order_quantiles` / `rejects_probabilities_summing_above_one`. |
| `test_live_pnl_never_inferred_from_hypothetical_backtest` | DONE — `DecisionRecordContractTest::cannot_carry_both_realized_and_hypothetical_pnl_at_once`. |
| `test_existing_webhook_live_flags_and_prime_mirror_guard_unchanged` | DONE (by construction + evidence) — `config/future_trade.php` was read-only inspected, never edited; `ModuleIsolationTest` confirms the module source never references `pine_webhook`/`prime_mirror`. |
| All others (workspace cross-tenant, idempotent replay, resource budget, etc.) | NOT_RUN — require Phase 2+ (routes/jobs/DB persistence) to exist first; explicitly deferred, not silently skipped. |

---

# Session 2 (2026-10-15, same day) — Phase 2, Phase 4, partial Phase 3

## 8. Fresh baseline re-verification (per owner instruction to not trust session 1's number blindly)

```
cd autofinx-backend/backends && php artisan test --compact
Tests:    1 failed, 1 skipped, 2243 passed (17015 assertions)
```
Matches session 1's end-of-session count exactly (2243 passed) — confirms nothing else touched
the suite between sessions. This is session 2's baseline.

## 9. Phase 2 — Binance public-data adapter tests (offline, `Http::fake()`)

```
cd autofinx-backend/backends && php artisan test tests/Unit/MarketOpportunityBrain/Adapters --compact
Tests:    14 passed (28 assertions)
Duration: 3.78s
```
`BinancePublicDataGateTest` (3), `BinancePublicSnapshotProviderTest` (5),
`BinancePublicUniverseProviderTest` (6). Covers: disabled-by-default refusal, in-progress-candle
exclusion, duplicate/malformed-row handling, non-USDT/non-perpetual/non-trading exclusion, missing
vs. insufficient volume distinction, top-K cap, execution_authorized always false.

## 10. Phase 2 — REAL opt-in network smoke test against live Binance futures data

Command (flags passed inline, never written to `.env`):
```
cd autofinx-backend/backends
AUTOFINX_MOB_PUBLIC_DATA_ENABLED=true AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE=1200 \
AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS=10 php artisan mob:binance-smoke-test --symbol=BTCUSDT
```
Real output (verbatim, captured 2026-10-15):
```
--- Universe scan (BinancePublicUniverseProvider) ---
Total symbols seen: 525
Eligible (ranked) candidates: 10
  #1 BTCUSDT score=1.0000 vol_evidence_reason=ELIGIBLE
  #2 ETHUSDT score=0.8088 vol_evidence_reason=ELIGIBLE
  #5 XRPUSDT score=0.0590 vol_evidence_reason=ELIGIBLE
  #4 ZECUSDT score=0.1268 vol_evidence_reason=ELIGIBLE
  #10 DOGEUSDT score=0.0282 vol_evidence_reason=ELIGIBLE
--- Candles (BinancePublicSnapshotProvider) for BTCUSDT ---
  2026-10-15T05:30:00Z -> 2026-10-15T05:44:59Z open=81082.8000 close=80987.9000 vol=1902.79
  2026-10-15T05:45:00Z -> 2026-10-15T05:59:59Z open=80987.9000 close=80550.6000 vol=4770.47
  2026-10-15T06:00:00Z -> 2026-10-15T06:14:59Z open=80550.5000 close=80737.8000 vol=2177.10
  2026-10-15T06:15:00Z -> 2026-10-15T06:29:59Z open=80737.8000 close=80940.5000 vol=1694.09
  2026-10-15T06:30:00Z -> 2026-10-15T06:44:59Z open=80940.5000 close=80924.4000 vol=1240.32
Smoke test complete. execution_authorized is always false; no order was placed.
```
Real, genuine evidence that the adapter works against live Binance data. No order was placed; this
command only ever calls `GET /fapi/v1/{exchangeInfo,ticker/24hr,klines}`.

## 11. Phase 4 — `ResearchDecisionPolicy` tests

```
cd autofinx-backend/backends && php artisan test tests/Unit/MarketOpportunityBrain/Decision --compact
Tests:    8 passed (20 assertions)
Duration: 10.46s
```
Covers: causal known-at-after-decision rejection, candidate/forecast symbol mismatch rejection,
ineligible-candidate rejection, forecast-unavailable WAIT, uncalibrated-forecast WAIT (matches the
owner's own canonical `decision.v1.example.json`), the hypothetically-calibrated branch still never
emitting LONG/SHORT (no cost model exists), execution_authorized always false, determinism.

## 12. Phase 3 — Python library installation and API verification (real, not assumed)

Disk-space discipline: `C:` had only ~1.2GB free at session 2 start (pre-existing, unrelated — see
§14). Every install this session was redirected to `D:` via a dedicated venv
(`D:\autofinx-mob-v4\venv`, confirmed stable at ~87GB free throughout):
```
D:\autofinx-mob-v4\venv\Scripts\python.exe -m pip install --cache-dir D:\autofinx-mob-v4\pip-cache chronos-forecasting
Successfully installed accelerate-1.15.0 chronos-forecasting-2.3.2 tokenizers-0.23.3 transformers-5.19.0 typer-0.27.3
(torch-2.14.1 installed in an earlier, separate invocation of the same command)
```
Real API introspection (not assumed from docs) of the actually-installed package:
```python
from chronos import Chronos2Pipeline, BaseChronosPipeline
# dir(chronos) confirms: Chronos2Pipeline, Chronos2Model, Chronos2ForecastingConfig exist
Chronos2Pipeline.from_pretrained(pretrained_model_name_or_path, *args, **kwargs)
Chronos2Pipeline.predict_quantiles(self, inputs, prediction_length=None, quantile_levels=[...]) -> (list[Tensor], list[Tensor])
```
Model file size confirmed via `HfApi().model_info('amazon/chronos-2', files_metadata=True)`:
`model.safetensors` = 455.79 MB (total repo 455.8 MB) — feasible on `D:`'s 87GB free, NOT on `C:`'s
~1.2GB (confirming the decision to redirect everything to `D:`).
`ibm-granite/granite-timeseries-ttm-r3`: `model.safetensors` = 5.46 MB (tiny, as the model card
describes) — also confirmed via the same API call, before any download was attempted.

**Dependency conflict discovered and handled (MOB-D-008)**: `granite-tsfm==0.3.3` requires
`transformers[torch]==4.56.0` + `torch<2.9`, which started downgrading the already-installed
`transformers==5.19.0`/`torch==2.14.1` when installed into the same venv (observed mid-install:
`Downloading transformers-4.56.0...`, `Downloading torch-2.8.0 (241.4 MB)...`). Install was killed
(`taskkill //F //PID 8304`) before any file was overwritten; verified afterward:
`pip list | grep -i "torch \|transformers "` still shows `torch 2.14.1` / `transformers 5.19.0`
unchanged. A second, separate venv (`D:\autofinx-mob-v4\venv-granite`) was created for
`granite-tsfm` instead.

## 13. Phase 3 — what was NOT completed, and why (honest, not papered over)

- **Chronos-2 model weight download (455.8 MB) did not finish this session.** The `huggingface_hub`
  `xet`-accelerated transfer logged a real, ongoing download (observed `observed bytes sent so
  far` climbing from ~1.7MB to ~53MB over several minutes) but then stalled with repeated
  `"Request error... TimedOut"` / `"connection struggling"` log entries and `hf-cache` never grew
  past 5KB afterward -- a genuine, external network/connectivity issue to HF's CDN from this host,
  not a disk-space or code problem (confirmed: `D:` free space never moved during this). No
  real Chronos-2 inference was run this session; `app/Domain/MarketOpportunityBrain/Forecast/ForecastGateway.php`
  and `external-predictive-engine/market_opportunity_brain/models/chronos2.py` are written and
  syntax-checked but UNVERIFIED against a real forward pass.
- **`granite-tsfm` package install did not finish** (still downloading its own pinned
  `torch-2.8.0`, 241.4 MB, at the time work stopped). `granite_ttm.py` adapter module was
  **not yet written** this session (deprioritized once the disk-space emergency hit — see §14).
- **The naive_last CLI bridge IS fully proven end-to-end**, including the subprocess path PHP will
  eventually use for the other two models:
```
cd autofinx-backend/backends/external-predictive-engine
D:\autofinx-mob-v4\venv\Scripts\python.exe -m market_opportunity_brain.cli predict --model naive_last \
  --symbol BTCUSDT --timeframe 15m --horizon-bars 4 --as-of-utc 2026-10-15T00:00:00Z \
  --workspace-id local-research-workspace --closes-json "[100.0,101.0,102.0]" \
  --last-close-time-utc 2026-10-14T23:45:00Z
```
Real output: a complete, valid `mob.forecast.v1` JSON object, `status: "OK"`, `execution_authorized: false`,
exit code 0.
- `ForecastGatewayTest` (4 tests, offline/config-driven, no real subprocess) was syntax-checked
  clean (`php -l`, confirmed no fatal errors) but **could not be executed** once the `C:` disk
  crisis hit (see §14) — honestly marked **NOT_RUN**, not claimed as passing.

## 14. CRITICAL, SESSION-STOPPING FINDING: host `C:` drive disk-space exhaustion (unrelated to this module)

Partway through Phase 3, `php artisan test` began failing reproducibly:
```
VirtualAlloc() failed: [0x000005af] The paging file is too small for this operation to complete
PHP Fatal error:  Out of memory (allocated 90177536 bytes) (tried to allocate 32768 bytes) in routes/api.php on line 587
```
Reproduced on 3 separate attempts (a scoped Phase 3 test run, then two full-suite attempts), with
`C:` free space measured at 25MB, 21MB, 19MB, 25MB across those attempts (`df /c`) — i.e.
essentially zero, consistent with Windows being unable to grow its paging file (which lives on
`C:`) under any real memory pressure, including Laravel's normal app boot.

**This is NOT caused by this session's work.** Every install this session targeted `D:`
exclusively (`D:\autofinx-mob-v4\...`), confirmed stable at ~87GB free the entire session via
repeated `df -h /d` checks, including during the exact windows when `C:` was dropping. `C:` started
this session at ~1.2GB free (already very low, a pre-existing condition inherited from session 1's
own report) and fell to near-zero independent of anything this session executed -- including
during periods when no command from this session was running at all. This points to an unrelated
process on the shared host.

**Consequence**: no further `php artisan test` run (scoped or full-suite) could be completed
after this point. Work was stopped here per the owner's own explicit instruction ("if you hit a
genuine blocker... stop at that point, document it honestly... and report back"). Phase 5
(experiment runner) and Phase 6 (API/UI) were **not started** in this session specifically to
avoid building more code on top of an unverifiable foundation.

**Last fully verified full-suite state**: `1 failed, 1 skipped, 2243 passed (17015 assertions)`
(§8, taken BEFORE the crisis). Phase 2 (14 tests) and Phase 4 (8 tests) passed in their own scoped
runs before the crisis (§9, §11) -- real evidence, not re-verified in a final full-suite run.

---

# Session 2 continued — `C:` recovered mid-session; Phase 3 completed with real inference

`C:` free space recovered on its own (observed climbing from ~19MB to ~2.5GB, then fluctuating in
the low-hundreds-of-MB range) partway through a background Python install. `php artisan test`
started working again, reproducibly. Work resumed cautiously rather than assuming the underlying
cause was fixed -- see DECISIONS.md MOB-D-009's update note.

## 15. Phase 3 COMPLETED — real Chronos-2 inference proven end-to-end, Granite TTM-R3 honestly blocked

**Chronos-2, standalone Python, real captured BTCUSDT data** (the same closes from §10's live
smoke test: `[81082.8, 80987.9, 80550.6, 80737.8, 80940.5, 80924.4, 80801.5, 80737.8, 80940.5,
80924.4]`), via `chronos.Chronos2Pipeline.predict_quantiles(...)`:
```
Model load time: 1832.10s   (one-time; mostly the 455.8MB weight download finally completing
                              on this session's slow/unstable connection -- see MOB-D-009)
Inference time: 0.117s
quantiles[0] shape: torch.Size([1, 4, 3])
Per-horizon-step [p10, p50, p90]:
  step1: [80658.25, 80884.37, 81069.53]
  step2: [80588.69, 80877.84, 81121.93]
  step3: [80543.22, 80879.23, 81156.20]
  step4 (reported): [80519.48, 80874.20, 81193.06]
```
This surfaced a real bug (MOB-D-012): the adapter's first version mis-indexed this tensor
(`quantiles[0][-1]` instead of `quantiles[0][0][-1]`), causing `INFERENCE_ERROR: float() argument
must be a string or a real number, not 'list'`. Fixed and re-verified:
```
cd autofinx-backend/backends/external-predictive-engine
D:\autofinx-mob-v4\venv\Scripts\python.exe -m market_opportunity_brain.cli predict --model chronos2 \
  --symbol BTCUSDT --timeframe 15m --horizon-bars 4 --as-of-utc 2026-10-15T06:45:00Z \
  --workspace-id local-research-workspace \
  --closes-json "[81082.8,80987.9,80550.6,80737.8,80940.5,80924.4,80801.5,80737.8,80940.5,80924.4]" \
  --last-close-time-utc 2026-10-15T06:44:59Z
```
Real output (weights now cached on `D:`, no re-download): `status: "OK"`,
`prediction_quantiles: {"p10": 80519.484375, "p50": 80874.203125, "p90": 81193.0625}`,
`reason: "latency_ms=9743.4"` (first call, cold model-into-process load) / later calls ~9s total
wall-clock. **Matches the standalone script's step-4 values exactly.**

**Granite TTM-R3, standalone Python**, via the library's own documented `get_model()` helper
(`tsfm_public.toolkit.get_model`), `context_length=512`, `prediction_length=4`:
```
Some weights of TinyTimeMixerForPrediction were not initialized from the model checkpoint...
You should probably TRAIN this model on a down-stream task to be able to use it for predictions and inference.
load time: 1.70s
prediction_outputs shape: torch.Size([1, 4, 1])
```
Confirmed via the library's own official API, not a custom/guessed one -- see DECISIONS.md
MOB-D-010 for full detail. `granite_ttm.py` programmatically detects this (via a `logging.Handler`
capturing the "newly initialized" message) and returns `MODEL_UNAVAILABLE`:
```
D:\autofinx-mob-v4\venv-granite\Scripts\python.exe -m market_opportunity_brain.cli predict --model granite_ttm_r3 \
  --symbol BTCUSDT --timeframe 15m --horizon-bars 4 --as-of-utc 2026-10-15T00:00:00Z \
  --workspace-id local-research-workspace --closes-json "[100.0,101.0,102.0]" --last-close-time-utc 2026-10-14T23:45:00Z
```
Real output: `"status": "MODEL_UNAVAILABLE", "reason": "ZERO_SHOT_WEIGHTS_NOT_VERIFIED_SEE_DECISIONS_MD_MOB_D_010"`.

**Full PHP→Python bridge, both models, via real `ForecastGateway` (not a mock)**, run through
`php artisan tinker` with real `Candle` objects:
- `chronos2`: first attempt timed out (`INFERENCE_TIMEOUT`) because the subprocess did not inherit
  the calling shell's `HF_HOME`/`TEMP`/`TMP` (MOB-D-011, a real bug, now fixed:
  `ForecastGateway` passes `python_env_overrides` explicitly as the subprocess `env` instead of
  relying on inheritance). After the fix: real output, `"status":"OK"`,
  `"prediction_quantiles":{"p10":80519.484375,"p50":80874.203125,"p90":81193.0625}`,
  `"reason":"latency_ms=8957.0"` -- identical values to the standalone CLI call, proving the bridge
  carries the real model's output through correctly, unmodified.
- `granite_ttm_r3`: real output, `"status":"MODEL_UNAVAILABLE"`,
  `"reason":"ZERO_SHOT_WEIGHTS_NOT_VERIFIED_SEE_DECISIONS_MD_MOB_D_010"` -- the honest limitation
  propagates correctly end-to-end through the full stack, never silently upgraded to a false "OK".

## 16. Offline MOB test suite re-run after the Phase 3 fixes (config + ForecastGateway changed)

```
cd autofinx-backend/backends && php artisan test tests/Unit/MarketOpportunityBrain --compact
Tests:    53 passed (359 assertions)
```
(27 Phase-1 + 14 Phase-2 + 8 Phase-4 + 4 Phase-3-gateway = 53; all still green after the
`python_env_overrides`/timeout config changes.)

## 17. Final full-suite re-run (after `C:` recovered and Phase 3 was completed)

Command:
```
cd autofinx-backend/backends && php artisan test --compact
```
Started after §15/§16's work completed. **Did not complete within a reasonable session time
window** (ran far longer than the earlier two full-suite runs this session, which each completed
in a few minutes). `tasklist` confirmed PHP test-worker processes were genuinely still cycling
(new PIDs replacing old ones over time, not a single frozen process), and `C:` free space was
reconfirmed still critically low and fluctuating (~27MB-137MB) throughout the wait — consistent
with severe disk I/O slowdown on a near-full drive affecting every file write the test suite makes
(SQLite test databases, cache, logs), rather than a hang caused by this session's code changes.
This is reported honestly as **NOT_RUN / INCOMPLETE** rather than guessed at.

**What IS confirmed, with real evidence, as the basis for this session's claims**:
- The full backend suite was confirmed green-apart-from-the-one-pre-existing-failure earlier in
  THIS SAME session, after Phase 2 and Phase 4 were added: `1 failed, 1 skipped, 2243 passed`
  (§8, §9, §11).
- After Phase 3's code changes (the `chronos2.py` fix, the new `granite_ttm.py`, and the
  `ForecastGateway`/config changes), the full **scoped** `tests/Unit/MarketOpportunityBrain`
  suite was re-run and confirmed green: **`53 passed (359 assertions)`** (§16) — this covers
  every test this session added or touched (27 Phase 1 + 14 Phase 2 + 8 Phase 4 + 4 Phase 3
  gateway tests), with zero failures.
- No test file outside `tests/Unit/MarketOpportunityBrain/` was touched or could plausibly be
  affected by this session's changes (confirmed via the isolation proof in §6 and re-confirmed
  fresh via `git diff --stat` at the very end of this session — identical to every earlier check).
- Combining these two facts (full suite green at 2243 before Phase 3; the exact set of Phase-3-
  affected tests independently confirmed green after Phase 3) is strong evidence the full suite
  would still show `2243 passed` plus the same 1 pre-existing failure if a final run completed —
  but this is **stated as an inference from partial evidence, not claimed as a directly-observed
  final count**. A fresh full-suite run is the correct first step of any future session, once the
  host's disk-space situation is confirmed stable.

## 16. Session 5 (2026-10-15), PHP 8.2.12, Laravel 12
- `php artisan test --compact` before: 1 failed, 1 skipped, 2269 passed (17136 assertions), exit 1 (pre-existing ManualEntryTest).
- `php artisan test tests/Unit/MarketOpportunityBrain/Validation --compact`: 10 passed (15 assertions), exit 0.
- `php artisan test tests/Unit/MarketOpportunityBrain --compact`: 63 passed (383 assertions).
- `php artisan test --compact` after: 1 failed, 1 skipped, 2279 passed (17160 assertions), exit 1 (same single pre-existing failure).
- Not run: npm build/eslint (frontend untouched), Python tests, real Binance/Chronos smoke.

## 17. Session 6 (2026-10-15), mob-exp-v1 dataset builder + real run
- Disk before: C: 4.4G free (98%), D: 85G free. After: C: 2.3G free (99%), D: 85G free (experiment artifacts ~13MB on D:). The C: drop is NOT from model/data files: `storage/logs` is 856M (daemon-schedule-work.log 449M and growing from a live daemon, laravel.log 312M, testing.log 134M) -- owner action advised; this agent deleted nothing.
- `php artisan test --compact` before: 1 failed, 1 skipped, 2279 passed (17160 assertions), exit 1 (pre-existing ManualEntryTest).
- `php artisan test tests/Unit/MarketOpportunityBrain --compact`: 71 passed (475 assertions), exit 0.
- `php artisan test --compact` after: 1 failed, 1 skipped, 2287 passed (17252 assertions), exit 1 (same single pre-existing ManualEntryTest failure; +8 new passed).
- `php artisan mob:experiment-v1 --max-windows=8` (smoke): exit 0, 40 windows, Chronos OK. `php artisan mob:experiment-v1` (full): exit 0, 5,200 windows, Chronos OK 160.5 s, verdict REJECTED_NO_EDGE both arms (RESULTS.md).
- Not run: Python pytest (pytest not installed in venv; CLI batch path verified by the real runs), npm build/eslint (frontend untouched).
