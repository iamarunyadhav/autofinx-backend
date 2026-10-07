"""Stage B — P4B audit (owner plan 2026-10-07, PLAN_PROFITABILITY). Read-only over the P4B reports and the
feature files; no model is trained and no selection changes. Development data only (dev_train rows).

Outputs reports/p4b_audit/AUDIT.json + AUDIT.md:
  1. selection table per symbol x horizon (baseline fold AUC, kept groups, feature count)
  2. per group: cells kept / tried, median and spread of fold gains, how often a gain was positive in all folds
  3. overlap: for each group (G1-G6, G7-T, v13), the median over its columns of the max |Spearman rho| against
     every OTHER group's columns (high = mostly duplicate information), per symbol on a fixed dev_train sample
"""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np

from . import splits

SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")
HORIZONS = (15, 60, 120, 240, 1440)
GROUPS = ("g1", "g2", "g3", "g4", "g5", "g6")
SAMPLE_ROWS, SEED = 40_000, 7


def _ranks(x: np.ndarray) -> np.ndarray:
    out = np.empty_like(x)
    for j in range(x.shape[1]):
        col = x[:, j]
        order = np.argsort(col, kind="stable")
        r = np.empty(len(col))
        r[order] = np.arange(len(col))
        out[:, j] = r
    return out


def spearman_matrix(x: np.ndarray) -> np.ndarray:
    """Spearman rho between columns (rows with any NaN dropped)."""
    x = x[np.isfinite(x).all(axis=1)]
    if len(x) < 100:
        return np.full((x.shape[1], x.shape[1]), np.nan)
    r = _ranks(x)
    r = (r - r.mean(axis=0)) / np.where(r.std(axis=0) > 0, r.std(axis=0), 1.0)
    return (r.T @ r) / len(r)


def group_overlap(names: list[str], groups: dict[str, list[str]], x: np.ndarray) -> dict[str, float | None]:
    rho = np.abs(spearman_matrix(x))
    idx = {n: i for i, n in enumerate(names)}
    out = {}
    for g, cols in groups.items():
        others = [idx[n] for h, c in groups.items() if h != g for n in c]
        mine = [idx[n] for n in cols]
        if not mine or not others:
            out[g] = None
            continue
        best = np.nanmax(rho[np.ix_(mine, others)], axis=1)
        out[g] = round(float(np.nanmedian(best)), 3) if np.isfinite(best).any() else None
    return out


def _load(path: Path) -> dict[str, np.ndarray]:
    import pyarrow.parquet as pq
    return {k: np.asarray(v) for k, v in pq.read_table(path).to_pydict().items()}


def run(work: Path) -> dict:
    rep_dir = work / "reports" / "p4b"
    cells, per_group = [], {g: {"kept": 0, "tried": 0, "gains": [], "all_folds_positive": 0} for g in GROUPS}
    for s in SYMBOLS:
        for h in HORIZONS:
            p = rep_dir / f"{s}_h{h}.json"
            if not p.exists():
                cells.append({"symbol": s, "horizon": h, "status": "MISSING"})
                continue
            r = json.loads(p.read_text("utf-8"))
            cells.append({"symbol": s, "horizon": h, "baseline_auc": r["baseline"]["fold_auc"], "selected": r["selected_groups"],
                          "features": r["selected_feature_count"]})
            for st in r["steps"]:
                g = per_group[st["group"]]
                g["tried"] += 1
                g["kept"] += int(st["kept"])
                g["gains"].extend(st["fold_gain"])
                g["all_folds_positive"] += int(all(v > 0 for v in st["fold_gain"]))
    groups_summary = {g: {"kept": v["kept"], "tried": v["tried"],
                          "median_fold_gain": round(float(np.median(v["gains"])), 4) if v["gains"] else None,
                          "p10_p90_fold_gain": [round(float(np.percentile(v["gains"], q)), 4) for q in (10, 90)] if v["gains"] else None,
                          "cells_all_folds_positive": v["all_folds_positive"]} for g, v in per_group.items()}

    overlap = {}
    for s in SYMBOLS:
        fpath = work / "features" / s / "v4.parquet"
        if not fpath.exists():
            continue
        f = _load(fpath)
        anchors = f["anchor_time_ms"].astype(np.int64)
        train_m, _ = splits.dev_train_val(anchors)
        rows = np.nonzero(train_m)[0]
        rng = np.random.default_rng(SEED)
        rows = np.sort(rng.choice(rows, size=min(SAMPLE_ROWS, len(rows)), replace=False))
        cols = {n: np.asarray(v, float)[rows] for n, v in f.items() if n != "anchor_time_ms"}
        groups = {"base": [n for n in cols if not n.startswith(GROUPS)]}
        groups.update({g: [n for n in cols if n.startswith(g + "_")] for g in GROUPS})
        for extra in ("g7t", "v13"):
            ep = work / "features" / s / f"{extra}.parquet"
            if ep.exists():
                e = _load(ep)
                pos = np.searchsorted(e["anchor_time_ms"].astype(np.int64), anchors[rows])
                pos = np.minimum(pos, len(e["anchor_time_ms"]) - 1)
                names = [n for n in e if n != "anchor_time_ms"]
                for n in names:
                    cols[n] = np.asarray(e[n], float)[pos]
                groups[extra] = names
        names = [n for g in groups.values() for n in g]
        x = np.column_stack([cols[n] for n in names])
        overlap[s] = group_overlap(names, groups, x)

    report = {"cells": cells, "groups": groups_summary, "overlap_median_max_abs_spearman": overlap,
              "sample": {"rows_per_symbol": SAMPLE_ROWS, "seed": SEED, "data": "dev_train only"}}
    out = work / "reports" / "p4b_audit"
    out.mkdir(parents=True, exist_ok=True)
    (out / "AUDIT.json").write_text(json.dumps(report, indent=1), "utf-8")
    (out / "AUDIT.md").write_text(markdown(report), "utf-8")
    return report


def markdown(r: dict) -> str:
    lines = ["# P4B audit (Stage B, development data only)", "", "## Selection per symbol x horizon", "",
             "| Symbol | " + " | ".join(f"h{h}" for h in HORIZONS) + " |", "|---|" + "---|" * len(HORIZONS)]
    for s in SYMBOLS:
        row = []
        for h in HORIZONS:
            c = next(x for x in r["cells"] if x["symbol"] == s and x["horizon"] == h)
            row.append("missing" if c.get("status") == "MISSING" else ("+".join(c["selected"]) or "none"))
        lines.append(f"| {s[:-4]} | " + " | ".join(row) + " |")
    lines += ["", "## Groups", "", "| Group | kept / tried | median fold gain | p10..p90 | cells all folds positive |", "|---|---|---|---|---|"]
    for g, v in r["groups"].items():
        lines.append(f"| {g} | {v['kept']} / {v['tried']} | {v['median_fold_gain']} | {v['p10_p90_fold_gain']} | {v['cells_all_folds_positive']} |")
    lines += ["", "## Overlap (median over a group's columns of max |Spearman| vs other groups; 1 = duplicate)", ""]
    groups = sorted({g for o in r["overlap_median_max_abs_spearman"].values() for g in o})
    lines += ["| Symbol | " + " | ".join(groups) + " |", "|---|" + "---|" * len(groups)]
    for s, o in r["overlap_median_max_abs_spearman"].items():
        lines.append(f"| {s[:-4]} | " + " | ".join(str(o.get(g)) for g in groups) + " |")
    return "\n".join(lines) + "\n"
