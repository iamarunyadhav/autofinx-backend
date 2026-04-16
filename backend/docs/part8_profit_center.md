## Part 8 – Profit Center quick guide

- Migrations: run `php artisan migrate`.
- Queue: `php artisan queue:work`.
- Commands: `php artisan arbitrage:loop-start --once`, `php artisan yield:simulate-start --once`, `php artisan defi:yield-simulate-start --once`, `php artisan analytics:snapshot-run --once`.
- Integrations: add via UI (Integrations page) -> stored encrypted, health ping available.
- Module modes: switch via `/api/v1/modules/{key}` (UI controls).
- Profit Center (UI):
  - Arbitrage tab: set spread threshold, run-once (paper).
  - Yield tab: accrue simulated yield.
  - DeFi tab: simulate read-only positions.
- Safe mode: live requires 2FA and module mode=live (not enabled by default).
