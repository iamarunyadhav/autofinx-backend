# V5 Exact-Exit Partitioned Execution

Date: 2026-10-16  
Status: **PARTIAL / BLOCKED**

## Instrumentation and attempted gate

The optimized `fast_exact.py` was extended with `--symbol`, `--model`,
`--scenario`, `--limit` and progress logging. The first required gate was
launched:

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/fast_exact.py `
 --root D:/autofinx-v5-phase6-data `
 --pred D:/autofinx-v5-taker-signal-validation-20261016.json `
 --out D:/autofinx-v5-partition-btc-base-close-100.json `
 --symbol BTCUSDT --model baseline --scenario close --limit 100
```

It did not execute because the instrumentation patch introduced inconsistent
indentation in the prediction loop. The exact traceback is:

```text
IndentationError: unindent does not match any outer indentation level
fast_exact.py, line 23
```

This is a local source error, not a Binance/network/data blocker. No partition
artifact was produced, so no full-dataset results are claimed. Earlier
performance reports remain unchanged and are not substituted.

## Required next correction

Normalize the entire `for scenario` / `for prediction` block to consistent
four-space indentation, run `py_compile`, then execute the 100-row BTC gate
before increasing workload. Only after that gate passes should cache manifests,
resume checkpoints, partition merges and all four global cash replays be run.

**Verdict: PARTIAL / BLOCKED.** The requested completion gates were not met.
