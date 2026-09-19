# Nexus installation and operations

## Implemented

`/nexus` is part of the existing frontend. It provides cycle status, observations, sources, candidates, review/approval, queued forecast experiments, knowledge/report exports, cost and safety views. Backend routes are under `/api/v1/workspaces/{workspace}/nexus`.

The existing Laravel scheduler requests a cycle every 15 minutes. Per-source caches, deterministic observations, a once-daily model reasoning gate and shared cost reservations bound the workload. Daily reports refresh with observations; Monday cycles also write the weekly innovation report. A single cycle per workspace/15-minute slot includes report generation; command aliases do not create duplicate independent pipelines.

## Installation

The coding session did not apply the new migration to your application database, modify private `.env`, or access the production server. Review the migration `2026_09_28_000000_create_nexus_tables.php`; apply through your normal authorized deployment process:

```sh
php artisan migrate --path=database/migrations/2026_09_28_000000_create_nexus_tables.php
```

Configure Redis and a shared Redis cache for distributed locks. Set `AUTOFINX_NEXUS_ENABLED=true`, `AUTOFINX_NEXUS_AUTONOMY_LEVEL=2`, `AUTOFINX_NEXUS_QUEUE_CONNECTION=nexus`. Use the dedicated `nexus` connection with retry_after=180. A custom durable queue must have retry_after greater than the job timeout of 110 seconds.

Install `deploy/nexus-supervisor.conf` after replacing application directory, PHP path, user and log locations. Two separate workers consume agent_research and agent_training. Do not add these queues to workers that execute exits/risk jobs. Supervisor's `priority` controls startup order, not CPU priority. Apply host resource limits or separate hosts if profiling shows competition; no code can promise zero shared-database/CPU overhead.

Keep exactly one owner of the existing Laravel schedule:run cron. Do not install another Nexus-specific cron. Refresh config cache and restart the new workers using your deployment process.

```sh
php artisan nexus:doctor
php artisan nexus:run-cycle WORKSPACE_UUID
php artisan nexus:status
```

`nexus:doctor` reports schema/config readiness, not a verified remote Supervisor connection. Check a completed/partial run in the dashboard to verify worker operation. A requested cycle may reuse the current 15-minute slot.

## Optional LLM setup

The provider transport is configurable OpenAI-compatible chat completions, using HTTPS and JSON responses. Supply an endpoint, API key, supported routine/deep model names and conservative input/output prices in `.env`; no provider or model is hardcoded. Enable `AUTOFINX_NEXUS_LLM_ENABLED` only after configuration. Native providers requiring different payloads should implement NexusResearchProvider and bind it in the container.

No API key is currently configured by this implementation. Without it, deterministic observations, baseline hypotheses, reports and experiments still work. External discovery synthesis reports PROVIDER_OR_PRICING_REQUIRED instead of returning fabricated research.

Daily/monthly budgets cover the shared provider account across workspaces. Reservations are conservative bounds, not invoices; failed calls keep their reservation. Use prices that upper-bound both chosen models including provider billing rules. There is no semantic-cache embedding provider; caching uses exact content/model fingerprints.

## Data and provenance

Initial public sources are configured in config/nexus.php. Endpoints require successful runtime verification; tests mock/parse sources without relying on external services. A source failure marks the run partial and records UNAVAILABLE. Only registered public HTTPS endpoints are reachable from research source collection; source text never invokes tools.

Internal observations use a 24-hour window and at most 500 rows per module. Quick Trade accounting summaries retain source/mode/symbol/timeframe/expiry/currency dimensions. Fast2X and Ratio Bot ownership are resolved through their config tables. Missing adapters are visible. Paper results are not merged into live results.

The default local filesystem root is determined by config/filesystems.php. Exports are stored under `autofinx-nexus/{workspace}/knowledge/autofinx-nexus-knowledge-latest.json` on that disk (normally storage/app/private). No runtime Git writes occur.

Observations/reports and terminal cycle records have bounded 90-day cleanup in batches of 500. Candidates, approvals and model-call cost ledgers remain durable; archive them deliberately when needed. Orphaned cycles/experiments older than one hour are marked failed during subsequent cycles. No automatic provider retry after an ambiguous timeout.

## Commands

- `nexus:run-cycle [workspace]`: queue a deduplicated complete cycle.
- `nexus:observe`, `nexus:discover`, `nexus:capability-scan`, `nexus:research`, `nexus:daily-report`: aliases that request the same complete cycle, with the same gates.
- `nexus:weekly-innovation-report [workspace]`: request a cycle including the weekly report/deep reasoning route, subject to the same daily/cost gates.
- `nexus:export-knowledge workspace`: generate a private portable JSON bundle.
- `nexus:cost-status workspace`: report workspace reservations against global budget limits.
- `nexus:status`, `nexus:doctor`: configuration/schema diagnostics.

## Boundaries and remaining integrations

This implements the reusable research foundation. It does not implement every strategy named in the source idea. Higher autonomy levels, live self-tuning, autonomous code changes, training new ML models, strategy-specific execution simulators and fully automatic outcome attribution are not enabled.

The first registered experiment is a forecast proxy, not a test of arbitrary proposed strategy code. It does not award evidence A. New strategy evaluators need explicit point-in-time data contracts and reviewed fill/cost models. Proprietary news, calendars, broker feeds and historical depth need the corresponding credentials/data adapters. Source availability, provider billing accuracy, production resource isolation and long-run latency require deployment validation.

UI research/discovery/opportunity/recommendation views share typed candidate records. Filtering is explicitly page-local. Model Lab exposes experiment evidence; it does not pretend an ML training system exists.
