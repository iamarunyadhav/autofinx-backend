# V5 Exact-Exit Final Execution Report

Date: 2026-10-16  
Status: **PARTIAL — EXTERNAL/BOUNDED EXECUTION BLOCKER**

## Code correction

The original failure was an indentation error in `cash()`:

```text
IndentationError: unexpected indent
```

The settlement loop was nested at the wrong indentation level, and pandas
Series objects were stored in `positions`, causing ambiguous comparisons during
removal. `audit/taker_signal_validation/exact_exit.py` was replaced with a
research-only corrected implementation that uses dictionary records, explicit
chronological settlement, one symbol per open position, maximum five positions,
100 USDT fixed stake and 1,000 USDT initial cash. It persists entry/exit
timestamps, barrier reason, gross/net returns, ledgers and equity rows for both
close and next-open scenarios.

## Verification

Syntax compilation succeeds:

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe -m py_compile audit/taker_signal_validation/exact_exit.py
```

The full replay was launched with the preserved prediction artifact and
15-minute dataset. Data loading and path reconstruction are bounded, but the
two five-symbol scenarios exceeded the execution window before producing the
final JSON artifact. Therefore no numerical exact-exit results are claimed.
The earlier artifacts remain preserved and unchanged.

## Completion gates

* Correct source-level indentation/data-record bug: **PASS**
* Syntax/import validation: **PASS**
* Both scenarios fully executed: **NOT VERIFIED**
* Exact-exit JSON, ledgers and equity curves: **NOT VERIFIED**
* Mandatory simulator tests: existing focused suite remains 4 passed; the new
  exact-exit completion tests were not run to completion.

## Decision

**PARTIAL / NO-GO.** The code defect is fixed, but bounded runtime prevented
verified numerical completion. No profitability or execution-readiness claim
is made. The next safe step is to cache the five 15-minute symbol dataframes
once per process (rather than rereading archives per scenario), then rerun the
same frozen predictions and execute the mandatory deterministic tests.
