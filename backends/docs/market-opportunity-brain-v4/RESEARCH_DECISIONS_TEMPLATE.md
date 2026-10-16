# Market Opportunity Brain research memory

New project only. Do NOT change the existing `docs/ml-engine-v1/DECISIONS.md` or research freeze to fit new experiments.

## DECISIONS.md (append only)

- `MOB-D-001`: initial boundary: research only, no execution, codebase fact references, date/reviewer.
- Each experimental decision: hypothesis, changed aspects, frozen evaluation period, reason, owner/agent decision, predecessors superseded (never silently overwrite).

## PROGRESS.md (updated each coding session)

- Last updated UTC, source commit/branch, phase gate, files changed, baseline tests, new tests, confirmed risks, blockers, next autonomous action, matters needing owner approval.

## RESULTS.md (append only)

- Experiment ID, preregistration hash, frozen config + dataset hash, source and timestamp convention, symbol universe snapshots, horizons, costs, sample counts, direction and calibration metrics, after-cost performance, confidence intervals and multiple-trial control, rejected candidates and exact failure reasons.

## CHANGELOG.md (append only)

- Filename, action CREATE/EDIT/DELETE, rationale, test added, review status. Protected-path modifications require stop and permission.

## PRODUCTION_STATUS.md

- `CODE_IMPLEMENTED`: yes/no; `UNIT_TESTED`: yes/no; `REAL_DATA_VALIDATED`: yes/no; `FORWARD_TESTED`: yes/no; `DEPLOYED`: **NO**; `LIVE_TRADING_CONNECTED`: **NO**. No extrapolating from local synthetic tests.
