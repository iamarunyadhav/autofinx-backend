# V5 TP/SL First-Touch Simulator Validation

Date: 2026-10-16  
Verdict: **CORRECTED_SIMULATOR_VALIDATED; TRADING_EDGE_UNPROVEN**

## Audit result

The original `audit/taker_signal_validation/opportunity.py` did not stop at the
first barrier. It scanned the entire 24-hour window, computed `mfe` and `mae`,
and then executed:

```python
outcome = 'AMBIGUOUS' if hit_tp and hit_sl else 'TP' if hit_tp else 'SL' ...
```

Thus any window that touched both barriers at any time was labelled ambiguous;
it did not mean that both barriers were touched in the same candle. This is a
verified simulator logic error. The original artifact remains preserved at
`D:/autofinx-v5-opportunity-targets-20261016.json`.

## Corrected evaluator

`audit/taker_signal_validation/first_touch.py` calculates entry and fixed
ATR-based barriers before reading future bars, iterates chronologically, stops
on the first decisive touch, and marks `AMBIGUOUS` only when both barriers are
touched in one unresolved candle. LONG and SHORT barriers are separate. The
entry convention is the 1-hour candle close; the replay starts at the first
strictly later 15-minute candle. TP/SL is `max(0.12%, 0.5*ATR24)`. Costs are
0.12% on resolved/time-out trades; no cost is charged to ambiguity or no-trade.
No funding was available in the preserved dataset.

## Reproduction commands

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/first_touch.py `
 --root D:/autofinx-v5-phase6-data `
 --pred D:/autofinx-v5-taker-signal-validation-20261016.json `
 --out D:/autofinx-v5-first-touch-15m-20261016.json
D:/autofinx-mob-v4/venv/Scripts/python.exe -m pytest audit/taker_signal_validation/tests -q
```

## Results: verified 15-minute path replay

The same 13,070 frozen predictions per model were replayed. All rows had a
complete 24-hour 15-minute path.

| Model | N | TP_FIRST | SL_FIRST | AMBIGUOUS | TIMEOUT | NO_TRADE | Mean net return |
|---|---:|---:|---:|---:|---:|---:|---:|
| Baseline | 13,070 | 55.68% | 43.33% | 1.00% | 0.00% | 0.00% | −0.064% |
| Taker combined | 13,070 | 55.80% | 43.21% | 1.00% | 0.00% | 0.00% | −0.059% |

The apparent win rate among unambiguous resolved trades is approximately the
TP_FIRST share divided by TP_FIRST+SL_FIRST (about 56.2% for baseline and
56.4% for taker-combined). The small positive difference is not a statistical
or cost-adjusted profitability claim; overlapping rows and fixed costs remain.

## Comparison with original result

The prior 1-hour-window implementation reported 73.79% ambiguity for both
models. That percentage was caused by the full-window `hit_tp and hit_sl`
branch, not by same-candle collisions. The corrected 15-minute replay reports
1.00% genuine same-bar ambiguity. This is a simulator correction, not evidence
that the strategy is profitable.

## Automated tests

Four tests pass. Synthetic fixtures cover earlier SL, later TP, earlier TP,
same-candle collision, timeout, SHORT barriers, and the existing field/causal
alignment tests. Command result: `4 passed`.

## Limitations and decision

The replay uses candle OHLC and cannot determine the order of TP and SL inside a
15-minute candle; those are conservatively ambiguous. It does not model actual
fills, spread variability, funding, leverage, liquidation, latency beyond the
explicit candle boundary, or portfolio overlap. The result is therefore a
validated research simulator and not a live execution guarantee.

**Verdict: CORRECTED_SIMULATOR_VALIDATED; TRADING_EDGE_UNPROVEN.** Keep this in
research/shadow mode. Do not enable orders or alter production execution.
