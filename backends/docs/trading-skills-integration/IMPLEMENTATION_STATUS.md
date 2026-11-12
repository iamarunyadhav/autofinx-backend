# Trading Skills Intelligence — implementation status

## Verified existing capabilities

- `App\Domain\Ml\Shadow\MlPaperAutopilotService` is the existing isolated paper
  executor. It writes only the ML paper-trade table and never calls an order
  endpoint, wallet, or live position service.
- `OperationalBreakerService`, the existing risk services, and `AuditResearch`
  remain the authoritative safety and audit layers.
- Binance aggregate-trade pressure is already collected by
  `RealtimeMarketFeedService` and exposed through the read-only market-pressure
  endpoint. No second pressure calculator is introduced here.
- Existing backtest and forecast evaluation services provide chronological,
  fee-aware research; new reports must reuse those services rather than create
  a parallel journal or database.

## Third-party concept mapping

| Claude skill | AutoFinX treatment |
| --- | --- |
| `drawdown-circuit-breaker` | Compare rules with `OperationalBreakerService`; do not import its YAML state model. |
| `pre-trade-discipline-gate` | Adapt as a paper/research decision report; live execution remains unaffected. |
| `crypto-regime-analyzer` | Research/shadow input only; stale or unavailable inputs produce `INSUFFICIENT_DATA`. |
| `backtest-expert` | Methodology reference for chronological holdouts, costs, slippage, and stress tests. |
| `trader-memory-core` | Reuse `AuditResearch` persistence; no second thesis store. |
| `signal-postmortem` | Add evidence-backed classifications to existing audit reports; unknown evidence stays `UNKNOWN`. |

## Safety invariants

1. Research output is advisory and cannot reach a live order client.
2. Paper Autopilot uses its simulation-only executor and separate virtual ledger.
3. New flags default off; no production `.env` is changed by this work.
4. No migration is applied without explicit approval.
5. No performance result is reported until a reproducible historical dataset is
   available for both baseline and enhanced configurations.

## Remaining implementation gates

- Confirm the exact historical dataset and period for a baseline/enhanced
  comparison.
- Add only missing discipline/risk observations after comparing the current
  paper flow with the third-party rules.
- Add focused tests for every new observation and for paper/live isolation.
- Run the full backend suite and frontend build/lint before claiming completion.

