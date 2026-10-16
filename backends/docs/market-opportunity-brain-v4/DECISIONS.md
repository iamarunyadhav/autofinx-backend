# DECISIONS.md — Market Opportunity Brain (append-only)

New project only. Does NOT change `docs/ml-engine-v1/DECISIONS.md` or the existing D-046/D-050/D-053
research freeze. Nothing here reopens or reinterprets those decisions.

---

## MOB-D-001 (2026-10-15): initial boundary

- **Decision**: This is a new, isolated, research-only market discovery/forecasting/decision
  module. It is NOT a continuation of `ml-engine-v1`'s V4 path-aware ML pipeline (that project is
  closed, 30/30 models REJECTED, see root memory `project_v4_final_p8_oct2026`). It shares no code,
  no config flag, no feature flag, no queue, and no database table with Future Trade, Nexus,
  Strategy Lab, or ml-engine-v1.
- **Codebase facts this rests on**: `app/Domain/MarketData/Providers/BinanceMarketDataProvider.php`
  is a no-op stub (confirmed, AUDIT_FINDINGS.md #8); `app/Domain/Forecast/ForecastService.php` is a
  separate EMA stub not to be reused (#7); Strategy Lab's fixed-SHA adapter model and
  `AssertsWorkspaceMembership` trait are the reusable patterns for later phases (#14, additional
  facts).
- **Reviewer**: autonomous coding agent (Claude Sonnet 5), session 2026-10-15. Owner has not yet
  reviewed this session's output.

## MOB-D-002 (2026-10-15): scope for this session is Phase 1 only (contracts/fixtures), not Phase 2+

- **Decision**: Per `PHASES_AND_TEST_GATES.md`'s own gate matrix, this session implements Phase 1
  ("Typed PHP+Python+TS schemas, mock adapters, research config all disabled") only. No real-market
  adapter (Phase 2), no Chronos-2/Granite TTM-R3 adapter (Phase 3), no concrete `DecisionPolicy`
  implementation (Phase 4), no experiment runner (Phase 5), no API routes or UI (Phase 6) were
  built. The `DecisionPolicy` *interface* was written (contracts are explicitly Phase 1 scope per
  `MASTER_SPEC.md` Layer L file-layout proposal), but it has zero implementations yet.
- **Rationale**: the owner's task explicitly said "do not jump ahead to later phases," and
  `PHASES_AND_TEST_GATES.md` treats each phase as its own gate. Building API/UI now would also
  require touching `routes/api.php` and `frontend/src/App.tsx`/sidebar registration before the
  underlying discovery/forecast/decision logic exists to serve — premature wiring risk.
- **Predecessor**: none (first session).

## MOB-D-003 (2026-10-15): deferred DB migrations for this phase

- **Decision**: No Laravel migration was written this session for `universe_snapshots`,
  `candidate_rankings`, `forecast_runs`, `forecast_predictions`, `decision_records`, etc. (the
  Layer H persistence tables).
- **Rationale**: `MASTER_SPEC.md` Layer L explicitly says "research-only Parquet/SQLite default...
  Stop and request review before any production schema migration," and Layer H (the full
  append-only fact-table schema) is Phase 4 work, not Phase 1. Writing migrations now — before the
  discovery/forecast/decision logic that will populate them is implemented — risks a schema that
  has to be reworked once the real field set is known, which is exactly the kind of premature
  commitment the spec warns against. Phase 1's own gate only requires "isolated storage," which the
  in-memory `DeterministicMarketFixtureProvider` (no persistence at all, pure functions of its
  inputs) satisfies for now.
- **Next action needed**: when Phase 2 (real-market discovery) or Phase 4 (immutable decision
  records) is implemented, write migrations then, under the existing migration-approval rule
  (written but left `Pending`, never auto-run).
- **Predecessor**: none.

## MOB-D-004 (2026-10-15): no route/UI wiring this session

- **Decision**: No Laravel route (`routes/api.php`) and no frontend route/sidebar entry
  (`App.tsx`, `Sidebar.tsx`) were added for this module.
- **Rationale**: the owner's task description named API/sidebar registration as explicitly
  *allowed* non-module changes, but also said "do not jump ahead to later phases" — and
  `PHASES_AND_TEST_GATES.md` places the read-only API/UI at Phase 6, after discovery (P2), models
  (P3), and decisions (P4) exist. Registering a route or sidebar entry for a module with no
  discovery/forecast/decision logic behind it yet would be dead wiring with nothing real to show,
  and would touch two shared files (`routes/api.php`, `App.tsx`) earlier than necessary. Zero-touch
  discipline favors deferring this until there is a real `/status` endpoint worth exposing.
- **Predecessor**: none.

## MOB-D-005 (2026-10-15): self-caught duplication of an existing Clock abstraction, reverted

- **Decision**: `App\Domain\MarketOpportunityBrain\Support\{Clock,SystemClock,FixedClock}`
  (written earlier in this same session) were deleted after discovering `App\Support\Clock\
  {Clock,SystemClock,FrozenClock}` already exists in the codebase (Carbon-based, app-timezone,
  with a settable `FrozenClock` for tests).
- **Rationale**: root `AGENTS.md` already documents this exact class of mistake for
  `Indicators::adx()` ("use this for any new indicator math — do not write a second
  implementation"). The same discipline applies here. The duplicate had zero consumers in this
  session's code (neither `DeterministicMarketFixtureProvider` nor `NaiveLastForecastAdapter` used
  a Clock — both take an explicit `\DateTimeImmutable $asOfUtc` argument), so deleting it was a
  pure, reversible, zero-risk cleanup with no test regressions possible.
- **Follow-up for a future phase**: if/when a job or scheduler needs an injectable "current time"
  (rather than an explicit as-of parameter), reuse `App\Support\Clock\Clock` and convert to UTC at
  the call site; do not reintroduce a module-local Clock.
- **Predecessor**: none (corrects this session's own MOB-D-002-era scaffolding, not a prior
  decision).

## MOB-D-006 (2026-10-15, session 2): owner authorized Phase 2-6, proceeding per spec's own gates

- **Decision**: Owner reviewed session 1's report, independently re-verified it (own `git diff
  --stat`, re-ran the 27 tests), and gave explicit go-ahead to continue through Phase 2 (real
  Binance adapter), Phase 3 (Chronos-2/Granite TTM-R3), and eventual Phase 6 (route/sidebar/UI).
  Absolute restrictions restated unchanged: no live trading, `execution_authorized` stays false,
  isolation from trading/wallet/risk/webhook/Nexus/Strategy Lab files continues, no migrations run,
  no commit/push/deploy.
- **This session re-verified before touching anything**: `MASTER_SPEC.md` still exists and is
  readable (194 lines, unchanged); both repos' pre-existing uncommitted files (RobotSession*,
  StrategyLab additions, frontend Manual* files) are byte-identical to session 1's end state
  (fresh `git diff --stat` matches exactly); fresh full-suite baseline re-run:
  `1 failed, 1 skipped, 2243 passed (17015 assertions)` -- same pass count as session 1's
  end-of-session number, confirming nothing else changed the suite in between.
- **Predecessor**: MOB-D-002 (which deferred Phases 2-6 pending exactly this kind of explicit
  owner sign-off).

## MOB-D-007 (2026-10-15, session 2): Phase 2 scope limited to near-real-time universe snapshots

- **Decision**: `BinancePublicUniverseProvider` (the real Binance adapter) only answers "as of
  now" (within a configurable tolerance, default 120s) and throws `\DomainException` for any
  materially historical `asOfUtc` rather than silently returning today's universe mislabeled as a
  past snapshot.
- **Rationale**: `exchangeInfo`/`ticker/24hr` are current-state-only endpoints; true point-in-time
  historical listing/delisting reconstruction needs a bulk archive (e.g. `data.binance.vision`),
  which is out of scope for this phase. MASTER_SPEC.md Layer D §46 explicitly forbids "fabricated
  missing coins" -- failing loudly on an unanswerable historical request is the honest
  alternative. `BinancePublicSnapshotProvider` (candles) has no such limitation since Binance's
  `klines` endpoint does serve real historical OHLCV directly.
- **Evidence**: real opt-in smoke test run against live Binance futures data this session (525
  symbols scanned, 10 ranked eligible candidates, real BTCUSDT candles retrieved) -- see
  TEST_EVIDENCE.md §8.
- **Predecessor**: none.

## MOB-D-008 (2026-10-15, session 2): Chronos-2 and Granite TTM-R3 need SEPARATE Python venvs

- **Decision**: `amazon/chronos-2` (via `chronos-forecasting==2.3.2`) and `ibm-granite/granite-timeseries-ttm-r3`
  (via `granite-tsfm==0.3.3`) are installed into two separate, independent virtual environments
  (`D:\autofinx-mob-v4\venv` and `D:\autofinx-mob-v4\venv-granite`), not one shared environment.
- **Rationale (discovered empirically, not assumed)**: `chronos-forecasting` requires
  `transformers>=4.41` and installed `transformers==5.19.0` + `torch==2.14.1` in this session's
  environment. `granite-tsfm` pins `transformers[torch]==4.56.0` (exact) and `torch<2.9` -- when
  installed into the SAME venv, pip began downgrading both packages (observed mid-install:
  `Downloading transformers-4.56.0...`, `Downloading torch-2.8.0...`), which would have broken the
  already-working Chronos-2 install. The install was killed before it could overwrite anything
  (`taskkill`, confirmed `torch==2.14.1`/`transformers==5.19.0` unchanged afterward), and a second
  venv was created instead. `App\Domain\MarketOpportunityBrain\Forecast\ForecastGateway` picks the
  python binary per model name (`market_opportunity_brain.granite_python_bin` for
  `granite_ttm_r3`, `market_opportunity_brain.python_bin` for everything else) for exactly this
  reason -- this was a design requirement discovered during implementation, not planned upfront.
  MODEL_RESEARCH_REFERENCES.md's own caution ("model format/load API may differ from Chronos")
  undersold this risk -- it is a load-bearing dependency conflict, not just an API-shape
  difference.
- **Predecessor**: none.

## MOB-D-009 (2026-10-15, session 2): STOPPED -- critical C: disk-space exhaustion, unrelated to this module

- **Decision**: Work paused mid-Phase-3 (before Phase 5/6) after the host machine's `C:` drive
  (where both the Laravel app and PHP itself live) dropped to single-digit megabytes free,
  causing `php artisan test` to fail with `VirtualAlloc() failed... paging file is too small` /
  `PHP Fatal error: Out of memory` -- reproduced twice, not a one-off blip.
- **Evidence this is NOT caused by this session's work**: every model/package install this
  session was deliberately redirected to `D:` (`D:\autofinx-mob-v4\{venv,venv-granite,pip-cache,hf-cache,tmp}`)
  specifically because `C:` already had only ~1.2GB free at session start. `D:` free space stayed
  at a stable ~87GB throughout (confirmed via repeated `df -h /d`) while `C:` independently
  dropped from ~1.2GB -> ~300MB -> ~30MB -> ~19MB over the course of this session, including
  during periods when this session was running no commands at all. This points to an external,
  unrelated process on the shared host consuming `C:` space, not anything this module did.
  `git diff --stat` on both repos was re-verified identical before and after this finding.
- **Impact on this session's deliverables**: the Phase 2 (Binance adapter, 14 tests) and Phase 4
  (`ResearchDecisionPolicy`, 8 tests) PHP test runs completed and passed BEFORE the disk space hit
  critical levels -- their results are real and reported in TEST_EVIDENCE.md. The Phase 3
  `ForecastGatewayTest` (4 tests) was syntax-checked clean (`php -l`, which does not need the
  Laravel bootstrap or much memory) but could NOT be executed once the crisis hit -- honestly
  marked `NOT_RUN`, not claimed as passing. No final full-suite re-run was possible after this
  point for the same reason.
- **Action taken**: stopped adding further PHP code that would need test verification (Phase 5
  experiment runner, Phase 6 API/UI) rather than build on an unverifiable foundation. Background
  Python downloads/installs on `D:` were left running (harmless, isolated, no `C:` impact) but no
  new large operations were started.
- **Next action needed from the owner**: free up `C:` drive space on this host (this agent has no
  visibility into what the unrelated process is and will not delete files outside this module's
  own paths without explicit instruction), then resume with a fresh `php artisan test` baseline
  re-run to confirm the exact state before continuing Phase 3 verification and Phase 5/6.
- **Predecessor**: none.
- **UPDATE, same session**: `C:` free space recovered on its own (observed climbing from ~19MB to
  ~2.5GB, then fluctuating in the hundreds-of-MB range) while a background Python install was
  still running on `D:`. `php artisan test` started working again. Work resumed cautiously (see
  MOB-D-010/011 below) rather than treating this as fully resolved -- `C:` free space is still
  abnormally low for a 199GB drive and may recur; the owner should still investigate the root
  cause, this agent only observed the symptom clearing, not its cause.

## MOB-D-010 (2026-10-15, session 2 continued): Granite TTM-R3's published weights do not load correctly -- real, confirmed, not assumed

- **Finding**: after `C:` recovered, the previously-incomplete `granite-tsfm==0.3.3` install
  finished in its own venv (`D:\autofinx-mob-v4\venv-granite`: `torch==2.8.0`,
  `transformers==4.56.0`, `granite-tsfm==0.3.3`). Loading `ibm-granite/granite-timeseries-ttm-r3`
  via the library's OWN documented/recommended entry point --
  `tsfm_public.toolkit.get_model.get_model(...)`, not a guessed or custom API -- logs "Some
  weights of TinyTimeMixerForPrediction were not initialized from the model checkpoint... and are
  newly initialized" for the large majority of the model (all 10 encoder mixer blocks, all 4
  decoder mixer blocks, the patcher, the decoder adapter, and the forecast head), and the library
  itself prints "You should probably TRAIN this model on a down-stream task to be able to use it
  for predictions and inference." The forward pass itself runs without error and returns a
  correctly-shaped `(1, 4, 1)` tensor (reproduced with `context_length=512`,
  `prediction_length=4`), but its *values* cannot be trusted -- they are dominated by randomly
  initialized weights, not the pretrained checkpoint.
- **Decision**: `external-predictive-engine/market_opportunity_brain/models/granite_ttm.py`
  programmatically captures this exact warning (via a temporary `logging.Handler` on
  `transformers.modeling_utils`) and returns `status: MODEL_UNAVAILABLE`,
  `reason: ZERO_SHOT_WEIGHTS_NOT_VERIFIED_SEE_DECISIONS_MD_MOB_D_010` whenever it is detected --
  verified working end-to-end via both the CLI and the real PHP `ForecastGateway` bridge (see
  TEST_EVIDENCE.md §15). It never reports `OK` for an untrained forward pass, regardless of how
  plausible the output numbers might look.
- **Not yet root-caused**: whether this is a revision mismatch (the default `main` branch not
  matching this `granite-tsfm` version's expected checkpoint layout), a `granite-tsfm` version
  mismatch against the currently-published HF repo, or a genuine repo issue. MASTER_SPEC.md's own
  caution ("do not blindly assume custom inference signatures or model dimensions") undersold this
  risk -- it is not just an API-shape question but a correctness question discovered only by
  trying the real call and reading the library's own warning. **Next step for a future session**:
  try an explicit older/newer `revision=` pin, or a different `granite-tsfm` version, and compare.
- **Predecessor**: MOB-D-008 (which first established the two-venv architecture this finding
  depends on).

## MOB-D-011 (2026-10-15, session 2 continued): `ForecastGateway` must not depend on the caller's ambient shell environment

- **Finding**: a real end-to-end test of `ForecastGateway` via `php artisan tinker` (no `HF_HOME`/
  `TEMP`/`TMP` set in that shell) timed out calling Chronos-2 (`INFERENCE_TIMEOUT` at the
  configured 60s), even though the identical model call succeeded in ~13s when invoked directly
  with those env vars set. The subprocess was silently falling back to default
  cache/temp locations (effectively `C:`, which was the critically-low drive from MOB-D-009) and/or
  re-attempting network resolution instead of using the already-cached `D:` weights.
- **Decision**: `config/market_opportunity_brain.php` gained `python_env_overrides` (built from
  `AUTOFINX_MOB_HF_HOME`/`AUTOFINX_MOB_HF_HUB_CACHE`/`AUTOFINX_MOB_PYTHON_TMP_DIR`, all unset/empty
  by default) and `ForecastGateway` now passes them explicitly as the subprocess's `env` (Symfony
  `Process`'s 3rd constructor argument) instead of relying on inheritance. Default
  `forecast_timeout_seconds` raised from 60 to 120 to accommodate real model-loading latency.
  Re-verified working end-to-end afterward (TEST_EVIDENCE.md §15): Chronos-2 now returns `status:
  OK` with real quantiles through the full PHP→Python bridge in ~9s.
- **Predecessor**: none.

## MOB-D-012 (2026-10-15, session 2 continued): fixed a real quantile-indexing bug in `chronos2.py`

- **Finding**: `Chronos2Pipeline.predict_quantiles([series], ...)` returns, per item,
  a tensor of shape `(channels=1, horizon_bars, num_quantile_levels)` -- confirmed by direct
  inspection of real output (`torch.Size([1, 4, 3])` for a single univariate series) -- NOT
  `(horizon_bars, num_quantile_levels)` as a naive reading of the library's type hint
  (`list[Tensor]`) might suggest. The first adapter version indexed `quantiles[0][-1]`, which
  (because the leading dimension is size 1) returned the *entire* `(4, 3)` block instead of the
  single `[p10, p50, p90]` triple for the final horizon step, causing a real
  `INFERENCE_ERROR: float() argument must be a string or a real number, not 'list'`.
- **Decision**: fixed to `quantiles[0][0][-1]` (index the size-1 channel dim, then the last
  horizon step). Re-verified correct afterward: output now matches the values independently
  confirmed via a raw, unwrapped `predict_quantiles` call (`p10=80519.48, p50=80874.20,
  p90=81193.06` for the real captured BTCUSDT series -- see TEST_EVIDENCE.md §15).

## MOB-D-013 (2026-10-15, session 3): host `C:` disk exhaustion recurred, now total (0-36MB free); session stopped before Phase 5/6

- **Finding**: at the start of this session, `C:` (the same drive hosting this Laravel app, PHP,
  and the Windows paging file -- see MOB-D-009) was measured at `199G used / 36M avail (100%)`,
  i.e. effectively zero free space, worse than MOB-D-009's low point (~19MB, which recovered on its
  own to 2.5GB+ by the end of session 2). A background task titled "Run full backend test suite for
  updated final count" (launched by a prior turn, not re-creatable from this turn's context) failed
  with exit code 1; its captured output file contained only `tail: write error: No space left on
  device`, confirming the failure is disk exhaustion, not a code/test regression -- the test
  runner's own output could not even be written to disk.
- **Verification performed this session before concluding "do not proceed"**: re-ran `df -h /`
  twice, confirming the near-zero state is sustained, not a transient blip; ran `git status --short`
  and `git diff --stat` in `autofinx-backend/backends`, confirming **zero MOB-module files are
  modified or corrupted** -- the only modified-tracked files
  (`app/Domain/Trading/RobotSessionService.php`, `app/Http/Controllers/Api/RobotSessionController.php`,
  `app/Models/RobotSession.php`) and untracked StrategyLab files/migration are the same
  pre-existing, intentionally-untouched RobotSession/StrategyLab changes already noted in
  `PROGRESS.md` session 2 -- not caused by this session or by the disk event.
- **Decision**: per this agent's own operating rule ("if experimental evidence fails / tooling
  unavailable, mark the stage honestly blocked" and "stop at a genuine blocker"), **no new code,
  no test execution, and no further file-writing work was attempted this session** beyond this
  documentation update (append-only, append-only in `PROGRESS.md`, and this entry), because even a
  small write risks failing or (worse) writing a truncated/corrupt file when free space is in the
  tens of megabytes on a 199GB volume. Phase 5 and Phase 6 (both pre-authorized by the owner per
  session 2's `PROGRESS.md`) are **not started this session** and should not be attempted by any
  future session until the host operator confirms `C:` has durable free space (at minimum a few
  GB headroom, not a one-time transient recovery as seen in MOB-D-009).
- **Scope check**: freeing host-level `C:` disk space (identifying and removing whatever is
  consuming ~199GB) is outside this module's file paths and outside this agent's designated
  repository scope (`autofinx-backend/backends`); no system file outside this agent's own
  scratch/task-output directories (which were checked and are only ~236MB, not a material
  contributor) was touched, inspected in detail, or deleted this session.
- **Predecessor**: MOB-D-009 (first occurrence, self-recovered); this is a recurrence that did
  **not** self-recover within this session and is more severe (0-36MB vs ~19MB-2.5GB).
- **Open question for the owner/host operator**: same as MOB-D-009's unresolved tail -- what is
  consuming `C:` and why does it fluctuate between near-zero and a few GB without any action from
  this agent (all MOB-related installs are confirmed isolated to `D:`). This agent cannot safely
  investigate further without risking destructive reads/writes on a full system volume outside its
  module scope.

## MOB-D-014 (2026-10-15, session 4): fresh baseline confirmed clean; disk recovered then re-drained mid-session by something outside this agent -- Chronos-2/Granite download deliberately NOT attempted

- **Context**: the coordinator reported the owner independently freed ~7GB on `C:` via
  `npm cache clean --force` + `pip cache purge`, reaching 11GB avail (95% used), and asked this
  agent to re-run a fresh baseline, then check for `D:`-isolation leaks onto `C:`, then retry the
  Chronos-2/Granite TTM-R3 downloads.
- **Independently verified**: `df -h /c` at the start of this session read `199G used / 11G avail`
  (matches the coordinator's figure). `git status --short` + `git diff --stat` in
  `autofinx-backend/backends` re-run fresh: identical to MOB-D-013's finding -- only the
  pre-existing, intentionally-untouched `RobotSession`/`StrategyLab` files are modified/untracked;
  zero MOB-module files touched.
- **Fresh full-suite baseline (`php artisan test --compact`, 514.57s)**: `1 failed, 1 skipped, 2269
  passed (17136 assertions)`. The 1 failure is the same pre-existing, MOB-unrelated
  `Tests\Feature\QuickTradeManual\ManualEntryTest > validation: stop-loss is mandatory...` case
  (expected 422, got 201 for an `invalid_symbol` case at `tests/Feature/QuickTradeManual/
  ManualEntryTest.php:189`) already known from session 2 (`PROGRESS.md`: `1 failed, 1 skipped, 2243
  passed`). Pass count is +26 vs session 2's figure, consistent with additional
  MOB/StrategyLab-adjacent tests now included; no new failure was introduced. **Caveat**: the shell
  command used `php artisan test --compact 2>&1 | tail -80; echo EXIT_CODE=$?`, which captures
  `tail`'s exit code, not `php artisan test`'s, due to a scripting oversight -- the printed
  `EXIT_CODE=0` is **not a verified exit code for the test run itself**; the "1 failed" count in the
  PHPUnit/Pest summary text is the reliable signal here, not the shell `$?`.
- **`D:`-isolation audit (requested by the coordinator) -- NOT perfect, documented honestly**:
  `C:\Users\PC\.cache\huggingface` = **121M** (a real leak onto `C:` despite `AUTOFINX_MOB_HF_HOME`
  being designed to redirect it -- see note below on why); `C:\Users\PC\AppData\Local\pip` = 283K
  (negligible); `C:\Users\PC\.cache` total = 723M; `C:\Users\PC\AppData\Local\Temp` = 903M (mixed,
  not attributable to MOB specifically -- this is the shared Windows user temp dir used by many
  unrelated apps). For comparison, the intentionally-isolated `D:\autofinx-mob-v4\` caches from
  session 2 are confirmed still there and correctly sized: `hf-cache` 462M, `pip-cache` 511M, `tmp`
  246M. **Root cause of the 121M `C:` leak**: `backends/.env` currently has **no**
  `AUTOFINX_MOB_HF_HOME` / `AUTOFINX_MOB_HF_HUB_CACHE` / `AUTOFINX_MOB_PYTHON_TMP_DIR` keys set at
  all (confirmed via grep -- zero matches), so `config/market_opportunity_brain.php`'s
  `python_env_overrides` (`:69-73`) currently resolves to an **empty array** via `array_filter`,
  meaning the subprocess env override that MOB-D-011 proved works is not actually active by default
  right now -- the 121M on `C:` is consistent with whatever `huggingface_hub`/`transformers`
  sub-operations ran without those overrides set (e.g., during initial venv/package setup, or any
  ad hoc interactive Python session outside the `ForecastGateway` CLI path), not a flaw in the
  `ForecastGateway` mechanism itself. **Conclusion for the coordinator's question**: `D:` isolation
  is real and working for the actual `ForecastGateway` subprocess path when the env vars are set,
  but it is **not automatic** -- it requires `AUTOFINX_MOB_HF_HOME` etc. to actually be populated in
  `.env` (or passed explicitly) for every invocation, including ad hoc ones; this was not previously
  stated as plainly as it should have been.
- **`HF_TOKEN` check (per the coordinator's request, did not ask the owner to create one)**: `env |
  grep -i 'HF_TOKEN\|HUGGINGFACE\|HF_HOME\|TRANSFORMERS_CACHE\|PIP_CACHE_DIR'` in this shell returned
  no matches, and `.env` has no `HF_TOKEN`-equivalent key either. **No `HF_TOKEN` is configured
  anywhere in this environment.** Per instruction, this agent did not ask the owner to generate one
  and did not attempt the download unauthenticated either (see next finding for why it stopped
  before that point).
- **New finding that changed this session's plan -- disk volatility reproduced live, independent of
  this agent's actions**: `df -h /c` immediately before the test run (after only read-only `du`/
  `ls`/`env`/`git` commands had been run this session -- no writes, no downloads, no installs)
  already read **`195G used / 4.4G avail (98%)`** -- i.e. `C:` had drained by roughly 6.6GB from the
  coordinator's just-reported 11GB figure, within the same session, before this agent did anything
  disk-write-capable. Two more `df -h /c` checks a few minutes apart (one before, one immediately
  after the 514.57s test run) both read the same `4.4G avail`, so the drain is not continuous or
  caused by the test run itself -- it looks like a discrete, external, bursty consumer unrelated to
  this agent (consistent with the coordinator's own prior observation that `C:` "kept draining after
  [the agent] stopped" last session).
- **Decision**: **did NOT attempt the Chronos-2 or Granite TTM-R3 download retry this session.** The
  coordinator's authorization to proceed was explicitly conditioned on "11GB free... enough headroom
  to avoid the exact VirtualAlloc/paging-file crash" being the current state; independently-verified
  real-time headroom is actually **4.4GB (98% full)**, less than half that margin, on a drive already
  proven capable of swinging several GB within minutes for reasons outside this agent's visibility or
  control. Starting a large, multi-hundred-MB-to-multi-GB model download onto a drive at 98% full
  with a demonstrated unpredictable external drain risks repeating the exact disk-exhaustion
  crash/corruption scenario documented in MOB-D-009/MOB-D-013 -- this is exactly the "paid/large
  network download" category this agent's operating rules require pausing on when the risk picture
  changes from what was assumed at authorization time, rather than proceeding on the strength of an
  instruction whose stated precondition no longer holds. This is a pause for a fresh real-time
  confirmation, not a reversal of the coordinator's plan -- Phase 3 continuation (Chronos-2 retry +
  Granite TTM-R3 install/inference), Phase 5, and Phase 6 all remain the correct next steps once
  headroom is re-confirmed.
- **Before any future attempt**: set `AUTOFINX_MOB_HF_HOME`, `AUTOFINX_MOB_HF_HUB_CACHE`, and
  `AUTOFINX_MOB_PYTHON_TMP_DIR` in `.env` (or the subprocess call site) to `D:\autofinx-mob-v4\...`
  paths for **both** venvs so `python_env_overrides` is non-empty by default (closing the gap found
  above), and additionally export `PIP_CACHE_DIR=D:\autofinx-mob-v4\pip-cache` and a
  `TRANSFORMERS_CACHE`/`HF_HUB_CACHE` pointing at `D:\autofinx-mob-v4\hf-cache` for whatever shell
  actually runs `pip install`, since that step runs outside `ForecastGateway`'s controlled
  subprocess env and is a separate leak surface. Re-check `df -h /c` immediately before and
  immediately after, not just `/d`, exactly as the coordinator requested.
- **Scope check**: no file outside `docs/market-opportunity-brain-v4/` was modified this session;
  the full-suite test run and all `du`/`df`/`git` checks are read-only or test-only (standard
  PHPUnit/Pest execution against the existing isolated test DB, not production data); no live
  trading, wallet, webhook, migration, deploy, or commit/push action was taken; `execution_authorized`
  remains hard-`false` and untouched.
- **Predecessor**: MOB-D-013.

## MOB-D-015 (2026-10-15, session 6): mob-exp-v1 design, D: storage, verdict
- **D: storage**: `.env` already pointed MOB python/HF/TMP to `D:\autofinx-mob-v4\...` (weights cached there, 456M). Kept that (the keys the code reads) to avoid re-downloading; created `D:\autofinx-ml\{hf-cache,tmp,pip-cache}` and added generic HF_HOME/HUGGINGFACE_HUB_CACHE/PIP_CACHE_DIR to `.env` as owner asked. No secrets read or printed. Experiment outputs go to `D:\autofinx-ml\mob-experiment-v1`.
- **Sealed holdout interpretation**: the global seal is 2026-01-04 (ml-engine-v1). Live "recent" Binance data is entirely inside it, so the builder uses a pre-seal window (2025-07..12) only; fetcher refuses endTime >= seal, builder excludes windows whose label matures after it. Dec 2025 was previously used by Edge Search v2 for other hypotheses (not this one); Chronos-2 is pretrained so no tuning on this window occurred.
- **Arms**: A = non-ML momentum stand-in (fixed-five TradingView history is unavailable); C = Chronos-2 p50 with fee-aware threshold = round-trip cost. Parameters frozen in plan hash before data; smoke run results were not used to change anything.
- **Verdict**: REJECTED_NO_EDGE for both A and C at 1x and 2x cost. Chronos-2 MAE 1.046x naive; direction hit 48.7%. No threshold loosening, no promotion. Predecessor: MOB-D-014.
