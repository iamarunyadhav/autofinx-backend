# V5 Trade Eligibility and Net P&L Validation

Date: 2026-10-16  
Verdict: **NO-GO for trading; research accounting partially validated**

## Eligibility audit

`first_touch.py` maps prediction class `2` to LONG, class `0` to SHORT, and
class `1` to no trade. The preserved prediction artifact contains no invalid
class values. The 13,070 rows per model are all directional in this frozen
artifact, so the observed NO_TRADE count is zero because the model emitted no
SIDEWAYS rows—not because SIDEWAYS was converted into a trade. The evaluator
preserves a no-trade branch and charges no P&L to it.

## Alignment and costs

Each position is keyed by model, symbol and prediction timestamp. Entry is the
decision 1-hour close; the replay reads only strictly later 15-minute candles.
The frozen assumptions are TP/SL `max(0.12%, 0.5*ATR24)`, entry fee 0.04%, exit
fee 0.04%, and slippage 0.04% (0.12% total). LONG return is price return and
SHORT return is its negative. Same-15-minute TP/SL collisions are not winners.
Funding is unavailable and therefore zero/not imputed.

## Results from the preserved corrected 15-minute artifact

Artifact: `D:/autofinx-v5-first-touch-15m-20261016.json`.

| Model | Predictions | Resolved trades | Trade coverage | Mean net / prediction | Mean net / executed trade |
|---|---:|---:|---:|---:|---:|
| Baseline | 13,070 | 12,940 | 99.01% | −0.0638% | −0.0645% |
| Taker combined | 13,070 | 12,940 | 99.01% | −0.0586% | −0.0592% |

The remaining 1.00% are same-bar ambiguous observations and are not counted as
wins. TP-first rates are approximately 55.68% and 55.80%, respectively; those
rates are not net profitability. The artifact contains overlapping 24-hour
predictions, so these rows are an independent-prediction diagnostic, not a
portfolio equity curve.

## Overlap and timing limitations

The corrected replay validates the close-entry convention and causal 15-minute
alignment. A separate next-bar-fill run was not accepted as evidence because
the current artifact does not persist bid/ask quotes or actual fills; inventing
those prices would overstate precision. A one-position-per-symbol portfolio
curve is therefore not claimed. Building that curve requires a pre-registered
capital and conflict policy, and must be run separately from these overlapping
diagnostics.

## Tests and files

`python -m pytest audit/taker_signal_validation/tests -q` passed **4 tests**,
covering LONG/SHORT barrier order, same-candle ambiguity, timeout, field
mapping, causal ordering and forward-label alignment. Research-only source:
`audit/taker_signal_validation/eligibility_pnl.py` (an accounting scaffold; its
output is not used as a claimed result), plus this report. No production path,
migration, scheduler or execution permission changed.

## Decision

**NO-GO.** The eligibility mapping and corrected first-touch accounting do not
show profitable net P&L. TP-first percentages must not be used as a trading
promotion criterion. Keep the model in shadow research until next-bar fills,
non-overlapping portfolio rules, funding and defensible spread/latency data are
pre-registered and independently validated.
