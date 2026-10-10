"""Machine-readable REPORT.md for Quick Trade Replay v1 (numbers only; the interpretation lives in docs/ml-engine-v1/QUICK_TRADE_REPLAY_V1_RESULT.md)."""

from __future__ import annotations

import csv
from pathlib import Path


def read(path: Path):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def fnum(x, d=4):
    try:
        return f"{float(x):.{d}f}"
    except (TypeError, ValueError):
        return "-"


def table(rows, cols, heads=None):
    heads = heads or cols
    out = ["| " + " | ".join(heads) + " |", "|" + "---|" * len(cols)]
    for r in rows:
        out.append("| " + " | ".join(str(r.get(c, "")) for c in cols) + " |")
    return "\n".join(out)


def write(results: Path, summ: dict) -> None:
    items = read(results / "items.csv")
    L = ["# Quick Trade Replay v1 - machine report", "", f"plan sha256 {summ.get('plan_sha256')}", "", "## Counts", "", "```", str(summ["counts"]), "```", ""]
    L += ["LEAD items: " + (", ".join(summ["leads"]) if summ["leads"] else "none"), ""]
    L += ["## Random-direction null (family F2: arm A x exit x gate), BTC+ETH pooled, net at 0.12 %", "", "```", str(summ["random_null"]), "```", ""]
    L += ["## Best deltas (exit / gate vs X1 / G0 of the same direction arm)", ""]
    L += [table([{"effect": fnum(e), "id": i, "q": fnum(q), "verdict": v} for e, i, q, v in summ["best_deltas"]], ["effect", "id", "q", "verdict"]), ""]
    L += ["## Best difference-in-differences vs the matched random-direction null (family F4)", ""]
    L += [table([{"effect": fnum(e), "id": i, "q": fnum(q), "verdict": v} for e, i, q, v in summ.get("best_did", [])], ["effect", "id", "q", "verdict"]), ""]
    L += ["## Best absolute profit items", ""]
    L += [table([{"effect": fnum(e), "id": i, "q": fnum(q), "verdict": v} for e, i, q, v in summ["best_profit"]], ["effect", "id", "q", "verdict"]), ""]
    base = [r for r in items if r["family"] == "F1" and r["type"] == "P" and r["exit"] == "X1" and r["gate"] == "G0"]
    L += ["## Baselines X1 / G0 (BTC+ETH pooled, % per trade)", ""]
    L += [table([{"preset": r["preset"], "arm": r["arm"], "n": int(float(r["n"])), "gross": fnum(r["gross"]), "net_0.12": fnum(r["effect"]),
                  "net_0.14": fnum(r["effect_code"]), "mde": fnum(r["mde"]), "q": fnum(r["q"]), "verdict": r["verdict"], "by_year": r["by_year"]} for r in base],
                ["preset", "arm", "n", "gross", "net_0.12", "net_0.14", "mde", "q", "verdict", "by_year"]), ""]
    for kind, title in (("exit_delta", "Exit deltas"), ("gate_delta", "Gate deltas"), ("exit_did", "Exit DiD vs matched null"), ("gate_did", "Gate DiD vs matched null")):
        sel = [r for r in items if r["family"] in ("F1", "F4") and r["kind"] == kind]
        sel.sort(key=lambda r: (r["preset"], r["arm"], r["exit"], r["gate"]))
        L += [f"## {title} (effect % per trade, BTC+ETH pooled)", ""]
        L += [table([{"preset": r["preset"], "arm": r["arm"], "exit": r["exit"], "gate": r["gate"], "effect": fnum(r["effect"]), "ci90": f"[{fnum(r['ci90_lo'])}, {fnum(r['ci90_hi'])}]",
                      "mde": fnum(r["mde"]), "q": fnum(r["q"]), "verdict": r["verdict"]} for r in sel],
                    ["preset", "arm", "exit", "gate", "effect", "ci90", "mde", "q", "verdict"]), ""]
    (results / "REPORT.md").write_text("\n".join(L), "utf-8")
