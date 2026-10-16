# AUDIT_FINDINGS.md — P0 source audit (session 2026-10-15)

Scope: verify `CURRENT_REPOSITORY_AUDIT.md`'s claims against the actual checked-out source at
`autofinx-backend/backends` (Laravel 12/PHP 8.2) and `autofinx-front/frontends` (React/Vite/TS),
per this codebase's own established rule ("every large spec/audit handed into this codebase has
contained at least one confident-sounding claim that turned out wrong when actually checked" —
root `AGENTS.md`). Method: direct file reads and greps, not trust of the doc. No code was changed
during this audit pass; this file is read-only evidence collection.

## Per-claim verification

| # | CURRENT_REPOSITORY_AUDIT.md claim | Verified? | Evidence |
|---|---|---|---|
| 1 | `app/Domain/Nexus/NexusCycleService.php` exists; Nexus has a 15-min scheduler | CONFIRMED | `app/Domain/Nexus/NexusCycleService.php` exists (file present); `routes/console.php:39-40`: `if ($schedulerEnabled && config('nexus.enabled', false)) { Schedule::command('nexus:run-cycle')->everyFifteenMinutes()... }` — matches exactly. |
| 2 | Nexus routes under `routes/api.php:119+`; frontend `App.tsx:51,83` | CONFIRMED | `routes/api.php` has `Route::prefix('workspaces/{workspace}/nexus')` group with `/`, `/entries`, `/export`, `/run`, `/experiments`, `/candidates/{entry}/review`. `frontend/src/App.tsx:51` `const NexusPage = lazy(...)`, `:83` `<Route path="/nexus" .../>` — confirmed, same line numbers. |
| 3 | `docs/ml-engine-v1/DECISIONS.md` D-046 / D-050 / D-053 at specific lines | CONFIRMED EXACTLY | `grep -n "D-046\|D-050\|D-053"` returns: D-046 at line 453, D-050 at line 467, D-053 at line 483 — identical to the audit's cited line numbers. D-046 verdict text confirmed: "no gross edge (+0.002..+0.007% per trade vs a 0.12% cost)"; D-050 confirmed as the owner freeze decision. |
| 4 | `docs/ml-engine-v1/AGENT.md` non-negotiable rules (live safety, no git commit, holdout sealed) | CONFIRMED | Read `docs/ml-engine-v1/AGENT.md` in full (90 lines): confirms V4 is research/local/shadow only, never touches Quick Trade/Future Trade/Robot Trade/Ratio Bot/Paper Autopilot execution, final holdout sealed until design frozen, never `git commit/push/merge`. |
| 5 | `StrategyLabService.php`, `StrategyLabRunner.php`, `config/strategy_lab.php` exist; routes at `routes/api.php:147-161` | CONFIRMED (line numbers close, not exact) | Files exist. `routes/api.php` Strategy Lab group is a few lines earlier than claimed (observed around line ~146-156 in the current checkout) but contains exactly the claimed endpoints (`/candidates`, `/candidates/{candidate}`, `/runs/{run}`, `/runs/{run}/chart`, quick-test/deep-test, webhook-token/rotate, shadow-report). Substance confirmed; line numbers drift by a handful of lines (expected — file churns daily). |
| 6 | Strategy Lab doesn't execute arbitrary Pine; `config/strategy_lab.php` has fixed `normalized_sha256` per adapter; `StrategyLabRunner.php` launches `v4.lab_runner --adapter ...` as a subprocess, no shell | CONFIRMED | `config/strategy_lab.php:35-56` `'adapters' => [...]` with `normalized_sha256` arrays for `autofinx-v13`, `v1`-`v4`. `StrategyLabRunner.php:79`: `return [(string) config('strategy_lab.python_bin'), '-m', 'v4.lab_runner', '--adapter', (string) $run->adapter, ...]`, built via Symfony `Process` (no shell string). |
| 7 | `app/Domain/Forecast/ForecastService.php:8-36` is a simple EMA-based stub, not deep ML | CONFIRMED | Read the full file: `predictNext()` fetches last 30 candles, computes an EMA, returns `['price' => ..., 'confidence' => 0.4]` — a hardcoded confidence constant, exactly as claimed. Must not be treated as a forecasting baseline for this project. |
| 8 | `app/Domain/MarketData/Providers/BinanceMarketDataProvider.php:13-20` has no-op subscribe methods and `historicalCandles()` returns empty | CONFIRMED EXACTLY | Lines 13-20 read: `connect(): void {}`, `disconnect(): void {}`, `subscribeQuotes(...): void {}`, `subscribeTrades(...): void {}`, `subscribeCandles(...): void {}`, `latestQuote(...): ?NormalizedQuote { return null; }`, `historicalCandles(...): iterable { return []; }`. `health()` unconditionally returns `FeedState::LIVE` regardless of the no-op body above it — confirms the audit's warning that "a health() method reporting LIVE is not evidence that OHLCV reads work." **Do not build discovery on this provider.** |
| 9 | `app/Domain/Market/FutureTradeLiveFeedManager.php:12-30`: streams fixed to `depth5@500ms,kline_1m,kline_5m` | CONFIRMED | Line 23: `private const STREAMS = 'depth5@500ms,kline_1m,kline_5m';` with an explicit code comment explaining why (`'trade'` family was removed as unrouted, 2026-09-22 hotfix). Confirms: do not commandeer this manager for all-symbol backfill. |
| 10 | `config/future_trade.php:101-124` `available_symbols` (50-name registry) includes `STRKUSDT`; runtime defaults restrict to ~4 symbols | CONFIRMED (line numbers drift slightly) | `available_symbols` array is actually at lines 109-122 in the current checkout (not 101-124 — a ~8-line drift), and does include `STRKUSDT` (line 120) among 50 curated perpetuals. `defaults.symbols` (claimed at `:159-168`) is actually at line 164: `['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT']` — exactly 4 symbols as claimed. Substance fully confirmed; line numbers are approximate, not exact, in the live checkout vs. the archived snapshot the audit was written against. |
| 11 | Webhook/mirror live flags guarded; `FutureTradeBotService.php` lines ~1757+/3389-3390/4189-4190 reference pine_webhook authority | CONFIRMED (substance; exact line text differs from snapshot) | `config/future_trade.php:35-38`: `pine_webhook_token`, `prime_mirror_execution_enabled`, `prime_mirror_live_enabled`, `pine_webhook_live_enabled`. `FutureTradeBotService.php` (12,342 lines total) contains `rejectPineWebhook(...)`, `source_must_be_pine_webhook` checks, and `primeMirrorExecutionBlocked(...)` gating with reason codes `backend_scanner_read_only_webhook_mode` / `prime_mirror_live_disabled_pending_tv_csv_validation` / `prime_mirror_execution_disabled_use_tradingview_webhook` at multiple call sites — confirms the audit's point that mirror/live execution is explicitly gated and must never be touched by this project. |
| 12 | `app/Domain/Intelligence/MasterBrainService.php:14-67` has `AUTO_TUNE` mode and an `apply()` method that can auto-apply bot parameters | CONFIRMED | `mode()` reads `AI_MODE` env, allows `OFF/ADVISORY/AUTO_TUNE`. `recommend(Bot $bot, bool $autoApply = false)`: if `$mode === 'AUTO_TUNE' && $autoApply` (or `AUTO_TUNE_FORCE`), calls `$this->apply($bot, $recommendation)`, which merges suggested params into `$bot->strategy_params` and saves. Confirms: **never call `recommend(autoApply: true)` or `apply()` from the new Brain.** |
| 13 | `external-predictive-engine/requirements-v4.txt` lists only LightGBM/numpy/sklearn/pyarrow/pytest — Chronos/TTM not installed | CONFIRMED EXACTLY | File content: `lightgbm==4.7.0`, `numpy==2.2.6`, `scikit-learn==1.7.2`, `pyarrow==25.0.1`, `pytest==9.1.1`, with header comment "V4 local research environment only (SPEC §93). Never installed on the production server." No Hugging Face / `transformers` / `chronos-forecasting` / `torch` dependency anywhere in that file. |
| 14 | Frontend `App.tsx` / `useStrategyLab.ts` / `strategyLab.ts` / sidebar module registration pattern | CONFIRMED | `App.tsx:58-59` lazy-imports `StrategyLabPage`/`CandidatePage`; `:301-308` registers `/strategy-lab` and `/strategy-lab/:candidateId` behind `<ModuleRoute moduleKey="strategy-lab">`. `src/components/layout/Sidebar.tsx:74`: `"strategy-lab": Beaker` icon mapping confirms the sidebar registration convention a future session would need to copy (NOT done in this session — see PROGRESS.md). |

## Drift summary

No claim in `CURRENT_REPOSITORY_AUDIT.md` was substantively wrong. The only drift found was in
**line numbers**, not facts: several citations (`routes/api.php:147-161`, `future_trade.php:101-124`
/`:159-168`, `FutureTradeBotService.php:1757+/3389-3390/4189-4190`) are off by roughly 5-15 lines
against the live checkout used in this session, consistent with normal day-to-day file churn
between when the archived snapshot (`backend(7).zip`, referenced in the audit's own provenance
section) was taken and this session's live checkout. Every underlying architectural claim —
Nexus scheduler shape, Strategy Lab's fixed-adapter-hash model, the `ForecastService` stub, the
no-op `BinanceMarketDataProvider`, the fixed live-feed stream set, the 4-symbol runtime default vs
50-symbol registry, the pine-webhook/prime-mirror guard rails, `MasterBrainService.apply()`, and
the absence of Chronos/TTM dependencies — was independently confirmed true against the real code,
not merely assumed. This matches the root `AGENTS.md` pattern ("verify against the real code
before trusting a spec") with one difference from past sessions: this time the audit document
itself held up, except for exact line citations.

## Additional facts discovered during this session not covered above

- The backend git root is `autofinx-backend` (one level above `backends/`), confirmed via
  `git -C autofinx-backend/backends rev-parse --show-toplevel` → `C:/xampplast/htdocs/auto_finx/autofinx-backend`.
  There was **pre-existing uncommitted work** in that repo at session start, unrelated to this
  module: modified `app/Domain/Trading/RobotSessionService.php`,
  `app/Http/Controllers/Api/RobotSessionController.php`, `app/Models/RobotSession.php`, plus
  untracked `app/Domain/StrategyLab/StrategyActivationGate.php`,
  `app/Domain/StrategyLab/StrategyRiskProfile.php`, a pending migration
  `2026_10_16_000000_add_strategy_activation_to_robot_sessions.php`, and
  `tests/Unit/StrategyLab/`. None of these were touched, read beyond `git status`, or depended on
  by this module — preserved byte-for-byte per this project's own rule.
- The frontend git repo (`autofinx-front`) also had pre-existing uncommitted changes at session
  start: `src/components/trading/manual/ManualOrderForm.tsx`,
  `src/components/trading/manual/ManualQuickTradeSection.tsx`, `src/pages/QuickTradePage.tsx` —
  also untouched.
- `App\Http\Controllers\Concerns\AssertsWorkspaceMembership` (trait) is the canonical, already
  shared workspace-authorization pattern (used by `StrategyLabController` and others) that any
  future Phase 6 `MarketOpportunityBrainController` should reuse rather than reinvent, per root
  `AGENTS.md` item 4.
- `config/strategy_lab.php` is the closest existing stylistic precedent for the new
  `config/market_opportunity_brain.php` written this session (feature flag, `work_dir`/`python_bin`
  pattern, all behind `env()` calls with safe defaults).
- **`App\Support\Clock\{Clock,SystemClock,FrozenClock}` already exists** (Carbon-based,
  `now(): CarbonImmutable` in `config('app.timezone', 'Asia/Colombo')`, with a settable
  `FrozenClock` for tests). This was found only *after* this session had already written a
  near-duplicate `App\Domain\MarketOpportunityBrain\Support\{Clock,SystemClock,FixedClock}`
  (native `DateTimeImmutable`, hard-UTC). Per root `AGENTS.md`'s "use this, do not write a second
  implementation" lesson (originally about `Indicators::adx()`), the duplicate was deleted from
  this module once discovered (it had zero consumers yet — nothing in Phase 1 actually used a
  Clock abstraction, since `DeterministicMarketFixtureProvider` and `NaiveLastForecastAdapter` both
  take an explicit `\DateTimeImmutable $asOfUtc` parameter instead). **Correction recorded, not
  hidden** — see `DECISIONS.md` MOB-D-005. Any future phase that needs an injectable clock should
  reuse `App\Support\Clock\Clock` and convert to UTC explicitly at the call site
  (`$clock->now()->utc()`), not reintroduce a parallel abstraction.
- Python 3.10.11 is installed and on PATH (`C:\Users\PC\AppData\Local\Programs\Python\Python310\python.exe`,
  matches `docs/ml-engine-v1/AGENT.md`'s documented research Python version), but **no project
  virtualenv exists yet** for `external-predictive-engine` and the global Python 3.10 install has
  no `pytest` (`python -m pytest --version` → `No module named pytest`). The new Python contracts
  module in this session was deliberately written stdlib-only (`dataclasses`, `unittest`) so it is
  actually runnable without installing anything — see TEST_EVIDENCE.md.
