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
