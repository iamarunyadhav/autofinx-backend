# CHANGELOG (append-only)

One line per file created / changed / deleted, grouped by session, with the reason.
Paths are relative to `autofinx-backend/backends` unless they start with `auto_finx/`.
The owner commits; this log says what is in the working tree, not what is committed.

---

## 2026-10-06 — ML offload to PC + V4 agent pack

ML training infra (V3, before the agent existed — recorded here for history):
- `app/Domain/Ml/Shadow/MlShadowTrainer.php` — export heartbeat (`exportBeat`) so a live export is not
  reaped; export reads keyset pages of 2000 rows instead of a buffered `cursor()` (memory).
- `app/Domain/Ml/Training/TrainingReaper.php`, `TrainingOverviewService.php`,
  `app/Http/Controllers/Api/MlTrainingController.php`, `config/ml.php` — phase-aware stall limit
  (`export_stall_seconds`, default 1800); `config/ml.php` also `AUTOFINX_ML_EXPORT_DIR`.
- `app/Domain/Ml/Training/MlDoctor.php`, `app/Console/Commands/MlTrainingBusyCommand.php` (new),
  `ml-doctor.sh`, `ml-train-batch.sh` — busy check, concurrency / retry-storm warnings.
- `disk-doctor.sh` (new), `ml-fix-all.sh` (new) — server disk report/safe cleanup, one-command
  server health + training orchestration.
- `app/Console/Commands/MlOffload{Settings,Export,Import,Pack,Install}Command.php` (new) — settings
  copy, server data stream (too slow on the loaded server, kept as fallback), local import, pack,
  checksum-verified install that never replaces an existing champion.
- `ml-offload.ps1` (new) — PC-side V3 training runner (Binance direct, isolated sqlite on D:).
- Tests: `tests/Feature/Ml/MlTrainingExportHeartbeatTest.php`, `tests/Feature/Ml/MlOffloadTest.php`
  (new), `tests/Feature/Ml/MlTrainingApiTest.php` (contract key added).

Agent pack (this session):
- `docs/ml-engine-v1/README.md`, `AGENT.md`, `SPEC.md`, `DECISIONS.md`, `PROGRESS.md`,
  `CHANGELOG.md`, `RESULTS.md` (new) — tool-independent memory of the ML engine work (D-010).
- `AGENTS.md` — added a pointer to `docs/ml-engine-v1/AGENT.md` for non-Claude agents.
- `auto_finx/.claude/skills/ml-engine-v1/SKILL.md` (new) — `/ml-engine-v1` slash command wrapper.
- `auto_finx/.claude/agents/ml-engine-v1.md` (new) — subagent wrapper. (Outside git: the
  `auto_finx/` root is not a repository; the pack in `docs/` is the source of truth.)

Spec completion (same day, later session):
- `docs/ml-engine-v1/SPEC.md` — added §74–101, replaced the pending note and the old C10 phase list
  with the reconciled §101 table; status line now "complete".
- `docs/ml-engine-v1/AGENT.md` — phase gates point to §101; priority order §98 added.
- `docs/ml-engine-v1/DECISIONS.md` — D-012 (phase reconciliation), D-013 (smoke test + order).
- `docs/ml-engine-v1/PROGRESS.md` — P1 plan as the next action; open questions narrowed.

Pattern intelligence review (same day, session c):
- `docs/ml-engine-v1/SPEC.md` — §102 Causal Pattern Intelligence (P4A/P4B, groups G1–G8,
  adjustments A1–A6), status PROPOSED.
- `docs/ml-engine-v1/RESULTS.md` — R-005 feature inventory + Binance metrics availability.
- `docs/ml-engine-v1/PROGRESS.md` — gate now "owner validates final plan"; proposed final plan.

Plan lock (same day, session d):
- `docs/ml-engine-v1/SPEC.md` — §102 status LOCKED, constraints L1–L9 + ablation keep rule.
- `docs/ml-engine-v1/DECISIONS.md` — D-014 (§102 locked), D-015 (final phase order).
- `docs/ml-engine-v1/PROGRESS.md` — gate "go P1", P1 step 5 G7 coverage audit, P4A/P4B rows.

## 2026-10-06 — P1 implementation (session e)
- `ml-server-pause.sh` (new) — production ML pause, dry-run by default: stops ML batch scripts, sets
  `AUTOFINX_ML_SHADOW_ENABLED=false` (with .env backup) + config:cache + scheduler restart, stops the
  ML training worker and sets autostart=false, removes ML temp/offload files; `--free-disk` empties
  ml_feature_rows / ml_labels / ml_shadow_predictions. Trading code does not read ML tables (verified:
  only MlShadowController and ML jobs do).
- `external-predictive-engine/v4/__init__.py` (new) — package, schema/policy names.
- `external-predictive-engine/v4/binance_data.py` (new) — HTTP with backoff + 404 handling, sha256
  checksum verification, CSV parsing (header/no header, µs→ms), atomic Parquet, manifest, calendar.
- `external-predictive-engine/v4/download.py` (new) — resumable monthly/daily/REST-tail klines,
  funding (bulk + REST), G7 metrics; progress + ETA; STOP marker; never marks the last 2 months
  "not listed".
- `external-predictive-engine/v4/verify.py` (new) — integrity, gaps, coverage, 1m→5m alignment,
  funding and G7 audits; verdict OK/WARN/FAIL; nothing repaired or interpolated.
- `external-predictive-engine/v4/cli.py` (new) — `python -m v4.cli download|verify|status`.
- `external-predictive-engine/requirements-v4.txt` (new) — pinned lightgbm 4.7.0, numpy 2.2.6,
  scikit-learn 1.7.2, pyarrow 25.0.1, pytest 9.1.1 (local only).
- `external-predictive-engine/tests/v4/test_data_layer.py` (new) — 11 tests.
- `ml-v4-local.ps1` (new) — runner: CHECK, VENV, DOWNLOAD, VERIFY (P2+ stages later), resume,
  -Months smoke override, -DownloadOnly/-VerifyOnly/-Refetch/-NoMetrics, STOP marker, logs.
- Local only (not in git): `D:\autofinx-ml-v4\venv`, `data\`, `reports\`, `logs\`.
- `external-predictive-engine/v4/download.py` — monthly klines download with 4 parallel workers
  (manifest written only by the main thread); metrics stage honours STOP for queued work.

## 2026-10-06 — P2 Layer A + feature export (session f)
- `external-predictive-engine/v4/targets.py` (new) — chunked vectorized Layer A path truth
  (MFE, MAE, timings, MAE-before-profit, profit-bar ambiguity, peak retracement, time-exit gross/net,
  funding), GAP / OUT_OF_RANGE status, per-horizon Parquet + JSON metadata.
- `external-predictive-engine/v4/features.py` (new) — 5m candles → isolated sqlite, export of PHP-built
  v3 features and V3 labels to Parquet (no Python feature code, §72).
- `external-predictive-engine/v4/cli.py` — commands `targets`, `import-candles`, `export-features`.
- `external-predictive-engine/tests/v4/test_targets.py` (new) — 13 tests incl. reference comparison.
- `ml-v4-local.ps1` — stages BUILD_5M_FEATURES (PHP via env overrides) and BUILD_PATH_TARGETS;
  options -Horizons, -TargetsOnly, -SkipFeatures, -ForceRebuildFeatures, -ForceRebuildTargets;
  features/targets are not built when VERIFY fails.
- Local only: `D:\autofinx-ml-v4\features\`, `targets\`.

## 2026-10-07 — P1 result + source-conflict handling (session g)
- `external-predictive-engine/v4/verify.py` — `conflict_buckets()`: 5m buckets where Binance 1m and 5m
  archives disagree.
- `external-predictive-engine/v4/targets.py` — status `SOURCE_CONFLICT` (3) for anchors whose own
  5m candle or path touches a conflict bucket; conflict list stored in each target JSON.
- `external-predictive-engine/tests/v4/test_targets.py` — conflict test (25 tests total).

## 2026-10-07 — P3 start (session h; owner said "go P3")
- `external-predictive-engine/v4/splits.py` (new) — holdout / dev / dev_train / dev_val with purge (D-016).
- `external-predictive-engine/v4/policy.py` (new) — Layer B simulator (hard stop, activation, trailing,
  time exit, conservative same-bar rules, gap fills, funding to exit), non-overlapping execution,
  §62 trade statistics.
- `external-predictive-engine/v4/targets.py` — `entry_status()` shared by Layer A and Layer B.
- `external-predictive-engine/v4/lab.py` (new) — P3 label audit, path distributions (incl. by
  volatility regime), policy lab with pre-declared baselines; asserts no holdout rows.
- `external-predictive-engine/v4/cli.py` — command `p3` (+ `reports/p3/SUMMARY.md`).
- Tests: `tests/v4/test_policy.py` (16), `tests/v4/test_splits.py` (1).
- `external-predictive-engine/v4/resolver.py` (new) — sampled aggTrade resolver (D-020): streams
  official daily aggTrades zips for random dev days, keeps only ambiguous minutes, re-simulates trades
  tick by tick, reports outcome changes and P&L bias of the conservative 1m rule.
- `external-predictive-engine/v4/cli.py` — command `resolve --days N`.
- `external-predictive-engine/tests/v4/test_resolver.py` (new, 3 tests; 45 V4 tests total).
- `docs/ml-engine-v1/DECISIONS.md` — D-018 (splits confirmed), D-019, D-020 (resolver method).
- `external-predictive-engine/v4/binance_data.py` — retries also on `http.client.HTTPException`
  (IncompleteRead seen on a 22 MB aggTrades file); `resolver.py` skips and records a failed day.

## 2026-10-07 — P4A (session i; owner said "go P4A")
- `external-predictive-engine/v4/train_v4.py` (new) — per symbol × horizon on dev data: V3 and V4
  BUY/SELL classifiers (same tree settings, recency weights, isotonic calibration on the last 20% of
  dev_train with purge), MFE/MAE quantile models, dev_val comparison ARM1_V3 / V3 signal + new exits /
  ARM2_V4 / always-take baselines, plus EV-only diagnostic arms. Models in
  `D:\autofinx-ml-v4\models\<SYMBOL>\h<H>\`, reports in `reports\p4a\`. Status P4A_DEV_ONLY_NOT_FROZEN.
- `external-predictive-engine/v4/cli.py` — command `p4a`.

## 2026-10-07 — Review corrections D-023 (session j)
- `v4/policy.py` — `worst_case_path()` + `apply_worst_case()` (both intrabar orders, lower net kept);
  drawdown key renamed `max_drawdown_additive_pct`; docstring corrected.
- `v4/lab.py` — worst-case applied to every policy run; label audit treats same-bar rows as ambiguous.
- `v4/train_v4.py` — sklearn AUC; worst-case path outcomes; V4 labels exclude unresolved rows;
  EV-only diagnostic arms; report field `v4_unresolved_excluded_pct`.
- `v4/resolver.py` — resimulate returns the exit bar; funding recomputed to the resolved exit;
  baseline uses worst-case evaluation.
- `tests/v4/test_policy.py` — 3 worst-case tests; `tests/v4/test_resolver.py` updated (49 tests).

## 2026-10-07 — P4B code prepared + stratified resolver (session k)
- `app/Domain/Ml/Shadow/PatternFeatures.php` (new) — G1–G6 causal pattern features (57 features; earlier "60" was a miscount):
  confirmed swings (SWING_K = 3, L6), candle shape/sequence, RSI/MACD/EMA slopes on fixed 200-bar
  windows, ATR/Bollinger squeeze, 15m/1h/4h bars from COMPLETE UTC buckets only (L5), volume/taker.
- `app/Domain/Ml/Shadow/ShadowFeatureBuilder.php` — research schema `qt-shadow-features-v4` = v3 + G1–G6;
  `SCHEMA_VERSION` stays v3 (server unchanged).
- `app/Domain/Ml/Shadow/FeatureLabelService.php` + `config/ml.php` — opt-in
  `AUTOFINX_ML_EXTRA_FEATURE_SCHEMAS` (default empty) so only the local V4 PC builds v4.
- `tests/Unit/Ml/PatternFeaturesTest.php` (new, 5 tests: append-only + finite, L6 swing confirmation,
  L5 forming bucket never read, K leakage via FeatureLabelService, not built unless configured).
  With `ShadowFeatureLabelTest`: 14/14 pass. Full backend suite still to run when the CPU is free.
- `external-predictive-engine/v4/resolver.py` — stratified day sampling (year × volatility tercile) and
  per-stratum report (D-024); `tests/v4/test_resolver.py` +1 test (50 V4 tests).
- `external-predictive-engine/v4/ablation.py` (new) — P4B fold-consistent sequential ablation (D-025).
- `external-predictive-engine/v4/features.py` — `export(..., schema)`; v4 → `features/<SYMBOL>/v4.parquet`.
- `external-predictive-engine/v4/cli.py` — command `p4b`, option `--schema`.
- `external-predictive-engine/tests/v4/test_ablation.py` (new, 2 tests; 52 V4 tests total).
- `ml-v4-local.ps1` — switch `-PatternFeatures` builds + exports schema v4 (opt-in env only for that
  process). Measured builder cost: v3 0.78 ms vs v4 4.48 ms per anchor (≈40 min per 5-year coin).

## 2026-10-07 — Dynamic SL / trailing targets + manifest (session l, D-026)
- `external-predictive-engine/v4/targets.py` — new Layer A field `pullback_before_continuation_pct`
  (long/short); docstring definitions.
- `external-predictive-engine/tests/v4/test_targets.py` — reference implementation extended to the new
  field + a dedicated pullback test (53 V4 tests).
- `external-predictive-engine/v4/train_v4.py` — conditional quantile models for
  `max_adverse_before_profit_pct` (dynamic SL) and `pullback_before_continuation_pct` (dynamic trailing),
  with fit/val row counts; skipped with a note if the column is missing.
- Targets rebuilt for 6 coins × 5 horizons to add the column (existing columns unchanged).
- `docs/ml-engine-v1/FEATURES_V4.md` (new) — feature manifest; `SPEC.md` §101 P5 row now includes the
  final retrain under the frozen policy; `DECISIONS.md` D-026.

## 2026-10-07 — P5 design locked + building blocks (session m, D-028)
- `docs/ml-engine-v1/SPEC.md` §103 rewritten as LOCKED (owner review); `DECISIONS.md` D-028;
  `PROGRESS.md` split-fraction question marked resolved (D-018), P5 next step.
- `external-predictive-engine/v4/p5.py` (new) — pure P5 rules: SL cap (Layer A, ambiguity excluded),
  quantile quality gate (pinball vs constant, coverage 40-60%, crossing repair + rate), 12-policy exit
  family with shared multipliers, dynamic levels with first-failing WAIT reason (reward floor, cap,
  activation floor, consistency, uncertainty, OOD), EV/lift/conflict decision, nested P5-A/B/C windows,
  selection rule (min trades, 2/3 sub-folds, PF, t-stat) + simplicity tie-break, forward-chaining blocks.
- `external-predictive-engine/tests/v4/test_p5.py` (new, 11 tests incl. SPEC §78 M, N, O); 64 V4 tests.
- `external-predictive-engine/v4/p5_run.py` (new) — P5 orchestration per symbol × horizon: models on
  dev_train, SL caps / uncertainty limit / OOD reference from dev_train, quality gate on P5-A, exit
  policy (P5-A), gate (P5-B), confirmation (P5-C), REJECTED paths, forward-chaining final retrain under
  the frozen policy, artifacts with sha256, report `reports/p5/<SYMBOL>_h<H>.json`. EV params cached
  per candidate; worst-case evaluation everywhere.
- `external-predictive-engine/v4/cli.py` — command `p5`.
- Layer A targets rebuilt for all 6 coins × 5 horizons with `pullback_before_continuation_pct`.

## 2026-10-07 — P5 smoke h60/h240 + EV consistency (session m, D-029 proposed)
- `external-predictive-engine/v4/p5_run.py` — per-candidate isotonic re-calibration of the classifier
  score inside `ev_params` (cached per policy × side); `decide` lift uses the candidate base rate; stage
  reports gain WAIT-reason counts and EV diagnostics (`*_ev_params` [avg win, avg other, base rate],
  `*_p_mean_tradeable`, `*_ev_max_tradeable`, `*_ev_ge_gate`).
- V4 tests 64/64. RESULTS R-017; DECISIONS D-029 (proposed).
- RESULTS R-013 (P3 all coins), R-014 (BTC resolver), R-015 (P4A h15–h120 all coins). `D:utofinx-ml-v4  run-p4a-resume.sh` resumes P4A after the chain was killed by a Windows power-off event.
- R-015 completed with h120 (XRP/SUI), h240 and h1440 for all coins (30/30). `D:utofinx-ml-v4\status.ps1`
  (read-only progress check) added.
- Full backend suite after the PatternFeatures PHP changes: 2068 passed, 1 skipped (345 s).
- Owner "go P4B": `D:utofinx-ml-v4
un-p4b.sh` (pattern features → ablation BTC/ETH 15m first, then all).
- D-029 marked KEPT (owner delegated the decision).
- `docs/ml-engine-v1/strategy-lab/README.md` (new): owner Strategy Lab / Tradeability proposal summary, agent
  review (7 corrections), proposed order. Nothing built.
- Strategy Lab candidate #1 (owner Pine "AutoFinX v13"): `strategy-lab/candidates/autofinx-v13/` (source.pine
  verbatim + sha256, AUDIT.md); `external-predictive-engine/v4/strategy_v13.py` (Pine-semantics translation,
  S0 simulation, S1 feature export); `tests/v4/test_strategy_v13.py` (12 tests); CLI `lab-v13`;
  RESULTS R-019 (S0 REJECTED_NO_VALUE, 24/24 cells negative). Features: `D:/autofinx-ml-v4/features/<SYM>/v13.parquet`.
- D-030 (owner): optional independent layers. `external-predictive-engine/v4/optional_layers.py` (new: G7-T
  noise/chop features, FEATURE / WAIT / RULE evaluation OFF vs ON on dev_train folds), CLI `export-g7t`,
  `layers`; `tests/v4/test_optional_layers.py` (4 tests; V4 suite 80/80). `D:/autofinx-ml-v4/run-layers.sh`.
- SPEC §104 / §105; D-031 (Strategy Lab). Backend: `config/strategy_lab.php`, migration
  `2026_10_13_000000_create_strategy_lab_tables.php` (NOT applied), models StrategyLabCandidate / Version / Run /
  Approval, `app/Domain/StrategyLab/` (PineAuditor, StrategyLabService, StrategyLabRunner, StrategyLabException),
  `app/Jobs/RunStrategyLabTestJob.php`, `Api/StrategyLabController.php`, routes `workspaces/{ws}/strategy-lab/*`,
  `.env.example` entries, `tests/Feature/StrategyLab/StrategyLabApiTest.php` (11 tests). Python:
  `v4/lab_runner.py` (QUICK_V1 / DEEP_V1, scorecard, verdict rule), `v4/strategy_v13.py` (load_sealed, s0_trades,
  trade prices), `tests/v4/test_lab_runner.py` (V4 suite 84/84).
- Strategy Lab frontend (autofinx-front/frontends): `src/lib/strategyLab.ts`, `src/hooks/useStrategyLab.ts`,
  `src/components/strategy-lab/` (LabShell, SourceInput, NewCandidateDialog, RunPanel, ResultView, LabChart),
  `src/pages/strategy-lab/` (StrategyLabPage, CandidatePage); routes `/strategy-lab`, `/strategy-lab/:candidateId`
  in App.tsx; module `strategy-lab` in module-access.ts; sidebar icon. `npm run build` OK, eslint 0 errors (29
  pre-existing warnings, none new). Not yet clicked through live (migration pending). Controller `cancel` now
  wrapped in guard().
- `v4/p5_run.py`: `arm` parameter (arm3 primary / arm2 V3-features benchmark, report suffix `_arm2`), feature
  names in the report, `decision.json` in the final-retrain dir (isotonic map, EV params, base rate, OOD
  mean/std, caps, uncertainty limit) with sha256; CLI `--arm`. V4 tests 84/84.
- `docs/ml-engine-v1/STATUS_REPORT_2026-10-07.md` (owner full status). `D:/autofinx-ml-v4/run-p4b-p5.sh`
  (missing P4B → layers → P5, per-process, memory guard). PROGRESS rewritten to the current state.
- `PLAN_PROFITABILITY_2026-10-07.md` (owner plan review: mapping to code, 5 corrections). Runner restarted detached
  (Start-Process) so it survives a Claude session end.
- G7-T +4 features (flip_48, range_pos_48, vol_confirm_12, persistence_12) in `v4/optional_layers.py`, tests;
  g7t.parquet re-exported (15 columns). `v4/p4b_audit.py` (Stage B: selection table, group gain stats,
  Spearman overlap incl. G7-T / v13) + CLI `p4b-audit` + test. V4 suite 86/86. DECISIONS D-033, D-035 PROPOSED.
- RESULTS R-018 (P4B 30 cells + audit), R-020 (optional layers h15). `CROSSCHECK_HANDOFF_2026-10-07.md` (full
  handoff for an independent reviewer).
- D-035 C1 maker entry: `v4/execution.py` (trade-through fills, 0.08 %), `policy.simulate` / `apply_worst_case` /
  `worst_case_path` optional entry price (default unchanged); `tests/v4/test_execution.py`.
- D-033 TAKE/SKIP: `v4/meta_label.py` (forward-chained base/meta/test inside the D-025 folds, C0 vs C1 arms,
  verdict), CLI `meta`. D-030 WAIT verdict revised (retention vs keep share). V4 suite 90/90.
