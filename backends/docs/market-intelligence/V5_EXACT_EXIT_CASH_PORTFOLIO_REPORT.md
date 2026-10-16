# V5 Exact-Exit Cash Portfolio Simulation

Date: 2026-10-16  
Status: **PARTIAL — COMPLETION GATES NOT MET**

## Work performed

The preserved predictions and verified 15-minute Binance archives were used as
inputs. A research-only `exact_exit.py` implementation was added to generate
entry price, TP/SL barriers, first-touch timestamp, exit proxy, gross/net return
and scenario-specific rows for decision-close and next-15-minute-open entry.
The original prediction artifact and prior 24-hour-settlement artifacts were not
modified.

## Blocking issue

The new cash-settlement implementation did not pass final execution validation:
its event-loop indentation/data-record handling requires correction before it
can be safely executed. Therefore no exact-exit scenario result is claimed, no
equity curve is presented as valid, and the completion gates remain open. This
is intentionally reported as PARTIAL rather than substituting the earlier
24-hour-boundary numbers.

Existing verified evidence remains:

* 13,070 frozen prediction rows per model.
* Prior corrected 15-minute first-touch artifact and one-unit replay are
  preserved at `D:/autofinx-v5-first-touch-15m-20261016.json` and
  `D:/autofinx-v5-portfolio-replay-20261016.json`.
* Earlier tests passed (`4 passed`) for barrier order, ambiguity, timeout and
  causal alignment.

## Required next correction

Fix and unit-test the exact-exit event loop, then regenerate both scenarios,
persist row-level ledgers with actual exit timestamps, settle cash at those
timestamps, and rerun deterministic cash-conservation and drawdown tests. No
model, threshold, symbol universe or production behavior should be changed.

**Verdict: PARTIAL / NO-GO.** Exact-exit completion and profitability have not
been demonstrated.
