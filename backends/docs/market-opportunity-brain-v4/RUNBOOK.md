# RUNBOOK.md — Market Opportunity Brain (Phase 1 state)

## Current state: nothing is running

No scheduler, queue, worker, route, or cron entry exists for this module. `config('market_opportunity_brain.enabled')`
defaults `false` and nothing in the codebase reads it yet. There is nothing to start, stop, or roll
back in production — this runbook documents the **local, offline** developer/test workflow only.

## Running the PHP unit tests (offline, no network, no real DB data needed)

```
cd autofinx-backend/backends
php artisan test tests/Unit/MarketOpportunityBrain --compact
```

## Running the Python contract-parity tests (offline, stdlib only)

```
cd autofinx-backend/backends/external-predictive-engine
python -m unittest market_opportunity_brain.tests.test_contracts -v
```

No venv, no `pip install`, no network access needed — `contracts.py` and its tests use only the
Python standard library on purpose.

## Running the frontend typecheck for the new types file

```
cd autofinx-front/frontends
npx tsc -p tsconfig.app.json --noEmit
```

## Health / rollback

Not applicable yet: there is no running process, no deployed route, and no flag flip that could
affect production, because nothing in this module is wired into the app's routes, scheduler, or
container bindings. The rollback story for Phase 1 is simply: delete the
`app/Domain/MarketOpportunityBrain/`, `config/market_opportunity_brain.php`,
`external-predictive-engine/market_opportunity_brain/`,
`tests/Unit/MarketOpportunityBrain/`, and `frontends/src/types/marketOpportunityBrain.ts` paths;
nothing else in the application references them.

## Session 2 additions: real Binance smoke test + Python model venvs

### Binance public-data smoke test (opt-in, real network, never scheduled)

```
cd autofinx-backend/backends
AUTOFINX_MOB_PUBLIC_DATA_ENABLED=true AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE=1200 \
AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS=10 php artisan mob:binance-smoke-test --symbol=BTCUSDT
```
Never places an order; only calls `GET /fapi/v1/{exchangeInfo,ticker/24hr,klines}`.

### Python model venvs (local machine only, NOT part of the git repo)

Two SEPARATE venvs are required (see `DECISIONS.md` MOB-D-008 for why):
```
D:\autofinx-mob-v4\venv            # naive_last, chronos2 (torch==2.14.1, transformers==5.19.0, chronos-forecasting==2.3.2)
D:\autofinx-mob-v4\venv-granite    # granite_ttm_r3 ONLY (needs transformers==4.56.0, torch<2.9 -- incompatible with the venv above)
```
Set `AUTOFINX_MOB_PYTHON_BIN=D:\autofinx-mob-v4\venv\Scripts\python.exe` and
`AUTOFINX_MOB_GRANITE_PYTHON_BIN=D:\autofinx-mob-v4\venv-granite\Scripts\python.exe` (local `.env`
only, never committed) plus `AUTOFINX_MOB_FORECAST_ENABLED=true` to let `ForecastGateway` actually
shell out. Caches: `HF_HOME`/`HUGGINGFACE_HUB_CACHE`/`PIP_CACHE_DIR`/`TEMP`/`TMP` should all point
at `D:\autofinx-mob-v4\{hf-cache,pip-cache,tmp}` -- **never let these default to `C:`**, which was
critically low on free space this session (see `DECISIONS.md` MOB-D-009).

Manual CLI test (works today, naive_last has zero heavy deps):
```
cd autofinx-backend/backends/external-predictive-engine
D:\autofinx-mob-v4\venv\Scripts\python.exe -m market_opportunity_brain.cli predict --model naive_last \
  --symbol BTCUSDT --timeframe 15m --horizon-bars 4 --as-of-utc 2026-10-15T00:00:00Z \
  --workspace-id local-research-workspace --closes-json "[100.0,101.0,102.0]"
```

## No-live checklist (must stay true through every future phase)

- [ ] No route in `routes/api.php`/`routes/web.php` mentions `MarketOpportunityBrain` until Phase 6.
- [ ] No binding in any `ServiceProvider` wires a concrete `ForecastProvider`/`DecisionPolicy` into
      a job or controller until the phase that needs it, and even then only behind
      `AUTOFINX_MOB_ENABLED=false`-by-default flags.
- [ ] `DecisionRecord` never gains a `quantity`/`leverage`/`api_key`/`order_type` field.
- [ ] No code path calls `FutureTradeBotService`, `BinanceFuturesClient`, or any order-placement
      method, enforced today by `ModuleIsolationTest`'s source-level grep guard.
- [ ] `execution_authorized` stays hard-coded `false` in every DTO.
