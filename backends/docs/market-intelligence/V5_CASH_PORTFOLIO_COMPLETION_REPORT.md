# V5 Cash-Constrained Portfolio Replay

Date: 2026-10-16  
Status: **PARTIAL — NO-GO**

## Reconciliation

The preserved first-touch input contains 13,070 candidate rows per model. The
prior one-position diagnostic reproduced 555 opened positions and 12,515
overlap skips. Keys are ordered by timestamp and symbol; no duplicate decision
keys were introduced. The original neutral/pessimistic/optimistic one-unit
artifacts remain unchanged.

## Shared-wallet run

Research-only command:

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/cash_portfolio.py --input D:/autofinx-v5-first-touch-15m-20261016.json --out D:/autofinx-v5-cash-portfolio-20261016.json
```

Assumptions are fixed: 1,000 USDT initial cash, 100 USDT (10% of initial)
allocation, maximum five open positions, one position per symbol, no borrowing,
and the net returns already present in the frozen 15-minute artifact. Capital is
reserved at entry and released at the replayed 24-hour settlement boundary.

| Model | Initial | Final cash/equity | Return | Opened |
|---|---:|---:|---:|---:|
| Baseline | 1,000 | 969.69 | −3.03% | 555 |
| Taker combined | 1,000 | 985.92 | −1.41% | 555 |

The output artifact includes row-level state decisions and an equity series:
`D:/autofinx-v5-cash-portfolio-20261016.json`. No negative cash was permitted.

## Important incompleteness

The preserved first-touch artifact contains outcome classes but not exact TP/SL
exit timestamps. Consequently this run settles positions at the 24-hour
boundary rather than at the actual earlier barrier timestamp. It is a
cash-constrained accounting diagnostic, not a completed first-touch cash
portfolio replay. The required close-entry versus next-15-minute-open scenario
comparison and exact exit-time cash release are **not implemented** here and
must not be inferred from these figures.

## Failure attribution

Negative cash-equivalent results persist after fixed fees/slippage. The loss is
not caused solely by same-bar ambiguity; only about 1% of rows are ambiguous in
the corrected replay. Overlap selection removes most candidate events, while
the remaining net returns are still negative. Since exit timestamps and fill
quotes are absent, no causal claim is made about early exits versus costs or
entry timing. Funding and order-book liquidity are unavailable and were not
invented.

## Tests and decision

Existing deterministic research tests pass (`4 passed`) for first-touch order,
collision, timeout, causal alignment and feature mapping. The cash runner
enforces fixed allocation, maximum five positions, one-symbol exclusivity and
non-negative cash. Additional exact-exit and simultaneous-settlement fixtures
remain outstanding because the source artifact lacks exit timestamps.

**NO-GO / NO-EDGE.** Even the completed portion is negative after costs. Do not
enable live execution or tune thresholds on the protected data. A future phase
must regenerate first-touch rows with exact exit timestamps before claiming a
complete cash portfolio result.
