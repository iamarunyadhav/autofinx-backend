# V5 Exact-Exit Performance and Results

Date: 2026-10-16  
Status: **PARTIAL — FULL DATASET STILL BLOCKED**

## Profiling and optimization

The original implementation repeatedly loaded/filter-scanned pandas dataframes
inside symbol/prediction loops. A bounded cProfile launch did not complete
within 30 seconds, confirming the bottleneck is the repeated archive/DataFrame
path plus per-row scans rather than JSON output or the cash loop.

`audit/taker_signal_validation/fast_exact.py` was added as a research-only
optimized path. It loads each symbol's 15-minute archives once, coerces and
deduplicates timestamps, builds NumPy arrays for open/high/low/close, uses
binary timestamp alignment, scans at most 96 candles, and performs the global
cash merge chronologically. It supports decision-close and next-open scenarios,
exact exit timestamps, ambiguity preservation, one-symbol exclusivity, five
positions, fixed 100 USDT stake and 1,000 USDT cash.

## Commands

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe -m py_compile audit/taker_signal_validation/exact_exit.py audit/taker_signal_validation/fast_exact.py
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/fast_exact.py --root D:/autofinx-v5-phase6-data --pred D:/autofinx-v5-taker-signal-validation-20261016.json --out D:/autofinx-v5-exact-performance-20261016.json
```

Syntax compilation passes. The optimized five-symbol/two-scenario launch still
exceeded the bounded execution window before writing the JSON artifact. The
first runtime defect encountered (header row in a raw archive) was corrected by
numeric coercion and fail-closed row removal. No full-dataset numerical result
is claimed.

## Completion gates

* Cache/NumPy optimization: implemented.
* Exact barrier scan and chronological cash code: implemented.
* Four completed scenario/model runs: **not verified**.
* Ledgers/equity curves from this run: **not produced**.
* Mandatory regression tests: existing suite remains 4 passed; new performance
  tests were not completed.

Existing prior artifacts remain preserved and are not substituted for this
requested exact-exit result. The remaining safe optimization is to persist a
per-symbol cache between processes and run each `--symbol` partition separately,
then merge only the row-level exit records before one global cash pass.

**Verdict: PARTIAL / NO-GO.** No fabricated full-dataset performance is reported.
