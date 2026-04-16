# AutoFinX Phase 0 Status Report (Baseline)

## Quick Repo Snapshot
- Backend: `backend/` Laravel 11 API with Sanctum + Spatie roles, Fortify hooks, assorted domain controllers (auth, workspaces, strategies, schedules, trading, billing, notifications, assistant, ops). Demo helpers for market ticks and schedule simulation.
- Data: Migrations cover users/roles/tokens, wide domain table (strategies, schedules, orders, trades, positions, ledgers, payments, notifications, assistant_sessions, etc.), plus extra wallet_accounts/portfolio_snapshots/market_ticks and schedule_states.
- Frontend: `frontend/sentinel-ai-trader/` Vite React app (not Next.js). Uses shadcn/ui style components, TanStack Query, some Supabase helpers, calls REST API at `VITE_API_BASE_URL`.
- Services: `laravel-services/` present with service stubs; not yet wired into HTTP.
- Docs: This report and an implementation checklist live in `/docs`.

## Planned Vision vs Current Code Status
| Module | Planned Vision | Current Status |
| --- | --- | --- |
| Auth + 2FA | Sanctum + Fortify TOTP, register/login/logout, sessions | 🟡 Partial: Sanctum auth + register/login/logout exist; TOTP stubbed (env TEST_OTP). |
| RBAC | Owner/Admin/Trader/Viewer/BotService enforced on routes | 🟡 Partial: Spatie roles installed; roles assigned on register; granular permissions not enforced on routes. |
| Workspaces | Multi-tenant workspaces + members | 🟡 Partial: workspaces + members tables/controllers exist; membership checks basic. |
| Wallet & Ledger | wallet_accounts, ledgers, ledger_transactions idempotent | 🟡 Partial: ledgers/ledger_transactions exist; wallet_accounts added; idempotency partially present; flows thin. |
| Portfolio Snapshots | periodic snapshots for UI | 🟡 Partial: table exists; no jobs/controllers. |
| Market Data | Demo + live adapters, ticks/candles, WS broadcast | 🟡 Partial: market controller returns seeded data; demo generator command exists; no live adapters/WS. |
| Strategies Catalog | Strategy definitions + params | 🟡 Partial: strategies and instances CRUD; no strategy engine logic. |
| Scheduler | Deterministic engine, cooldowns, simulator API | 🟡 Partial: schedules table + controller + simple simulate command; no persisted counters or timezone handling. |
| Risk Engine | Rule set (position %, daily loss, vol, spread) | ❌ Missing: no risk evaluation pipeline. |
| Execution | Paper/live adapters, idempotent orders/trades, fees | 🟡 Partial: trading controller creates orders/trades/positions; basic ledger adjustments; no adapters or partial fills. |
| Arbitrage | Cross/triangular engine + tables | ❌ Missing: no tables or logic. |
| DeFi / Yield | Protocols/positions/txn tables + accrual | ❌ Missing. |
| Billing/Payments | PayHere/PayPal/Stripe initiation + webhooks + reconciliation | 🟡 Partial: billing controller has stub endpoints; webhook handling minimal; no gateway integrations. |
| Notifications | DB notifications + settings + WS | 🟡 Partial: notifications/settings tables + controller; WS not present. |
| Config Versioning | config_versions + rollback endpoint | 🟡 Partial: table exists; rollback logic absent. |
| Audit Logs | Event coverage across critical actions | 🟡 Partial: audit_logs table + some writes (auth/assistant/trading). |
| AI Assistant | Chat + function-call with RBAC/audit | 🟡 Partial: assistant endpoints with demo replies; workspace FK fix added; no tool execution. |
| Realtime | WebSockets (Reverb/laravel-websockets) for prices/orders/etc. | ❌ Missing. |
| Frontend | Next.js App Router + shadcn + WS + pages per domain | ❌ Gap: current app is Vite React; many flows stubbed/demo only. |
| Testing | Pest (backend), Vitest/Playwright (frontend) | ❌ Missing: no test suites. |

## Notable Gaps / Issues
- No WebSocket layer; all data is polled.
- Risk engine, arbitrage, DeFi yield, live market/execution adapters, payment gateways are absent.
- Notification settings recently moved to Laravel but remain minimal (preferences blob only).
- Assistant uses demo responses only; no tool/action layer.
- Frontend stack diverges from target (Next.js requested; current Vite React). Supabase helpers still used for auth token retrieval.
- Idempotency/uniqueness enforced unevenly (ledger_transactions/orders/payments need checks).
- Seed data limited; demo workspace/users/strategies partially covered.

## Recent Additions (delta)
- Arbitrage scanner + execute endpoint and UI panel; cross-exchange compare live.
- Staking/DeFi simulator with `staking_positions` table, simulate/start endpoints, and wallet panel.
- Billing checkout intents + webhook normalization + invoice PDFs; subscription activation on webhook.
- Vaulted exchange secrets with rotation + adapter decryption; masked secrets in UI.
- WS/SSE price stream fallback integrated in frontend hook.
- New tests: risk guard, order poller, payout job, webhook billing, connection vault, arbitrage controller, staking controller.

## Immediate Risks to Running
- Workspace/RBAC enforcement is loose; some routes may expose cross-workspace data.
- Payment/webhook flows are stubbed; wallet credits may be inconsistent.
- No automated tests; regressions likely as new features land.
- Realtime UX absent; polling may hide race conditions in trading flows.

## Recommended Focus for Phase 1
1) Lock core schema to match vision: wallet_accounts/ledger_transactions idempotent, trading/risk/schedule tables, payments/webhooks, notifications/audit/config versions. Validate existing migrations against the target list.
2) Seed robust demo data: admin/trader/viewer users, workspace, symbols BTC/ETH/USDT/AAPL, strategies, schedule, risk profile.
3) Add model relationships/factories to unblock tests and seeds.
4) Map frontend API contract to backend (avoid Supabase-specific calls) and plan Next.js alignment.

## Evidence Collected (sampling)
- Routes: `backend/routes/api.php` includes auth, workspaces, strategies, schedules, connections, trading, billing, notifications, assistant, ops.
- Migrations: `2025_12_31_114501_create_domain_tables.php`, `2026_01_02_000001_add_wallet_and_market_tables.php`, `2026_01_02_000002_create_schedule_states_table.php`.
- Commands: `market:demo-start`, `schedule:simulate`.
- Frontend: `frontend/sentinel-ai-trader` Vite app using `VITE_API_BASE_URL`.
