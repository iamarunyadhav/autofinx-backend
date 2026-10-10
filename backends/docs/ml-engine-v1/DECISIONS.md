# DECISIONS (append-only)

Format: `D-NNN · date · decided by · decision · why`. To change a decision, add a new entry that
supersedes the old one (say which). Never edit or delete past entries.

---

**D-001 · 2026-10-06 · owner** — ML training runs on the owner's PC, never on the production server.
Why: server is 4 cores / 7.7 GB, load 20–46, swap 96%, disk 94%; its own scheduler + workers +
MariaDB saturate the CPU (RESULTS R-001). Training/export there crawls and endangers live trading.

**D-002 · 2026-10-06 · owner** — Market data for local training comes directly from official
Binance public sources (data.binance.vision bulk, Binance REST for tails/gaps), not from the server.
Why: server export ran at ~2,000 rows/min; Binance direct pulled 526k 5m candles per coin in minutes.

**D-003 · 2026-10-06 · owner** — Adopt the V4 path-aware spec (SPEC.md §0–73) with review
corrections C1–C10, and the locked phase order P1→P8, STOP after P8.

**D-004 · 2026-10-06 · owner (C1)** — Conclusions must be stated precisely. The 12 rejected V3 models
show only that "V3 features + endpoint labels + current LightGBM/calibration/decision policy did not
demonstrate sufficient out-of-sample net edge under the current gates". It does NOT prove the
features have no directional edge (labels, model, features or actual predictability could each be
the cause).

**D-005 · 2026-10-06 · owner (C2, C3)** — P3 policy laboratory uses training + validation /
walk-forward only. The final newest holdout stays sealed until targets, model design, SL, profit
activation, trailing, probability and risk gates are frozen (P5). P3 is a diagnostic/baseline
checkpoint (does the exit engine create fake performance, does SL/trailing change payoff
mechanically, do costs eat small moves), not a verdict on whether V4 ML can work.

**D-006 · 2026-10-06 · owner (C4)** — Two target layers. Layer A = policy-independent raw path truth
(MFE, MAE, MAE-before-profit, timings, retracement, gap/ambiguity flags). Layer B = policy simulation
(hard SL, activation, trailing, expiry, fees, slippage, funding → realized net + exit reason).
Changing a policy must not require rebuilding Layer A.

**D-007 · 2026-10-06 · owner (C5)** — No fixed runtime promises; tools show real progress/ETA and
report actual runtime. (The earlier "~3 min per coin" figure came from one 1-month file and is void.)

**D-008 · 2026-10-06 · owner (C6, C7)** — Risk invariants: model never owns the hard risk cap
(required SL > cap ⇒ WAIT; confidence never widens the cap); SL tightens only; trailing only tightens
in the trade's favour and never loosens once armed. Break-even SL after profit is a validation
candidate, not an assumption.

**D-009 · 2026-10-06 · owner (C8, C9)** — Native Binance 5m for feature parity, Binance 1m for future
paths, Binance funding history; verify 1m/5m alignment; never fabricate candles. No full-history tick
data; same-1m-bar conflicts = AMBIGUOUS_INTRABAR (never favourable), measure the rate first, optional
aggTrade resolver only if material.

**D-010 · 2026-10-06 · owner** — Create the tool-independent agent `ml-engine-v1`: all knowledge and
tracking live in `docs/ml-engine-v1/*.md` inside the backend git repo, so the work can continue in
any AI tool or account. Claude Code wrappers (skill + subagent) only point at these files.

**D-011 · 2026-10-06 · owner** — Coding of P1 waits for SPEC §74+ (cut off in the original message),
unless the owner explicitly says to proceed without it.

**D-012 · 2026-10-06 · owner + agent** — SPEC §74–101 received and merged; D-011 is satisfied. The
owner's §101 phase list (P3 = label audit, P5 = policy calibration, P6 = walk-forward + holdout) and
correction C10 (P3 policy lab, P5 freeze, P6 holdout) are reconciled into one order (SPEC §101
table): P3 = label audit + path distribution + policy lab (train/validation only); P5 = policy
calibration then FREEZE; P6 = walk-forward + one holdout. Holdout stays sealed through P5.

**D-013 · 2026-10-06 · owner** — Execution scope rules from SPEC §75/§95/§96: BTC 15m smoke test on a
small window before multi-year work; progressive training order (BTC, ETH 15m → other coins 15m →
60/120/240/1440); the runner still supports the full matrix and resumes instead of restarting.

**D-014 · 2026-10-06 · owner** — SPEC §102 Causal Pattern Intelligence is LOCKED: P4 splits into
P4A (V4 labels + V3 features) and P4B (feature schema `qt-shadow-features-v4`, groups G1–G6 in fixed
order with fold-consistent ablation; G7 optional after coverage check; G8 excluded), adjustments
A1–A6 and final constraints L1–L9. Key points: primary holdout arm pre-registered as Arm 3 (Arm 2 if
no group survives, L9); market SL cap chosen in P5 from P3 distributions, account risk cap out of
scope; G5 uses only closed UTC-aligned HTF bars; swings usable only after confirmation; stress matrix
frozen in P5; P6/P7 never retune. Why: separate the label/exit effect from the feature effect and
keep the one-shot holdout a real test. Owner reviewed the proposal with an external reviewer
(ChatGPT) and approved it.

**D-015 · 2026-10-06 · owner** — Final phase order (supersedes the table wording in SPEC §101 where
they differ): P0 audit ✅ → P1 Binance data (1m + 5m + funding, G7 coverage audit, integrity, gaps,
alignment) → P2 Layer A path truth + V3 feature export → P3 V3-vs-V4 target audit, path
distributions, policy lab (train/val) → P4A → P4B → P5 FREEZE (feature groups, hyperparameters,
quantiles, probability thresholds, market SL caps, activation, trailing, risk/reward and uncertainty
gates, costs, stress matrix, 3 arms + primary) → P6 one holdout opening, all arms, same timestamps /
costs / engine → P7 pre-registered stress → P8 reports (ACCEPT / REJECT / FRAGILE) → STOP. Holdout
sealed P2–P5.

**D-016 · 2026-10-07 · agent, PROPOSED — owner to confirm** — V4 splits (`v4/splits.py`): holdout =
newest 15% of each symbol's anchors (same fraction as V3; for BTC 2026-01-04 23:15 → now, 79,131 rows);
development = everything before holdout start minus 1440 minutes (purge for the longest path, all
horizons); dev_train / dev_val = 70% / 30% of development with the same purge. P3+ code must use
`dev_mask`; the P3 lab asserts no holdout row is used. Why: matches V3 for the pre-registered
comparison (L1) and keeps every horizon's path out of the holdout.

**D-017 · 2026-10-07 · agent** — Same-1m-bar conservative rules for Layer B (policy.py docstring): a bar
touching both the hard stop and activation before arming ⇒ AMBIGUOUS_INTRABAR evaluated as the stop;
a bar making a new peak and crossing the stop implied by it ⇒ exit at that new stop (flag
`same_bar_trail`), including the arming bar; a bar gapping through an existing stop ⇒ fill at its open.
Trail distance is measured in % of the entry price. Why: SPEC §25-26 forbids favourable ordering; the
rates are reported so the owner can decide on an aggTrade resolver (§26).

**D-018 · 2026-10-07 · owner** — D-016 splits CONFIRMED (holdout newest 15%, dev 70/30 with purge).

**D-019 · 2026-10-07 · owner (direction) + agent (method)** — Plan an aggTrade resolver for
same-1m-bar ambiguity (SPEC §26). Order: (1) the full P3 report counts ambiguous minutes per symbol ×
horizon × policy; (2) resolve only those minutes with official Binance futures aggTrades (REST per
minute, not 5-year bulk tick files); (3) re-run the P3 policy lab and report how many outcomes changed.
Unresolvable minutes stay conservative. Must finish before P5 freezes the policy.

**D-020 · 2026-10-07 · agent (supersedes the method in D-019; owner direction unchanged)** — Binance
REST aggTrades only serves the last 2 days (`-4166 Search window is restricted to recent 2 days`), and
daily bulk aggTrades files are 17–60 MB/day for BTC (~150 GB for 6 coins × full history). So:
(1) before P5: SAMPLED resolver — ~60 random dev days per coin, stream each daily file, keep only the
ambiguous minutes, measure how often the conservative rule was wrong and the P&L difference;
(2) at P6: resolve every ambiguous minute of the executed holdout trades (one time, ~9 months).
If (1) shows a small difference, conservative evaluation stays for P3–P5.

**D-021 · 2026-10-07 · agent, PROPOSED — owner to confirm** — Replace the inherited absolute probability
floor (0.55, V3) for V4 decisions. Why: V4 success = "net ≥ edge on the realizable path", whose base
rate is ~11% at BTC h15 (R-011), so 0.55 almost never triggers; the economically right gate is expected
net. Proposed P5 decision gate: (1) EV = p·avg_win + (1−p)·avg_other (costs + funding included) ≥
min edge + margin; (2) relative confidence floor p ≥ lift × side base rate (calibration slice). Candidates
fixed in advance: lift ∈ {1.25, 1.5, 2.0} × margin ∈ {0, 0.03, 0.06}% (9 combos), selected per horizon
on dev_val + walk-forward folds with fold consistency, frozen in P5, never tuned on the holdout. Other
gates (min trades, t-stat, cost stress, drawdown) unchanged.

**D-022 · 2026-10-07 · owner** — D-021 CONFIRMED: V4 decision gate = EV gate + relative confidence
floor, chosen in P5 from the 9 pre-declared combos on validation, then frozen.

**D-023 · 2026-10-07 · owner (external review) + agent** — Corrections, all applied:
1. `same_bar_trail` is NOT conservative by itself (low-first would keep the trade open). Evaluation now
   explores both orders (`policy.worst_case_path` / `apply_worst_case`) and keeps the lower net;
   V4 labels EXCLUDE unresolved rows (stop-vs-activation ambiguity and same-bar trailing) from fit,
   calibration and label metrics until aggTrades resolve them. Supersedes the same-bar rule in D-017.
2. Wording: "V3 endpoint labels are materially misaligned with the new SL/trailing execution policy" —
   not "objectively wrong" (V3 never used an SL).
3. AUC via `sklearn.metrics.roc_auc_score` (isotonic ties).
4. Resolver returns the resolved exit bar and recomputes funding up to the resolved exit.
5. `trade_stats` drawdown renamed `max_drawdown_additive_pct`: research diagnostic only, not account
   drawdown, not a risk gate until position sizing / equity simulation exists.
6. Agent correction: the earlier explanation "V3 base rate ~50%" was wrong — BTC h15 V3 positive base
   rate is ~14.5%. D-021/D-022 are amended: EV / expected utility is the PRIMARY gate; the relative-lift
   floor is a SECONDARY candidate that may be "none" — candidate set lift ∈ {none, 1.25, 1.5, 2.0} ×
   margin ∈ {0, 0.03, 0.06}% (12 combos), chosen on dev validation / walk-forward, frozen in P5.

**D-024 · 2026-10-07 · owner (external review) + agent** —
1. P4B runs regardless of P4A verdicts: P4A only isolates the label/exit change on the old 37
   features; P4B tests whether pattern features improve direction. A P4A reject is not a reason to skip P4B.
2. Resolver days are STRATIFIED by year × daily-volatility tercile (round-robin), not plain random,
   and the report breaks results down per stratum.
3. If resolved rows are ever added to training truth, the affected P4A/P4B experiments are re-run.
   Until then unresolved rows stay excluded (current P4A is clean/conservative).
4. P4A/P4B are judged per coin × horizon on the full picture — AUC/Brier, trade count, avg/total net,
   profit factor, t-stat, positive walk-forward folds, BUY/SELL balance, probability and EV
   distributions, unresolved-exclusion % — never on AUC or avg net alone; few-trade results are weak
   evidence. Code tests passing proves behaviour, not profitability; the verdict is P6 only.

**D-025 · 2026-10-07 · agent (pre-registered before any P4B result; owner may veto)** — P4B ablation
rule (`v4/ablation.py`): folds INSIDE dev_train only (test windows 55–70%, 70–85%, 85–100% of
dev_train, expanding train, purge = H); score = mean BUY/SELL classifier AUC on clean rows; a group is
kept if median fold gain ≥ 0.002 AUC, gain > 0 in ≥ 2 of 3 folds, mean Brier not worse by > 0.0005,
and total features ≤ 80; order G1→G6, a kept group joins the baseline. Economics are judged with the
frozen policy in P5/P6, not in the ablation. dev_val and the holdout are not used.

**D-026 · 2026-10-07 · owner (external review) + agent** —
1. Confirmed: P4B ablation scores the V4 BUY-success / SELL-success targets (realizable path, net ≥
   edge), not generic up/down. The 80-feature cap counts TOTAL model features incl. the V3 base.
2. Dynamic SL target: side-specific quantile models (q25/q50/q75) of `max_adverse_before_profit_pct`,
   trained/scored only on paths that became profitable (conditional), alongside full MAE.
3. Dynamic trailing target: new Layer A field `pullback_before_continuation_pct` (largest drop from the
   running peak that was followed by a higher peak — a "normal" pullback; the old `peak_retracement_pct`
   also contains the final reversal and was too wide, R-009). Side-specific conditional quantile models.
   P5 still compares this against a simpler causal volatility trailing policy and keeps the better one.
4. MFE stays the reward / profit-activation input; it never becomes an exact hard TP.
5. P5 final step (mandatory, before P6): after the dynamic SL + activation + trailing + EV/confidence
   policy is selected and frozen on development data, rebuild the V4 success outcomes UNDER THAT FROZEN
   POLICY, retrain and recalibrate the final selected-feature models on development data, freeze them,
   and only then open the holdout. No training on one exit policy and evaluating on another.
6. Feature manifest kept in `docs/ml-engine-v1/FEATURES_V4.md` (formula, lookback, knowable time,
   confirmation lag, HTF boundary, test status). Pattern feature count is 57 (94 total), not 60.

**D-027 · 2026-10-07 · agent, PROPOSED — owner to review before any P5 code** — P5 design in SPEC §103:
CAP = p90 MAE-before-profit (dev_train winners); 12 pre-registered exit policies (SL a ∈ {1.0, 1.25} ×
q75 MAE-before-profit; activation b ∈ {0.6, 0.8} × q25 MFE floored at cost + edge; trailing model
1.0/1.5 × q50 pullback or 0.5 σ volatility); 12 gate combos; two-stage selection (24 evaluations) on 3
purged dev_val folds with min-trade and fold-consistency rules; REJECT when nothing passes; final retrain
under the frozen policy with 5-block time cross-fitted quantile predictions; full freeze record.

**D-028 · 2026-10-07 · owner (external review) — D-027 LOCKED as amended** (SPEC §103 rewritten): CAP from
Layer A dev_train, per symbol × horizon × side, ambiguity excluded; reward floor ⇒ WAIT (activation never
forced up); quantile quality gate with simple fallbacks; consistency + uncertainty + OOD + conflict gates
frozen; nested P5-A / P5-B / P5-C selection on dev_val (no evidence reused); pre-registered min trades,
fold consistency, PF ≥ 1.05, t ≥ 1.0 and a simplicity tie-break; ambiguity never dropped from economic
comparison; forward-chaining (past-only) cross-fitting for the final retrain; multipliers shared by
LONG/SHORT. Agent note: nesting reduces the data per stage (BTC dev_val ≈ 15 months split 40/30/30), so
some symbol × horizons may be REJECTED for too few trades — accepted as the honest outcome.

**D-029 · 2026-10-07 · agent proposal; KEPT 2026-10-07 (owner delegated the call to the agent: "neeye sollu")** — P5 EV consistency: the success
probability used in EV = p·avg_win + (1−p)·avg_other must describe success under the SAME candidate exit
policy as avg_win / avg_other. The REFERENCE-policy classifier's raw score is re-calibrated per (candidate
policy, side) with isotonic regression on the dev_train calibration slice (out of fit; no dev_val, holdout
sealed), and the lift gate uses that candidate's base rate. No model retrain. Already in
`v4/p5_run.py` (`ev_params`); revert = restore the reference isotonic. Effect on BTC h240: verdict unchanged
(R-017). A stronger alternative (one classifier per candidate policy) is not proposed — the recalibrated
score shows almost no signal, so it is unlikely to change the verdict and it costs 12× training.
D-029 decision note: kept because (1) EV needs p and win/loss measured under the same exit policy, (2) it made
results stricter, not rosier (BTC h240 max EV −0.083% → −0.12%), (3) no retrain cost, (4) P4B/P5 results
will be computed correctly if an edge appears.

**D-030 · 2026-10-07 · owner — optional, independent research layers (tradeability / noise + Strategy Lab)**:
noise/chop and external-strategy logic are built as INDEPENDENT optional layers (`v4/optional_layers.py`,
`v4/strategy_v13.py`). They never change the V4 pipeline (P4B/P5 code, selections, splits). Default OFF.
Every layer is reported OFF vs ON in three roles: FEATURE (P4B rule D-025), WAIT (a tradeability TAKE/SKIP
model on the layer columns, keep top 50% / 30%), and RULE (a script's own gates, e.g. v13 chop ≤ 62). No
hard filter is assumed. Evidence: dev_train folds only (dev_val reserved for P5, holdout sealed). A layer is
switched ON (later: a UI toggle) only with a passing verdict AND explicit owner approval. Pre-registered
WAIT verdicts: USEFUL_AS_WAIT_LAYER (ON beats OFF in executed avg net by ≥ 0.01%/trade in ≥ 2/3 folds with
profit retention ≥ 50%), PROFITABLE_WITH_LAYER (also ON net > 0 in ≥ 2/3 folds), OVER_FILTERED, NO_VALUE.
Strategy Lab product build (DB/API/UI, SL2+) still waits for "approve Strategy Lab build".

**D-031 · 2026-10-07 · owner ("approve Strategy Lab build") — Strategy Lab architecture (SPEC §105)**: built
as Laravel control plane (4 new tables: candidates, immutable versions, runs, immutable approvals; API under
`workspaces/{ws}/strategy-lab`; owner/admin for mutations) + Python runner (`v4.lab_runner`) + React UI.
Agent choices recorded for review: (1) tests run where the 5-year data lives (research PC, option a);
production refuses with RUNNER_UNAVAILABLE until a runner exists there (option b later); (2) Pine is audited
statically and tested only through reviewed translations matched by normalized sha256 — no Pine interpreter;
(3) Quick Test uses dev_train only (not dev_val) so P5 nested selection stays clean; (4) the verdict rule is
fixed in code and never uses win rate / accuracy. Migration `2026_10_13_000000_create_strategy_lab_tables`
is written but NOT applied (owner approval needed per environment).

**D-033 · 2026-10-07 · agent, PROPOSED — owner to approve before code runs — TAKE/SKIP meta-label (Stage E)**:
- *Proposals:* per symbol × horizon, every anchor whose P5 level gates pass (reason 0) on the side with
  the higher candidate-calibrated probability, under P5-A's chosen exit policy (or a1.0_b0.8_vol when P5-A
  qualified none — this is stated in advance, not picked after results).
- *Meta label:* the SAME candidate policy's worst-case net ≥ MIN_EDGE (D-029 semantics; ambiguous rows
  kept in economics, excluded from fitting).
- *Meta inputs (all known at T0):* raw + calibrated p_long / p_short, their margin, EV, MFE q25/q50/q75,
  adverse q75, pullback q50, reward/cost, reward/adverse, uncertainty width, OOD z-share, G7-T columns.
- *Model:* LightGBM classifier + isotonic.
- *Evaluation:* dev_train ablation folds (55–70 / 70–85 / 85–100 %) only. ON keeps proposals whose meta
  score is in the top 50 % / 30 % of the fold-train distribution; the rest become WAIT_META_SKIP.
- *Verdict (as D-030 WAIT):* USEFUL if executed avg net ON − OFF ≥ 0.01 %/trade in ≥ 2/3 folds with
  profit retention ≥ 50 %. PROFITABLE if, in addition, ON net > 0 in ≥ 2/3 folds. Otherwise
  NO_VALUE / OVER_FILTERED. False-WAIT, loss avoidance and trade retention are reported.
- *Integration:* only a USEFUL verdict lets P5 add "meta ON (share 50 / 30)" to its P5-B gate candidates.

**D-035 · 2026-10-07 · agent, PROPOSED — owner to approve — execution-cost scenarios (Stage G)**:
- Same predictions and the same exits in every scenario; only the execution changes.
- *C0 (current):* taker entry + taker exit, 0.12 % round trip + funding.
- *C1:* maker entry + taker exit.
  - At the signal (T0) a post-only limit order sits at the T0 reference price for F = 2 minutes.
  - It fills only if a 1m bar trades THROUGH the price (long: low < limit; short: high > limit), at the
    limit, from that minute.
  - A missed fill = no trade (reported as MISSED, never as a free WAIT).
  - Cost = maker 0.02 % + taker 0.05 % + 0.01 % exit slippage = 0.08 %, plus funding.
  - SL / trailing / time exits stay taker.
  - Reported: fill rate; filled vs unfilled outcome of the same proposals (adverse-selection check);
    C0 vs C1 avg net, PF and t.
- *C2 (maker exits):* deferred — the V4 exits are stop / trailing / time; none is a resting take-profit,
  so a maker exit does not apply without changing the exit design.
- *Fees:* assumed Binance USDⓈ-M VIP0 public rates (maker 0.02 % / taker 0.05 %). The owner confirms
  the real account tier; stress keeps C0 and +25 / +50 % cost.
- *Validation:* an aggTrades sample check of the 1m trade-through fill rule (like R-014) before any
  conclusion.

**D-033 / D-035 · 2026-10-07 · owner "continue" (taken as the go for the dev-only experiments) — amended
before any run:**
- (1) The verdict uses "profit retention >= trade retention" instead of a fixed 50 % floor. The same
  revision applies to the D-030 WAIT verdict, because the fixed floor made OVER_FILTERED automatic at a
  30 % keep share (R-020).
- (2) Meta-label folds are forward-chained inside each D-025 fold's train rows:
  - base 60 %: direction, quantiles, cap, calibration;
  - meta 40 %, purged by H: out-of-sample proposals → meta fit;
  - fold test: evaluation.
- (3) Arms on the fold test proposals: OFF, EV_GATE (P5-style), META_50, META_30. Each runs under C0
  (0.12 %) and C1 (maker entry, trade-through, 0.08 %). C1 reports the fill rate and the C0 net of filled
  vs missed proposals (adverse-selection check).
- Code: `v4/execution.py`, `v4/meta_label.py`, `policy.simulate(entry_price=...)`, CLI `meta`.

**D-036 · 2026-10-07 · owner ("ok rendum") — adopt the independent review (Codex) and its roadmap**
(`REVIEW_CODEX_2026-10-07.md`).
- (a) The automatic 30-cell meta / maker run was cancelled before it started.
- (b) The roadmap P0 → P6 replaces the PLAN_PROFITABILITY order where they differ:
  - TAKE/SKIP (D-033) and continuation (D-034) move behind P1/P2 and must beat simpler executable
    baselines first;
  - maker cost (D-035) moves to P5 with the repaired simulator.
- P0 done: fixes A–E + identity stamps + regression tests (V4 suite 97/97).
- The running P5 T0 baseline continues from the frozen code snapshot `D:/autofinx-ml-v4/snapshots/
  p5-baseline-20261007` (MANIFEST.sha256). Its reports carry the pre-fix B behaviour (quality gate crossing)
  and are labelled "pre-P0" in RESULTS.
- Replays after the baseline (from a new frozen snapshot): P4B 30 cells (fix C), audit (fix E), optional
  layers h15 (fix D + D-030 rev verdict).

**D-037 · 2026-10-07 · agent, under D-036 roadmap P2 (owner "continue") — pre-registered event setups, rule-only:**
- **S1 TREND_PULLBACK:**
  - context: 1h close > EMA20 > EMA50, last COMPLETE 1h bucket;
  - pullback: low of the last 6 bars touched the 5m EMA20, and the 24-bar high minus that low ≥ 1 ATR14;
  - trigger: close > previous high and close > EMA20.
  - Short mirrored.
- **S2 RANGE_REVERSION:**
  - context: 1h ADX14 < 20;
  - trigger: the bar pierced the previous 48-bar low and closed back above it.
  - Short mirrored.
- **Exits:** E1 = stop 1.5 ATR + time exit at H; E2 = stop 1.5 ATR, activation 1 ATR, trail 1 ATR.
- **Baselines:** CONTEXT_ONLY and ALWAYS, same side and exits.
- **Data:** BTC/ETH h60/h240 first, dev_train only. Cost 0.12 (stress 0.15 / 0.18). Non-overlapping execution,
  1-day block bootstrap.
- **No parameter search.** A setup is "promising" only if net > 0 with the bootstrap 90 % interval above 0,
  ≥ 3 of 4 chronological slices positive, and it beats CONTEXT_ONLY. Only then does it go on to P3 payoff
  modelling and to the other coins (same fixed rules).
- Code: `v4/p2_setups.py`, CLI `p2`.

**D-038 · 2026-10-07 · owner "ok start pannu" (P4) — context information, pre-registered test**:
- **Features** (`v4/p4_context.py` → `ctx.parquet`), every value known at T0:
  - cross-market: BTC / ETH 1h / 4h returns, relative strength vs BTC, beta / correlation 1 d / 3 d, 6-coin
    dispersion;
  - funding: last published rate, minutes to the next event, z vs 90 events;
  - Binance daily metrics with create_time ≤ T0 − 5 min: OI change 1h / 4h / 24h, OI z 30 d, top-trader and
    global long/short, log taker ratio and its 1h mean. Gaps over 15 min → NaN.
- **Test:** P1 diagnostics, V3 base vs V3 + ctx, on identical D-025 folds (BTC / ETH h60 / h240 first).
- **Pass rule:** the paired lift with ctx has CI90 > 0 in ≥ 2 / 3 folds AND its mean lift is at least 2× the
  V3-only lift, AND the top-margin 10 % executed net improves by ≥ 0.02 %/trade in ≥ 2 / 3 folds.
- If passed → P3 economics (direct net-return models) on that cell. If failed → final P8 report for the
  OHLCV + context family.

**D-039 · 2026-10-07 · owner "continue" (Codex item 4) — P3 economics, pre-registered:**
- **Model:** LightGBM huber regression of the after-cost NET return, plus a q20 quantile, per side.
  - Fixed executable policy: stop 1.5 × ATR14(5m), no trailing, time exit at H.
  - Worst-case execution; cost from `v4/costs.py`.
  - V3 base + ctx, dev_train D-025 folds, fit / cal purged.
- **Arms:** PRED_GT_0 (best side if predicted net > 0, else WAIT), PRED_GT_0_AND_Q20 (q20 > −stop), TOP10,
  ALWAYS_LONG.
- **Pass:** PRED_GT_0 executed net > 0 with block-bootstrap CI90 > 0 in ≥ 2 / 3 folds.
- **Cells:** BTC / ETH × 60 / 120 / 240. Then P1 / P2 at h120.
- **Fees:** `v4/costs.py` is the single source of truth (default VIP0 0.02 / 0.05 % + 0.01 % slippage per leg
  = 0.12 / 0.08 %). Real account rates via `fees.json` with AUTOFINX_V4_FEES=account; every report records
  them.

**D-040 · 2026-10-07 · owner — real account fees verified**: live Binance USD-M account (AccountFeeRateService →
/fapi/v1/commissionRate, source "account") = maker 0.02 %, taker 0.05 % for all six symbols. Identical to the
research default, so C0 = 0.10 % fees + 0.02 % slippage buffer = 0.12 % and C1 = 0.07 % + 0.01 % = 0.08 % stand;
no rerun needed. Saved to `D:/autofinx-ml-v4/fees.json`. Open assumptions: slippage 0.01 %/leg (no fill data
yet); a BNB fee discount (if enabled) would lower C0 to ~0.11 %.

**D-041 · 2026-10-07 · owner ("Codex plan accurate-aa pannu") — V5-lite, pre-registered BEFORE any run.**
New dev-only experiment. New run ids; nothing overwritten; holdout sealed; no live / shadow.

**Preconditions (Codex blockers):**
- 1–4 fixed in P0 (D-036).
- 5: V5-lite uses a FIXED feature set (no selection), so nothing is selected outside a fold. Preprocessing
  (isotonic calibration) is fit inside each outer fold's training events only.

**Scope:**
- Symbols BTCUSDT, ETHUSDT; horizons 60 / 120 / 240.
- Event rows only. Data: dev_train with 5 expanding purged walk-forward folds (test windows 50–60 / 60–70 /
  70–80 / 80–90 / 90–100 % of the dev_train span, purge H). dev_val stays untouched as the later
  confirmation set.

**Regime** (last COMPLETE 1h / 4h buckets), in precedence order:
- VOL_EXPANSION: 1h ATR % > 1.5 × its mean over the previous 720 1h bars.
- UP: 1h close > EMA20 > EMA50 and 4h close > EMA20.
- DOWN: mirrored.
- RANGE: 1h ADX14 < 20 and 1h efficiency(12) < 0.3.
- Otherwise UNCERTAIN.
- UNCERTAIN and VOL_EXPANSION → WAIT (reported only).

**Setups** (separate models, never mixed):
- **S1 TREND_PULLBACK** (UP → long, DOWN → short):
  - low of the last 6 bars ≤ 5m EMA20, and the 24-bar high minus that low ≥ 1 ATR;
  - momentum weakened: RSI14 3 bars ago < 45 (short: > 55);
  - resumption: close > previous high and > EMA20;
  - market support: BTC 1h return in the trade direction (ETH's for BTC).
- **S2 RANGE_REJECTION** (RANGE):
  - stable range: previous 48-bar range width 2–8 ATR;
  - the bar pierces the previous 48-bar low (high) and closes back inside;
  - rejection wick ≥ 50 % of the bar;
  - invalidation (entry − bar low) ≤ 1.5 ATR.

**Exits:**
- E1 PRIMARY: stop 1.5 ATR14(5m), time exit H.
- E2 secondary: + activation 1 ATR, trail 1 ATR.
- Cost C0 0.12 % (real account fees, D-040) + funding, worst-case execution. Maker not used in V5-lite.

**Models** (per setup × horizon × exit × side; per-symbol PRIMARY, pooled BTC+ETH secondary):
- LightGBM huber net regression + q10 / q25 quantiles;
- calibrated P(net > 0), P(net ≥ 0.06), P(stop hit).
- Inputs: V3 base 37 + ctx (cross-market, funding, metrics) + premium / basis (archive) + event features
  (pullback depth / ATR, EMA distance, RSI, range width, wick, boundary distance, ATR %).

**Decision:**
- Utility U = mean − 0.25 × (mean − q10) − 0.03 (cost +25 % stress).
- BUY if U_long > 0 and U_long − U_short ≥ 0.02; SELL mirrored; else WAIT.
- Arms: V5_UTILITY, RULE (event side, no model), CLASSIFIER_EV (old p × avg win / loss).

**Outputs:**
- side-selection lift;
- U deciles vs realised net;
- gross / net / PF / win;
- 1-day block bootstrap CI90;
- cost 0.15 / 0.18;
- 1-minute delayed entry;
- per-symbol and per-fold concentration.

**PASS** (per setup × horizon, PRIMARY config only: per-symbol, E1, V5_UTILITY) — all required:
- avg net > 0 in ≥ 4 / 5 folds;
- aggregate PF ≥ 1.15;
- ≥ 100 executed trades;
- CI90 lower bound ≥ −0.01;
- avg net ≥ 0 at cost 0.15;
- avg net > 0 with a 1-minute delay;
- BTC and ETH both > 0 (else labelled SINGLE_SYMBOL);
- no fold > 50 % of positive profit;
- beats RULE by ≥ 0.02 %/trade.

Secondary configs are reported, never promoted. 6 primary tests, so multiple testing is acknowledged.
No tuning before PASS. FAIL → new data hypothesis, not more tuning.

**D-042 · 2026-10-07 · owner ("p8 final") — close the V4 research family with the P8 final report**
(`FINAL_REPORT_P8_2026-10-07.md`). 30/30 symbol × horizon cells REJECTED. No candidate was frozen; the P6
holdout stays sealed for a future, different hypothesis. Nothing is installed or connected to live trading.
Continuing requires a new owner decision (new data such as live-recorded order book / liquidations / fills,
or a different strategy family).

**D-043 · 2026-10-08 · owner ("seivom, autowa run pannu, naan approved paniden") — Edge Search v1**
- The plan is frozen in `EDGE_SEARCH_V1.md` (Part A = the owner's plan; Part B = the agent's pre-registered
  resolution, written before any run).
- The agent runs it unattended: dry run → full run → report.
- Code: `external-predictive-engine/edge_search/`; tests `tests/test_edge_search.py` (20; engine vs a brute-force
  re-implementation on real data matched exactly: 4,347 / 4,347 trades).
- Split: the existing sealed per-symbol split is reused (BTC DEV → 2024-09-23, VAL → 2026-01-03, HOLDOUT
  2026-01-04 → never read). All coins are physically cut at the global seal 2026-01-03.
- Prior look at VAL by earlier ML experiments is disclosed in B1.
- Decision rules fixed in the plan (BH-FDR over all items, MDE-based INCONCLUSIVE, the Stage 2–4 pass rules).

**D-044 · 2026-10-08 · agent (executing D-043) — Edge Search v1 verdict**: (B) no tradable directional edge in
the tested families after real costs, (C) 4h / 1d INCONCLUSIVE for lack of independent trades. 0 Stage-1 survivors, holdout
never read, plan sha d6485a10de85… Next steps need new owner decisions: (a) record order-book / liquidation / fill data live,
(b) add more coins / years for the slow timeframes, (c) a pre-registered episode-level carry test.

**D-045 · 2026-10-08 · owner (Track B approved, host = this PC; maybe a server later) + agent (design) — live collector v1**:
public Binance USD-M streams only, no keys, no trading, research only. Runs detached on the PC, below-normal priority, own venv,
host-agnostic JSON config. Streams: `/market/stream` (aggTrade x6, markPrice@1s x6, !forceOrder@arr), `/public/stream`
(bookTicker btc/eth aggregated to 1 s in-process and NOT stored raw; depth20@500ms btc/eth raw), REST openInterest every 60 s.
Design choices (agent): bucket for the 1 s snapshot = UTC second of local receive time; a silent stream (per-kind threshold) forces a
reconnect of its websocket and writes a gap-log line; disk guard pauses writing below 20 GB free and resumes at 22 GB (hysteresis), always logged;
no Windows power/sleep settings changed (owner must disable sleep). The data is a **30-day pilot**: quality, descriptive checks and execution
realism only, **no pass/fail or edge claim** from it (plan section 3 point 3). Any later analysis needs its own pre-registration.


## 2026-10-08 ? M1 momentum-candle breakout

Owner authorized separate M1 hypothesis, no tuning, six existing coins at 15m/1h/4h, development only. Entry uses only explicit M1 predicates. Attached Pine supplies signal-close anchored 2 ATR stop and three-bar failed-breakout semantics. Exit decisions fill next open; gaps fill at worse open. Legacy v2 Python lacks failed-breakout exits and is not modified. Per-timeframe pooled gates: N>=100, gross>0.12%, PF>1.15, net t>=2, >=4/6 coins net-positive. Formal spec persisted before evaluation at D:/autofinx-ml-v4/reports/strategy-lab/m1-frozen-dev/spec.json. Hypothesis rejected; no retuning or holdout evaluation authorized by this run.

**D-045 · 2026-10-08 · owner (approved Track A pilot) / agent — Edge Search v2 PILOT**: frozen plan `EDGE_SEARCH_V2_PILOT.md` (sha 3be7cf03…ea3924), BTC/ETH, aggTrades + bookDepth 2025-10..12, pipeline built to extend (aggTrades 2021-10.., bookDepth 2023-01..) but only the pilot window run. Verdict INCONCLUSIVE (48/60), 0 leads, holdout never read. Pilot months are now burned as confirmation data for these 10 events. Operational deviations (no effect on statistics): memory guard lowered from 4 GB to 2 GB available commit (the PC sat at 2.9 GB, the guard would have waited forever); network-retry loop added to the runner script. Full run (~12-16 h) needs a new owner approval; months after the 2026-01-03 seal are refused by the code.

## D-046 (2026-10-08): directional prediction research CONCLUDED; full Edge Search v2 NOT run
Owner decision. Evidence: V4/P8 30/30 REJECTED, Edge Search v1 0/1,844 survivors, v2 pilot (order flow + depth, 2025-10..12) 0 LEAD, 0 FDR survivors, 15m/1h gross edge is below the 0.12% round-trip cost. The 12-16 h full v2 run was declined. The holdout (2026-01-04 on) stays sealed and unread. New work moves away from "predict direction" toward risk, regime, volatility, execution-cost and non-directional (carry) uses of the data. The live collector keeps running as a 30-day pilot data source (no pass/fail claims).

## D-047 (2026-10-10): Regime + Tradeability v1 — owner request, agent executed, verdict recorded
Owner asked (2026-10-10) to apply his regime idea (V5 prompt sections 6, 7, 10-11) to 3 years of local data and report only the result. Agent froze `REGIME_TRADEABILITY_V1.md` (sha256 a48e7406…f966, `D:\autofinx-ml-v4\regime_v1\PLAN_SHA256.txt`) before any computation and ran it unattended. Agent design choices (all fixed in the plan before results): window 2023-01-01..2025-12-31 with data cut 2026-01-01 (earlier than the 2026-01-03 global seal, holdout reads 0); 9 rule-based regimes with ADX 25/20, EMA20/50, swing structure k=3 (confirmed), BBW / ATR percentile 100 bars; 7-measure tradeability score T (fixed ramps, equal weights) + move gate; filter grid T40/T50/T60/M/TM counted in FDR; H = 4 bars; filtered sets are subsets of the unfiltered trades with a random-subset null; circular-shift null for Q1. Verdict: Q1 direction FAIL, Q1 magnitude LEAD (volatility information only, not an edge), Q2/Q3 FAIL or INCONCLUSIVE (4h under-powered), Q5 not tested. No after-cost edge from these fixed rules; no change to any live path, no model installed. Pause / sizing uses of the volatility label are exploratory ideas only and would need their own pre-registered plan. Holdout stays sealed.

## D-048 (2026-10-10): Quick Trade Replay v1 — owner "start", agent executed, verdict recorded
Owner approved a pre-registered historical replay of Quick Trade decisions (live presets, SL / TP / trailing / volatility / regime gates) against a random-direction null, development window 2023-01-01..2025-12-31, holdout sealed (reads 0). Agent froze `QUICK_TRADE_REPLAY_V1.md` (sha256 d6dc8a4e…ad00b) before any computation. Agent design choices (fixed before real results): presets exactly the three in `config/quick_trade.php`; primary cost 0.12 % (D-040) with 0.14 % (code), 0.11 % / 0.08 % (maker, report-only); entry-matched exits on the X1 chain; SL / TP in the code's rangePct units (atr% x sqrt(h)); worst-case intrabar; arm D declared not available (no out-of-fold V4 predictions). AMENDMENT 1 (written after code / synthetic tests, before any real-data result; new sha ec434d43…85bc7): matched random-direction null on every exit x gate for B and C and a difference-in-differences family F4; materiality bar 0.03 % per trade for a LEAD; risk statistics. One bug-fix during the first real run (X4 table burn-in for coins listed in-window), no result-dependent change. Verdict: no exit / gate component adds edge beyond a random direction (0 LEAD in 1,071 items), every futures variant loses about the round-trip cost, stops cut tail risk without changing the mean (exploratory risk layer), the code's volatility gate cuts trade count not per-trade loss; the only above-break-even number is the report-only PAPER binary hit rate of a Bollinger fade (55-57 %, decaying, sign effect). No live path, flag, model or setting changed; any follow-up (e.g. a pre-registered PAPER forward test) needs a new owner decision. Corrections to the request recorded: code maker_entry cost 0.11 %, paper BTC payout 0.86, SL / TP are % of stake and software-polled, live symbols exclude XRP / SUI.


## D-049 (2026-10-10): Experiment E v1 — calibrated probability and Quick Trade decision intelligence; owner request, agent executed, verdict recorded
Owner pasted the "EXPERIMENT E" spec and asked for an unattended end-to-end run, research only. Agent froze `EXPERIMENT_E_V1.md` (sha256 fbd5ddfe...556637) before any result; AMENDMENT 1 before any real-data result (new sha 0c910d7b...fed0). Agent design choices (fixed before results): (1) no genuine OOF predictions exist (verified by scan), so the existing V3 / V4 LightGBM recipes were re-fit walk-forward; (2) because no fresh holdout exists besides the sealed 2026 data, the design is annual test folds 2023 / 2024 / 2025 with an expanding fit window, a 6-month matured calibration window before each test year and a 24 h embargo, and every result is labelled REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT (the requested 3+2 split was NOT imitated: it would have weakened the leakage controls); (3) targets A terminal direction, B 1 R / 1 R barrier TP-before-SL, C after-cost success, D net return at the live presets and costs 0.12 / 0.14 / 0.11 / 0.08 %; (4) Platt is the primary calibrator, isotonic secondary, base rate and constant 0.5 as baselines; (5) decision = BUY if EV_L > 0 and >= EV_S, SELL symmetrically, else WAIT (WAIT is a decision outcome, not a class); EV from the isotonic after-cost-success probability and the calibration-window mean win / loss; (6) families F1 49 / F2 18 / F3 12 with BH-FDR; PASS needs ECON_PASS and a calibrated F1 lead. Loader refuses any 2026 file / cut and feature / target rows at or after 2026-01-01 (holdout reads 0). Verdict: **INCONCLUSIVE** by the frozen rules (the primary's MDE 0.085 % exceeds 0.06 %), substantively NO: raw probabilities are overconfident and not calibrated, Platt calibration on a later window gives a small real information gain (mostly volatility) that fails the strict CAL_CALIBRATED rule, the calibrated probability never reaches 75 %, and no policy is net profitable (primary -0.060 % per trade, CI90 below 0; full-power Q-SIDE -0.117 %, FAIL). Disclosed deviations: one F3 verdict-rule coding bug corrected before recording (no verdict impact), constant-0.5 row added, binned-bootstrap CIs for slope / intercept / ECE. No live path, flag, model, setting or server touched; nothing committed; the next step (if any) needs a new owner decision: this study gives no support for a paper forward test of an ML decision policy.


## D-050 (2026-10-10): owner freeze of the LightGBM directional research; one new rule-based baseline; LLM-shadow design only
Owner (Arun) decided on 2026-10-10 after reading Experiment E (R-031 / D-049):
1. **Experiment E is closed formally as INCONCLUSIVE / NO PROFIT EVIDENCE and the existing LightGBM directional research is FROZEN.** Models, code and results stay preserved (nothing deleted, nothing re-run). No threshold re-tuning reruns. The ML confidence % must not be shown to users as a success probability and must not be used for position sizing. Volatility information is the only validated finding (D-047 Q1 magnitude, D-049 "mostly volatility") and stays available for risk research. Fee-aware WAIT and the volatility stop are risk-control CANDIDATES, not validated profit strategies. Production execution is unchanged (nothing was ever connected).
2. Run ONE new, separate, explainable rule-based baseline hypothesis for Quick Trade as a pre-registered experiment: RULE_BASELINE_V1 (plan `RULE_BASELINE_V1.md`, executed under D-051 / R-032).
3. Prepare the DESIGN ONLY (no execution, no API call, no key) of an LLM-analyst shadow comparison: `LLM_SHADOW_DESIGN_V1.md`.
Constraints repeated by the owner: research only, no live trading, no keys, no production server / DB, no Laravel / Ratio Bot / Quick Trade code change, never commit / push, development window 2023-01-01..2025-12-31 only, holdout 2026-01-04+ sealed, collector on D:\autofinx-collector untouched. Recorded by the agent; nothing is superseded in D-043..D-049 (this adds a freeze label to D-049 and a new hypothesis).


## D-052 (2026-10-10): owner decision to host the collector on a Linux SERVER; deployment bundle prepared (D-051 is reserved for RULE_BASELINE_V1 by D-050)
Owner (Arun) decided on 2026-10-10 that the live market-data collector (D-045, `external-predictive-engine/collector/`) must run on a Linux server instead of his laptop, because the laptop keeps going off (laptop log: 955 `silent_stream`, 128 `ws_disconnect`, 24 `missing_agg_ids`, clock about 6.2 s behind). The agent has NO access to any server and did not try; it prepared a bundle the owner runs himself: `collector/deploy/linux/` (config, systemd system + user units, Supervisor conf, install.sh, service installer, nohup start, stop / status helpers, pull + retention helpers, `README_DEPLOY.md`) and minimal portability fixes (SIGTERM / SIGINT / SIGBREAK clean stop, `stop --wait`, `~` and `$VAR` in `data_dir`, priority mode `unchanged`/`idle`, POSIX `pid_alive` EPERM fix, LF-only log lines). Data formats unchanged. The running Windows collector and its data were not touched (it keeps running the old code until the owner restarts or stops it). Agent recommendations recorded: **do not run it on the production trading server while it is under load (load 27-29 seen earlier)**; use a separate small VPS (1 vCPU / 1-2 GB RAM / 40 GB disk). If the owner insists on the production host: a dedicated non-root user, Nice 19 + CPUWeight 5 + CPUQuota 60 % + IO idle + MemoryMax 512 M, and watch load for the first day. Laptop data stays as dataset `laptop` (kept untouched), server data is dataset `server`; analysis concatenates on exchange event time `et` (aggTrade id for dedupe) with clock offsets from `clock.jsonl`, and the hole between them is marked as a gap, never filled. The D-045 pilot rule (descriptive only, no edge claim) is unchanged. Research only; no keys, no orders, no production DB; nothing committed.


## D-051 (2026-10-10): Rule Baseline v1 — executed under D-050, verdict recorded
Agent froze `RULE_BASELINE_V1.md` (sha256 adf5d5df...cefa25) before any result and ran it unattended; no amendment, no result-dependent change. Agent design choices (fixed before results): signal timeframe = the preset chart timeframe; breakout against previous-20-bar highest high / lowest low; EMA200 on closes; fractal k = 3 confirmed at j+3 via the existing `regime_v1.features.pivots`; stop distance floor 0.25 % / cap 1.50 % of price (cost argument; checked at the signal close and again at the entry price as a gap guard); 2 R target; expiry timeout; primary pool BTC/ETH/BNB/SOL (the live symbols), XRP/SUI report-only; matched random-side null with the rule's own R and entries; ablations NOEMA / NOSWING (1.5 ATR14) / PLAIN; sensitivities EMA100 and BRK50 counted in FDR (two families of 18); LEAD rule = v1 convention + materiality 0.03 % + BTC and ETH and >= 3 of 4 coins + all three years; per-preset verdict LEAD only if profit AND vs-null are LEAD. Disclosed: a distance-only feasibility look (no returns) before freezing; a BTC P1 smoke run after freezing (identical to the full run, no change). Verdict **FAIL**: no gross edge (+0.002..+0.007 % per trade vs a 0.12 % cost), all 36 items FAIL, powered (MDE <= 0.018 %). Labelled REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT; holdout reads 0. No live path / Laravel / Ratio Bot / Quick Trade code touched; nothing committed. The LLM-shadow design (`LLM_SHADOW_DESIGN_V1.md`, D-050 point 3) was written as a document only: no API call, no key, nothing run. Any follow-up needs a new owner decision.


## D-053 (2026-10-10): Experiment F narrowed to a Market Structure proof of concept; API / UI NOT built; verdict INCONCLUSIVE
Owner first asked for the full "Experiment F" (Python structure brain + read-only API + standalone research UI + replay + 4-arm backtest), then (newest instruction) re-scoped to a POC first: no API, no UI. Agent decisions (all in the plan `EXPERIMENT_F_POC_V1.md`, sha256 4e4ced03...a28, frozen before any real result): BTC primary (ETH / SOL report-only); pivots k 3 / 3 / 2 on 15m / 1h / 4h (confirmed at j + k); trend from the last two confirmed highs / lows; close-based BOS in a trending timeframe; HTF = last completed 4H and 1H bars; flat move excluded from accuracy; target = next open to horizon close at 15m (primary), 1h, 4h (secondary); NOHTF variant to answer whether alignment helps; 15 items in one BH-FDR family; PROMISING / NOT PROMISING / INCONCLUSIVE rules incl. MDE limits (2 pp accuracy, 0.06 % net). Result: INCONCLUSIVE (rule fires on 0.17 % of bars, 174 signals, under-powered) but substantively no edge (all point estimates negative, accuracy below chance, net -0.096 % at 15m). Not built / not run: API, UI, replay UI, four-arm backtest (`experiment_f/core/brain.py`, `job.py`, `soft.py`, arm constants in `params.py` were written before the re-scope, are untested on real data and unused; kept for a possible later phase). The engine core (`structure.py`) is tested. No existing file modified (manifest proof, 14,205 protected files), holdout reads 0, nothing committed, collector untouched. Follow-up needs a new owner decision; this POC gives no support for a larger structure system.

## 2026-10-10 � Trend Pullback v1 decision
Owner requested a 2022-2024 development and 2025-2026 forward test for the supplied Pine. Keep source defaults; do not tune. The forward result is negative after fees/funding, so this exact candidate is rejected for live use. Further work requires a new pre-registered hypothesis and fresh validation, not threshold selection on this forward window.
