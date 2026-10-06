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
