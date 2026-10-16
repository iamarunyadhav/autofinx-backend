# V5 Portfolio Accounting Final Audit

Date: 2026-10-16  
Verdict: **PARTIAL / NO-GO**

## Prediction-universe audit

The frozen prediction artifact contains five intentionally separate feature
specifications, each with 13,070 rows. The portfolio runner must use only
`baseline` and `taker_combined`; the other three models are not cash-simulated.
For each of those two models, `(model,symbol,timestamp,horizon,fold)` keys are
unique (0 duplicates). The 65,350 rows in each scenario JSON are the five-model
research rows; cash results contain only the two requested models. No duplicate
trade event was found in the frozen keys.

## Scenario reconciliation

| Scenario/model | Candidate rows | TP | SL | Ambiguous | Final capital |
|---|---:|---:|---:|---:|---:|
| Close / baseline | 13,070 | 7,277 | 5,663 | 130 | 480.76 |
| Close / taker | 13,070 | 7,293 | 5,647 | 130 | 517.06 |
| Next-open / baseline | 13,070 | 5,237 | 7,716 | 117 | 99.34 |
| Next-open / taker | 13,070 | 5,494 | 7,462 | 114 | 99.71 |

The large next-open difference is explained numerically by the barrier outcome
shift: baseline TP_FIRST falls from 7,277 to 5,237 while SL_FIRST rises from
5,663 to 7,716. The entry proxy is the next 15-minute open with adverse 2 bp
slippage; it is not a guaranteed exchange fill.

## Accounting audit

The runner reserves exactly 100 USDT on an OPEN event, releases principal plus
`100 * net_return` at the persisted exit timestamp, and never allows cash below
100 before opening. Ambiguous rows are skipped without P&L. Final capital adds
all still-open marked proceeds. Net return already includes the fixed 0.12%
cost once; no second fee deduction was found in the cash loop. The losses are
mathematically consistent with thousands of recycled fixed-stake trades whose
per-trade net returns are negative: TP rows average about +0.32%, SL rows about
−0.56%, before the repeated portfolio sequence is compounded.

## Before/after and limitations

These figures are reconciled against `D:/v5-close.json` and
`D:/v5-next.json`, with row-level exit timestamps and ledgers. However, the
current artifact does not include a separate explicit cash-transition record
for every settlement, nor a formal invariant test suite for fee-free flat
trades, drawdown, resume equivalence and simultaneous event ordering.

Existing focused tests remain `4 passed`; the mandatory accounting-test list in
the request was not fully implemented. Therefore the numbers are evidence for
the saved simulator output, not a production-grade financial ledger.

**PARTIAL / NO-GO.** No prediction or threshold changes were made. Do not
interpret the scenario losses as live execution results until the missing
cash-invariant and deterministic rerun tests are added and passed.
