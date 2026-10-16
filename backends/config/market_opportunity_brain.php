<?php

/*
 * AutoFinX V4 Market Opportunity Brain (docs/market-opportunity-brain-v4/MASTER_SPEC.md).
 *
 * RESEARCH ONLY. This config intentionally shares NO flag with Future Trade, Nexus, Strategy Lab
 * or ml-engine-v1 -- see docs/market-opportunity-brain-v4/CONFIG_PROPOSAL.md:27 ("Never use
 * FUTURE_TRADE_PINE_WEBHOOK_LIVE_ENABLED, FUTURE_TRADE_PRIME_MIRROR_LIVE_ENABLED, AI_MODE, or
 * AUTOFINX_NEXUS_AUTONOMY_LEVEL as a gate for this research module"). Every flag below defaults
 * OFF/0 and must be turned on explicitly and locally; nothing here is wired into a scheduler,
 * route, or job yet (Phase 1 of PHASES_AND_TEST_GATES.md -- contracts and fixtures only).
 *
 * `execution_authorized` is NOT a config flag: it is hard-wired `false` in
 * App\Domain\MarketOpportunityBrain\Data\DecisionRecord and cannot be changed here or anywhere else.
 */
return [
    'enabled' => (bool) env('AUTOFINX_MOB_ENABLED', false),
    'public_data_enabled' => (bool) env('AUTOFINX_MOB_PUBLIC_DATA_ENABLED', false),
    'scheduler_enabled' => (bool) env('AUTOFINX_MOB_SCHEDULER_ENABLED', false),
    'forecast_enabled' => (bool) env('AUTOFINX_MOB_FORECAST_ENABLED', false),
    'external_model_download_enabled' => (bool) env('AUTOFINX_MOB_EXTERNAL_MODEL_DOWNLOAD_ENABLED', false),
    'backtest_enabled' => (bool) env('AUTOFINX_MOB_BACKTEST_ENABLED', false),

    'research_host_url' => env('AUTOFINX_MOB_RESEARCH_HOST_URL'),

    'max_discovery_symbols' => (int) env('AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS', 0),
    'top_k' => (int) env('AUTOFINX_MOB_TOP_K', 20),
    'max_parallel_inferences' => (int) env('AUTOFINX_MOB_MAX_PARALLEL_INFERENCES', 1),
    'request_timeout_seconds' => (int) env('AUTOFINX_MOB_REQUEST_TIMEOUT_SECONDS', 10),
    'max_api_weight_per_minute' => (int) env('AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE', 0),
    'data_cache_ttl_seconds' => (int) env('AUTOFINX_MOB_DATA_CACHE_TTL_SECONDS', 300),

    'version' => env('AUTOFINX_MOB_VERSION', 'research-v1'),

    'workspace_id_default' => env('AUTOFINX_MOB_DEFAULT_WORKSPACE', 'local-research-workspace'),

    /*
     * Phase 2 (real-market discovery, read-only). Binance's own public, unauthenticated USDⓈ-M
     * futures REST endpoints only (exchangeInfo / klines / ticker/24hr) -- no API key, no order
     * endpoint, ever. Base URL is overridable only so tests can point at a fake/mock base.
     */
    'binance_public_base_url' => env('AUTOFINX_MOB_BINANCE_PUBLIC_BASE_URL', 'https://fapi.binance.com'),
    'min_quote_volume_24h' => (float) env('AUTOFINX_MOB_MIN_QUOTE_VOLUME_24H', 1_000_000),
    // Historical point-in-time universe reconstruction is NOT implemented (would need a bulk
    // archive adapter, e.g. data.binance.vision); the live adapter only answers "as of now" within
    // this tolerance and fails closed (explicit exception, never a fabricated historical universe)
    // outside it.
    'universe_as_of_tolerance_seconds' => (int) env('AUTOFINX_MOB_UNIVERSE_AS_OF_TOLERANCE_SECONDS', 120),

    /*
     * Phase 3 (Python forecasting adapters). `ForecastGateway` shells out to a FIXED CLI
     * (`market_opportunity_brain.cli predict --model ... `), the same subprocess pattern
     * `StrategyLabRunner` already uses for `v4.lab_runner` -- fixed argument list, no shell, no
     * arbitrary code execution. Research-only, OFF by default (`forecast_enabled` above).
     *
     * Two separate Python binaries/venvs are intentional, not a mistake: `chronos-forecasting`
     * (Chronos-2) requires `transformers>=5`, while `granite-tsfm` (Granite TTM-R3) pins
     * `transformers[torch]==4.56.0` + `torch<2.9` -- installing both in ONE venv downgrades
     * whichever installed second (confirmed in this session: see DECISIONS.md MOB-D-008).
     */
    'engine_dir' => base_path('external-predictive-engine'),
    'python_bin' => env('AUTOFINX_MOB_PYTHON_BIN'),
    'granite_python_bin' => env('AUTOFINX_MOB_GRANITE_PYTHON_BIN'),
    // Confirmed empirically this session (DECISIONS.md MOB-D-011): the subprocess must NOT rely
    // on inheriting the calling PHP process's ambient environment for these -- a bare `php artisan
    // tinker` invocation does not have HF_HOME/TEMP/TMP set, which left a real Chronos-2 call
    // hunting for cache/temp space on the default (here, critically low) `C:` drive and timing out.
    'python_env_overrides' => array_filter([
        'HF_HOME' => env('AUTOFINX_MOB_HF_HOME'),
        'HUGGINGFACE_HUB_CACHE' => env('AUTOFINX_MOB_HF_HUB_CACHE'),
        'TEMP' => env('AUTOFINX_MOB_PYTHON_TMP_DIR'),
        'TMP' => env('AUTOFINX_MOB_PYTHON_TMP_DIR'),
    ]),
    'forecast_timeout_seconds' => (int) env('AUTOFINX_MOB_FORECAST_TIMEOUT_SECONDS', 120),
];
