# ml-engine-v1 — AutoFinX ML research agent pack

This folder is the **complete, tool-independent memory** of the AutoFinX ML engine work.
Any AI coding agent (Claude Code, Codex, ChatGPT, Cursor, a future tool) or a human can
continue the work from these files alone. Nothing important may live only in a chat.

- **Agent name:** `ml-engine-v1` (the first version of this agent)
- **What it builds now:** the **V4 path-aware ML pipeline** (target schema `qt-path-targets-v4`,
  policy `qt-dynamic-exit-v4`) — research / local training / shadow only.
- **Owner:** Arun (decides every phase gate). Agent never commits, pushes, deploys or installs.

## Files (read in this order)

| File | What it holds | Who updates it |
|---|---|---|
| [AGENT.md](AGENT.md) | Who the agent is, the non-negotiable rules, the work loop | Only when the owner changes the rules |
| [SPEC.md](SPEC.md) | The agreed V4 specification (numbered requirements) | Only with an owner-approved decision |
| [DECISIONS.md](DECISIONS.md) | Locked decisions with date + reason (append-only) | Agent, every time something is decided |
| [PROGRESS.md](PROGRESS.md) | Phase status, current gate, **next action**, open questions | Agent, at the end of every session |
| [CHANGELOG.md](CHANGELOG.md) | Every file created/changed/deleted, per session (append-only) | Agent, every session that touches files |
| [RESULTS.md](RESULTS.md) | Every experiment/training result with numbers (append-only) | Agent, after every run |

## How to start the agent in any tool

Give the tool this one instruction:

> Read `autofinx-backend/backends/docs/ml-engine-v1/AGENT.md` and follow it exactly.
> Then continue from the "Next action" in `PROGRESS.md`. Task: <what you want>.

- **Claude Code:** `/ml-engine-v1 <task>` (skill) or delegate to the `ml-engine-v1` subagent.
  Both are thin wrappers in `auto_finx/.claude/` that point here.
- **Codex / other agents:** the backend `AGENTS.md` points here.

## Where things live

- Backend repo (git): `autofinx-backend/backends` — PHP feature code, commands, tests, this folder.
- Python research code: `autofinx-backend/backends/external-predictive-engine/`.
- Local work data (NOT in git, large): `D:\autofinx-ml-v4\` (V4) and `D:\autofinx-ml\` (V3 offload runs).
- Production server: `root@62.72.45.72 -p 333`, app path
  `/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends`.
  V4 never needs the server.
