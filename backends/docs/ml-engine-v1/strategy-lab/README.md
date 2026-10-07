# Strategy Lab + Tradeability Intelligence — owner proposal (2026-10-07), agent review, plan

Status: **PROPOSED, nothing built.** Owner gate: `"approve Strategy Lab build"` before SL2 onward.
SPEC §104 / §105 and D-030 / D-031 get drafted after P4B finishes and the SL0 audit is done.

## 1. Owner proposal (summary of the master prompt, 71 sections)
- Two research extensions of V4, never a redesign:
  - **Tradeability / Noise Intelligence (§104):** keep noisy history, learn "no-edge / chop" as WAIT
    evidence. Arms: T0 baseline · T1 features · T2 soft score · T3 WAIT pre-filter · T4 meta-label
    TAKE/SKIP.
  - **Universal Strategy Lab (§105):** upload Pine (first adapter) or rule/JSON/other → static audit
    (repaint / lookahead / request.security / pivots / unsupported) → async Quick Test on a
    pre-declared development window → owner approve → Deep Test (folds, cost and parameter stress,
    regimes) → component extraction + dedupe vs G1–G6 → challenger experiment → shadow/paper.
    Owner approval is required between every major stage.
- Hard rules:
  - Pine is evidence, never label truth. Noisy rows are never deleted.
  - No accuracy-threshold approval; economics, loss avoidance, profit retention, false-WAIT,
    chronological consistency and cost stress decide.
  - No parameter mining. No executing uploaded code. No live or autopilot path. P6 holdout sealed.
  - Arms are pre-declared before freeze.
- Phases:
  - SL0 repository audit → SL1 SPEC + decisions → STOP.
  - SL2 data model · SL3 upload · SL4 Pine audit · SL5 Strategy IR · SL6 Quick Test engine ·
    SL7 UI · SL8 Deep Test · SL9 components · SL10 tradeability · SL11 ML experiment ·
    SL12 shadow/paper preparation.

## 2. Agent review (validity)
Valid and kept: research-only lifecycle, owner gates, Pine = evidence, never delete noise, no
accuracy rule, false-WAIT / profit-retention metrics, no code execution, bounded search, versioning
+ sha256, holdout rule (§59), challenger never auto-promoted.

Corrections / risks to resolve before SL2:
1. **Order vs evidence.** P3–P5 show no V4 edge so far (R-013..R-017). A filter can only help if it
   separates winners among the trades the model takes. Recommend running SL10 (tradeability
   T0–T4, Python only, existing data) right after P4B, **before** building the SL2–SL7 product
   (DB, API, UI, parser). §66 of the proposal allows this; it is proposed here, not done silently.
2. **Where it runs.** Data (1m/5m, 5 years) and the simulator live on the owner PC
   (`D:\autofinx-ml-v4`); the production server cannot train (overloaded, ML paused). A Quick Test
   button on the production UI has no data or compute behind it. Options:
   (a) local-only Laravel + React on the PC,
   (b) the server queues jobs, and a PC runner pulls, runs and uploads summaries,
   (c) move data and compute to a separate research box.
   This is an owner decision.
3. **Pine parsing.** A real Pine parser/translator is a large project. Proposed first adapter:
   - a token/regex **static audit** (repaint/lookahead constructs, `ta.*` inventory);
   - plus **manual translation** of supported logic into Strategy IR (RULE_JSON) with the audit
     attached.
   Auto-translation only for a small `ta.*` subset later. Unsupported constructs are labelled
   `UNSUPPORTED_CONSTRUCT`.
4. **Multiple testing across candidates.** Every uploaded strategy is another test on the same
   development data. Needed:
   - a global trial ledger (count of candidates × variants tried);
   - thresholds that tighten as it grows (e.g. a Bonferroni-style or deflated-Sharpe-style
     haircut);
   - a Quick Test window that is NOT the P5-A/B/C windows (dev_val), otherwise the P5 nested
     selection gets contaminated.
   Proposed: Quick Test on a fixed dev_train slice, Deep Test on dev_train folds; dev_val stays
   reserved for P5.
5. **Tradeability features overlap with G4/G5.**
   - `atr_percentile` ≈ `g4_vol_pctile`; `bollinger_width_percentile` ≈ `g4_bb_width_ratio`;
     `mtf_disagreement_score` ≈ `g5_alignment` (reuse these).
   - Genuinely new: trend efficiency (net move / path length), choppiness index,
     direction-flip rate, range overlap, failed-breakout rate, EMA compression, body/wick
     dominance means. They become group **G7-T**, built in `PatternFeatures.php`-style PHP for
     parity, with the same causal tests.
6. **Shadow/paper reuse.** The server already has the Quick Trade ML shadow loop (Phase 1). SL12
   should extend it, not create a second shadow system.
7. **Expectation.** The base models already WAIT 60–87% of the time. A tradeability layer is
   another way to ask the same "which trades win" question, so expect a modest effect. SL10
   answers this cheaply before the large build.

## 3. Proposed order
1. P4B finishes unchanged (running: `run-p4b.sh`, `logs/chain-p4b.log`) → report (R-018).
2. SL0 read-only repository audit → `strategy-lab/AUDIT.md`.
3. Draft SPEC §104 / §105, D-030 / D-031 as PROPOSED, with the corrections above → STOP for the
   owner.
4. Owner choices: (a) run SL10 tradeability experiment first (recommended), (b) where Strategy Lab
   runs (point 2), (c) `"approve Strategy Lab build"` for SL2+.

## 4. Owner decision 2026-10-07 (D-030)
Layers stay independent and optional (default OFF, toggle only after evidence + owner approval); no hard
filter; the V4 pipeline is never changed by them. SL10 (tradeability) runs first, together with candidate
autofinx-v13 S1/S4 — `v4/optional_layers.py`.
