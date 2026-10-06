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
