# AutoFinX V5 Prediction Target & Trade Opportunity Research

Date: 2026-10-16  
Verdict: **INCONCLUSIVE**

## Audit findings

The implemented target is a three-class label on completed 1-hour candles:
`fwd = close[t+24]/close[t]-1`; UP when `fwd > max(0.0012, 0.5*ATR24)`, DOWN
when below its negative, otherwise SIDEWAYS. Features are computed through
decision candle `t`, and outcomes mature only after 24 subsequent candles.
The research adapter uses candle close as entry. This is a research convention,
not proof that an exchange order filled at that close; latency, spread and
queue position are not present in the historical archive. The target therefore
describes future close movement, not necessarily an executable opportunity.
Taker imbalance is correctly `2*taker_buy_ratio - 1`.

## Reproducible commands

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/validate.py --root D:/autofinx-v5-phase6-data --out D:/autofinx-v5-taker-signal-validation-20261016.json
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/opportunity.py --root D:/autofinx-v5-phase6-data --pred D:/autofinx-v5-taker-signal-validation-20261016.json --out D:/autofinx-v5-opportunity-targets-20261016.json
D:/autofinx-mob-v4/venv/Scripts/python.exe -m pytest audit/taker_signal_validation/tests -q
```

## Target comparison on identical rows

The frozen Logistic baseline and taker-combined candidate were evaluated on the
same five symbols and three chronological 24-hour folds. The direction result
was baseline 32.53% versus combined 34.05% balanced accuracy (13,070 rows per
model), with a conservative paired uncertainty interval that includes zero.

For executable-style outcomes, TP and SL were each fixed at `max(0.0012,
0.5*ATR24)`. The next 24 completed candles were inspected. Round-trip cost was
fixed at 0.12% (fees plus slippage). If both high and low touched TP and SL in
the same horizon, the result was marked `AMBIGUOUS`; it was not assigned an
intrabar order.

| Predicted model | Rows | TP first | SL first | Ambiguous | Mean net return |
|---|---:|---:|---:|---:|---:|
| Baseline | 13,070 | 13.18% | 13.03% | 73.79% | -0.242% |
| Taker combined | 13,070 | 13.99% | 12.23% | 73.79% | -0.180% |

Rows classified SIDEWAYS were not traded. Timeout returns were marked to the
last close and also charged the fixed cost. These are opportunity diagnostics,
not a portfolio backtest: overlapping positions, capital allocation, funding,
liquidation, and exchange execution are not inferred from OHLCV.

## Alternative targets and limitations

The artifact records forward return, MFE and MAE, and executable TP/SL outcome.
An after-cost target can be defined as forward return minus the fixed 0.12%
cost, but its sign is still sensitive to entry fill and does not solve the
intrabar ambiguity. MFE/MAE are descriptive excursion targets; they are not
tradable labels unless an entry, exit and path rule are specified. A candle
archive cannot determine whether TP or SL occurred first inside a candle.

The very high ambiguous rate (73.79%) means the current hourly OHLCV data is
insufficient for a defensible TP-before-SL label at this distance. Lowering
thresholds or choosing a favorable tie-break would be test-set tuning and was
not done. No funding or historical order-book data was available in this
experiment.

## Tests and files

The focused tests passed (`2 passed`) for Binance field mapping, completed
candle ordering, causal feature ranges and forward-label alignment. New
research-only code/artifacts:

* `audit/taker_signal_validation/opportunity.py`
* `D:/autofinx-v5-opportunity-targets-20261016.json`
* this report

## Decision

**INCONCLUSIVE.** The existing close-direction label is well-defined but is not
equivalent to a tradable 24-hour LONG/SHORT opportunity. The taker candidate
shows a small diagnostic improvement and less negative mean net return, but the
paired direction evidence is not statistically decisive and executable labels
are dominated by unresolved intrabar ambiguity. Keep research/shadow only; do
not promote targets or enable trading without higher-resolution path data and a
pre-registered, cost-aware validation.
