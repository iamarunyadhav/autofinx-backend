# AutoFinX source audit — supplied 15 Oct 2026 snapshot

## Scope and provenance

Read-only inspection of `backend(7).zip` (2962 archive entries, ~1.11 GB uncompressed, including large logs/reports) and `frontend(7).zip` (548 entries). Inspected source, docs, config, routes, tests and frontend without trusting `.env` or logs. Extracted *source subset only*; did **not** execute application code, connect Binance, load database, run ML models, validate live server, install dependencies or change source archives. All paths below are **relative to the archive backend/frontend roots**; coding agent must reconfirm them in the real checkout. Four representative PHP classes syntax-checked successfully by `php -l`; this is **not** a full test suite.

## Confirmed source anchors and implications

| Area | Verified source evidence | Architectural implication |
|---|---|---|
| Existing agent hierarchy | `backend/AGENTS.md:1-9`; `backend/agent/AGENT_CORE.md`; `agent/ARCHITECTURE.md`; `agent/RISK_POLICY.md` | Do not replace root agent policy or grant Nexus order/deploy permissions. |
| Existing Nexus control plane | `app/Domain/Nexus/NexusCycleService.php`, `routes/console.php:39-40`, `routes/api.php:119+`, `frontend/src/App.tsx:51,83` | Nexus already has research/run/approval/cost structure. New Brain is a separate product, not a replacement for Nexus. |
| Existing ML V3/V4 frozen research | `docs/ml-engine-v1/AGENT.md:21-62`, `docs/ml-engine-v1/DECISIONS.md:453+ (D-046)`, `:467+ (D-050)`, `:483+ (D-053)` | Existing directional LightGBM work frozen, no verified after-cost edge, sealed 2026 holdout; new model tests cannot quietly reopen those experiments. |
| Existing research stack | `app/Domain/Ml/Shadow/*`, `Ml/Training/*`, `external-predictive-engine/v4/*`, `config/ml.php`, `backend/ml-v4-local.ps1` | Reuse read-only data contracts; don't rewrite or change model registry/labels without separate approval. |
| Strategy Lab already exists | `app/Domain/StrategyLab/StrategyLabService.php`, `StrategyLabRunner.php`, `config/strategy_lab.php`, `routes/api.php:147-161`, `frontend/src/pages/strategy-lab/` | Do not implement duplicate arbitrary Pine evaluator; import only versioned, read-only research outcomes where relevant. |
| Strategy Lab doesn't execute arbitrary Pine | `config/strategy_lab.php` `adapters` fixed normalized SHA256s; `StrategyLabRunner.php` launches `v4.lab_runner --adapter ...` | Don't treat upload as executable Pine or modify translation hashes. |
| Simple forecast is not deep ML | `app/Domain/Forecast/ForecastService.php:8-36` comments/30 closes/EMA constant 0.4 | Avoid replacing globally; new prediction model lives in separate adapter. |
| Canonical provider incomplete | `app/Domain/MarketData/Providers/BinanceMarketDataProvider.php:13-20` no-op methods; `historicalCandles()` empty | Must identify real market acquisition path (other Binance/FutureTrade sources) or implement dedicated *read-only* official-public adapter; never assume existing market adapter works. |
| Actual live feed transport separate | `app/Domain/Market/FutureTradeLiveFeedManager.php:12-30` `depth5@500ms,kline_1m,kline_5m`; `config/future_trade.php` | Don't overload or commandeer current feed manager for all-symbol backfill/deep streaming. |
| Symbol list ≠ dynamic market discovery | `config/future_trade.php:101-124` 50-name `available_symbols` includes `STRKUSDT`; defaults `:159-168` restrict runtime list ~4 (`ETH,BTC,SOL,BNB`) | User's five active coins may be runtime/config/UI-specific, but available symbol registry is already much larger. Dynamic market *ranking and analysis* is the missing target, not blindly adding STRK to allowlist. |
| Webhook authority protected | `config/future_trade.php:35-43`, `FutureTradeBotService.php:1757+`, `:3389-3390`, `:4189-4190` | Webhook/mirror live permissions explicitly guarded; new research engine must never call executor or toggle flags. |
| Existing master brain can apply params | `app/Domain/Intelligence/MasterBrainService.php:14-67` `AUTO_TUNE` and `apply()` | Do NOT call `recommend(autoApply=true)` or `apply()` in new brain. Optional only read-only projections. |
| Existing UI / API shape | `frontend/src/App.tsx:51-59, 83, 276-309`, `frontend/src/hooks/useStrategyLab.ts`, `src/lib/strategyLab.ts`, `routes/api.php:123-161` | New protected page should reuse React Query/module auth and workspace membership authorization conventions. |
| Resource constraint documented | `docs/ml-engine-v1/RESULTS.md` R-001 and agent docs; production overloaded during earlier evaluation | Keep Chronos/TTM training and inference off production trading server and feature flags off by default. |
| Research results are mostly negative | `DECISIONS.md` D-046/047/048/049/050/051/053; `docs/ml-engine-v1/PROGRESS.md` latest | Do not make profit predictions or silently loosen gates; preregister new research, allow `NO_EDGE`. |
| AI models not present by default | `external-predictive-engine/requirements-v4.txt` lists LightGBM/numpy/sklearn/pyarrow/pytest only | Chronos/TTM require separate opt-in dependency manifest + dedicated runtime; do not assume installed. |

## Specific codebase pitfalls

1. `permissive_symbol_mode` permits some incoming Pine symbol formats: this is **webhook acceptance**, not tested dynamic discovery and not a safe all-pairs trading selection system. Don't change permissive symbol settings in this project.
2. `StrategyLabRunner` has subprocess execution with a vetted adapter key and work-dir config; new models should use isolated worker/service and never turn arbitrary scripts into executable Python.
3. Nexus has own 15-minute scheduler, queue, workspace entries and capability-only autonomy. New brain should have distinct jobs, queue, namespaces and feature flags, disabled until reviewed.
4. `MasterBrainService` has existing auto-parameter application methods. Read-only is safe; calling apply isn't.
5. Existing MarketData Binance provider returns null/empty for read methods. A `health()` method reporting LIVE is not evidence that OHLCV reads work; unit-test actual adapter.
6. Historic 2026 sample data, training reports, production log archive and `.env` may contain sensitive material. They are **not** free license to upload to Hugging Face, LLMs, debug logs, or external services. Never include contents of `.env` in agent transcripts/reports.
7. Archive includes 1+ GB logs/reports; do not extract/share everything or parse unrestricted logs as part of coding agent initialization. Keep log size/time budgets.
8. The running Python market collector is separate from new inference; do not alter retention, restart or migrate it without explicit owner permission.

## What is NOT confirmed by static audit

- Whether five, four or more symbols are actively scanned **in deployed workspace configuration**.
- Current Binance connectivity, account status, precise live latency, order results and webhook runtime readiness.
- Model installability on user's research PC, RAM/GPU capacity, training data coverage across additional altcoins, source licensing, actual current exchange listings.
- Production DB migrations/workers status, completed Strategy Lab server rollout, final holdout access controls enforced outside code.
- Profitability of any existing or proposed strategy, fitness of Chronos-2/Granite TTM for crypto direction, quality of 2026 results beyond documented local experiments.

## Baseline checks run in this environment

- Counted archive entries / sizes; inspected source tree and documented core classes/routes.
- `php -l` successful on: `ForecastService.php`, `BinanceMarketDataProvider.php`, `StrategyLabRunner.php`, `MasterBrainService.php`.
- `php artisan test`, frontend `npm run build/typecheck` and Python model inference **NOT RUN** in this snapshot: the repository source-only extraction lacks Composer dependencies, node_modules, runtime database and model weights. No code changes performed.

## Recommended first agent action

Verify current branch/worktree and above claims, inspect the production-config *code paths* without reading/publishing private credentials, write a new audit with line refs, report precisely which current components already meet the mission, then write isolated new code without modifying existing execution files.
