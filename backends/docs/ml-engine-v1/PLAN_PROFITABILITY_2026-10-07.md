# Owner plan "Profitability Intelligence & Dynamic Trade Management" (2026-10-07) — agent review

Status: **ACCEPTED as direction, PROPOSED in detail.** Each stage needs a pre-registered decision record
before code runs. Existing D-030 (optional layers) and D-031 (Strategy Lab) are taken, so the new IDs are:
D-032 P6 acceptance + P7 classification · D-033 TAKE/SKIP meta-label · D-034 continuation/exhaustion
trailing · D-035 execution-cost scenarios (maker entry).

## Mapping: owner plan vs what already exists in code
| Plan item | Already exists | Gap |
|---|---|---|
| Stage A: finish P4B | running, 27/30 (`run-p4b-p5.sh`) | — |
| Stage B: P4B audit (overlap, importance stability, per-horizon) | per-cell fold gains in reports/p4b | correlation + importance-stability table (new, cheap) |
| §5 G7-T features | 11 built (`optional_layers.py`): efficiency 12/48, chop 14/48, flips 24, overlap, failed breakout, EMA compression, body/wick means, path ratio | missing: flip_rate_48, normalized_range_position, volume_confirmation, recent_direction_persistence. atr / BB percentile and MTF disagreement deliberately reused from G4/G5 (no duplicates) |
| §6 T1 features / T2 soft score / T3 gate | FEATURE + WAIT (top 50% / 30% keep) roles, OFF vs ON, false-WAIT metrics | the T2 score is not yet fed to P5 EV as evidence; T3 hard gate is only tested after T2 helps |
| §7–8 TAKE/SKIP meta-label | not built | new: trained on P5 candidate trades, with the label = the same candidate policy's outcome (D-029 semantics) |
| §9–14 continuation / exhaustion trailing | trailing already never loosens; activation + running peak in `policy.simulate` | new: a causal per-minute continuation score → bounded trail multiplier (3 states or probability) |
| §17–19 cost scenarios C0 / C1 / C2 | C0 = 0.12% + funding everywhere | new: maker-entry model WITH fill probability and adverse selection (see risk 3) |
| §23 WAIT reasons | P5 has REWARD_FLOOR / SL_ABOVE_CAP / ACTIVATION_FLOOR / INCONSISTENT / UNCERTAIN / OOD + EV gate counts | add META_SKIP, LOW_TRADEABILITY, LOW_EV, CONFLICT explicitly |
| §29–32 P6 / P7 / P8 | P5 now saves `decision.json`; P6–P8 code not written | D-032 first, then the code |
| §41 profit capture / giveback | MFE known per path | add peak_profit_seen, realized, giveback to the trade stats |

## Agent corrections / risks
1. **Data budget.** dev_train folds feed P4B, the optional layers, TAKE/SKIP, continuation and cost. Every
   added arm is another look at the same data. Keep a trial ledger, add each stage only with a
   pre-registered rule, and leave dev_val untouched until the final P5 selection.
2. **TAKE/SKIP cannot create information that does not exist.** The meta model selects among the trades
   the direction model proposes. With AUC 0.55–0.65 and gross edge ≈ 0 at 5–15m, expect it to cut losses
   AND winners. False-WAIT and profit retention decide. It is still the right experiment, because it
   targets the "right direction, too-small move" failure directly.
3. **Maker entry is the largest single lever, and the easiest to fake.** A limit order fills more often
   when the price moves through it, i.e. against you (adverse selection). An honest C1 model must:
   - fill only if the price trades THROUGH the limit within N seconds/minutes (1m path; aggTrades for a
     sample);
   - count missed fills as missed opportunities (not free WAITs);
   - keep taker exits for SL / trailing.
   - Binance futures VIP0 is ~0.02% maker / 0.05% taker, so a C1 round trip is ~0.07% + slippage,
     against 0.12% today. Report C0 / C1 / C2 side by side, with the same predictions.
4. **Continuation model leakage.** It decides minute by minute inside a trade, so its features must be
   causal per minute (running peak, retracement, 1m/5m momentum known at that minute). It needs its own
   leakage tests (owner §42, §49).
5. **Order.** Owner order kept: P4B → audit → G7-T → TAKE/SKIP → economics → continuation → trailing →
   cost study → final P5 → freeze → P6 → P7 → P8. The P5 full run now queued is the **T0 baseline**: its
   result is a comparison point, not the final freeze.
