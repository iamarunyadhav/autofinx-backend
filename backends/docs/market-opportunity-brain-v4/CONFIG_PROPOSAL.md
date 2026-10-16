# New Brain config proposal — OFF by default

Create `config/market_opportunity_brain.php` in actual backend only after verifying repo conventions. All env and runtime switches must be independent of current Future Trade, Nexus, Strategy Lab and ML flags.

```env
# Illustrative only; don't add to secrets and don't change production .env automatically
AUTOFINX_MOB_ENABLED=false
AUTOFINX_MOB_PUBLIC_DATA_ENABLED=false
AUTOFINX_MOB_SCHEDULER_ENABLED=false
AUTOFINX_MOB_FORECAST_ENABLED=false
AUTOFINX_MOB_EXTERNAL_MODEL_DOWNLOAD_ENABLED=false
AUTOFINX_MOB_BACKTEST_ENABLED=false
AUTOFINX_MOB_RESEARCH_HOST_URL=
AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS=0
AUTOFINX_MOB_TOP_K=20
AUTOFINX_MOB_MAX_PARALLEL_INFERENCES=1
AUTOFINX_MOB_REQUEST_TIMEOUT_SECONDS=10
AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE=0
AUTOFINX_MOB_DATA_CACHE_TTL_SECONDS=300
AUTOFINX_MOB_VERSION=research-v1
```

`MAX_DISCOVERY_SYMBOLS=0` and `MAX_API_WEIGHT_PER_MINUTE=0` mean the external poller remains disabled unless explicitly configured. Agent must implement consistent interpretation and positive validated local limits before running market downloads. Batch/worker settings and actual Binance budget limits are subject to latest official API documentation and deployment environment. This document is a proposed contract, not an installed env file.

Status values: `DISABLED`, `OFFLINE_MOCK`, `WAITING_FOR_DATA`, `SCANNING`, `FORECASTING`, `DECIDING`, `EVALUATING`, `DEGRADED`, `BLOCKED`; each with UTC last-success, request budget and reason code. Availability is not equivalent to profitability.

Never use `FUTURE_TRADE_PINE_WEBHOOK_LIVE_ENABLED`, `FUTURE_TRADE_PRIME_MIRROR_LIVE_ENABLED`, `AI_MODE`, or `AUTOFINX_NEXUS_AUTONOMY_LEVEL` as a gate for this research module; doing so would couple permissions.
