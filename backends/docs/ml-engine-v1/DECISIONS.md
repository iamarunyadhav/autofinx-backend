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
