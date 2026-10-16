# Implement/test/release gates for autonomous coding agent

## Gate matrix

| Phase | Work product | Local acceptance gate | Auto proceed? |
|---|---|---|---|
| P0 Audit | Line-anchored source map + baseline + repository compatibility | Every claimed integration points to verified code; no protected changes | Yes |
| P1 Contracts | Typed PHP+Python+TS schemas, mock adapters, research config all disabled | JSON schema+parity tests, zero execution refs | Yes |
| P2 Scanner | Current/history universe, quality filters, volume/volatility, candidate rank | Offline fixtures include delisted, missing, spread, rate limit, stale, cross asset | Yes |
| P3 Models | Naive, Chronos-2, TTM-R3 adapters, batch/timeout/trace | Mock/pass with models absent; actual model smoke optional on approved host | Yes for offline, pause external network install |
| P4 Decisions | Pure policy+immutable dataset+delayed labels | WAIT reasons, label maturity, causal timestamp checks; no order structures | Yes |
| P5 Validation | Dataset splits, preregistered A/B/C/D experiments, cost stress | No future leakage, duplicate P&L, sealed holdout reads, score overstatements | Yes on synthetic/permitted data; stop for sealed/production data |
| P6 UI/API | Protected read APIs and React research dashboard | Workspace cross-tenant auth tests; TS/build/lint; no trade actions | Yes |
| P7 Hardening | Docs, health, benchmarks, rollback and test evidence | New tests + existing protected tests; no secrets or flag flip | Yes |
| P8 Production deploy | **OUT OF SCOPE** | Requires fresh user authorization and separate review | **NO** |
| P9 Connect to Trading Engine | **OUT OF SCOPE** | Requires independent research proof and separate user approval | **NO** |

## Baseline vs experiment arms

A. Fixed currently-operating coin set + existing validated Strategy Lab/TradingView strategy, shadow replay only.

B. Dynamic discovery rank/eligibility + same strategy, same cost/risk horizon.

C. Dynamic discovery + AI model forecast, pure research decision only.

D. Dynamic discovery + same strategy + calibrated AI agreement/disagreement, pure research decision.

Carry forward failures honestly. The experiment runner must have immutable plan hash, input dataset hash, strategy/feature version, symbol-universe as-of, model revision, clock convention, cost profile, random seeds, number of trials, and explicit withheld/insufficient-data metrics. **Never label a replay as validated live edge.**

## Invariants to implement as failing tests before risky wiring

- `test_opportunity_brain_cannot_submit_order_or_modify_execution_config` scans service bindings/routes/jobs, plus package forbidden-dependency checks.
- `test_all_new_feature_flags_default_disabled`.
- `test_unlisted_or_delisted_historical_symbol_excluded_at_asof`.
- `test_strategy_only_signals_unchanged_when_brain_disabled`.
- `test_stale_or_unavailable_prediction_yields_wait`.
- `test_no_future_feature_known_at_after_decision`.
- `test_existing_ml_holdout_unread`.
- `test_fee_funding_slippage_can_reject_gross_positive`.
- `test_strategy_lab_registry_hashes_unchanged`.
- `test_workspace_cross_tenant_id_lookup_denied`.
- `test_replayed_job_is_idempotent`.
- `test_forecast_quantiles_ordered_and_probabilities_well_formed`.
- `test_live_pnl_never_inferred_from_hypothetical_backtest`.
- `test_existing_webhook_live_flags_and_prime_mirror_guard_unchanged`.

## Evidence discipline

Every test executed must list command, tool versions, test count, result, time, relevant files. Never write "all pass" without evidence. If dependency/tool absent report `BLOCKED_NOT_RUN`, not pass. Copy/paste no `.env` / URLs with tokens / production endpoint secrets.
