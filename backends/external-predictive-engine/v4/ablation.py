"""P4B — sequential, fold-consistent ablation of pattern feature groups G1..G6 (SPEC §102 A5, L1-L9).

Data: schema v4 features (v3 + G1-G6) exported from the PHP builder, V4 path labels (P3 REFERENCE
policy, worst-case evaluation, unresolved rows excluded, D-023). Folds live INSIDE dev_train only, so
dev_val stays untouched for P5 and the holdout stays sealed.

Pre-registered rule (D-025), fixed before any result is seen:
  folds        test windows at 55-70%, 70-85%, 85-100% of dev_train (expanding train, purge = H)
  score        mean of BUY and SELL classifier AUC on the fold test window (clean rows)
  keep group   median fold AUC gain >= MIN_GAIN, gain > 0 in >= 2 of 3 folds,
               mean Brier not worse by more than BRIER_TOLERANCE, total features <= FEATURE_CAP
  order        G1, G2, G3, G4, G5, G6 (fixed); a kept group becomes part of the next baseline
Economics (net after costs) is judged later with the frozen policy (P5/P6), not here.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import policy as pol
from . import splits
from .download import load_funding
from .lab import reference_levels
from .targets import MINUTE, VALID, dense_minutes
from .train_v4 import COST, MIN_EDGE, auc, fit_classifier, predict
from .verify import conflict_buckets

GROUPS = ("g1", "g2", "g3", "g4", "g5", "g6")
FOLD_STARTS = (0.55, 0.70, 0.85)
FOLD_LENGTH = 0.15
MIN_GAIN = 0.002
BRIER_TOLERANCE = 0.0005
FEATURE_CAP = 80


def folds(anchors: np.ndarray, train_idx: np.ndarray, horizon: int) -> list[tuple[np.ndarray, np.ndarray]]:
    """(fit+calibration rows, test rows) per fold, both inside dev_train, purge = horizon."""
    order = train_idx[np.argsort(anchors[train_idx])]
    n = len(order)
    out = []
    for start in FOLD_STARTS:
        test = order[int(n * start):int(n * min(start + FOLD_LENGTH, 1.0))]
        if len(test) == 0:
            continue
        cutoff = anchors[test[0]] - horizon * MINUTE
        out.append((order[anchors[order] < cutoff], test))
    return out


def keep_group(gains: list[float], brier_delta: float, n_features: int) -> tuple[bool, str]:
    if n_features > FEATURE_CAP:
        return False, f"feature cap {FEATURE_CAP} exceeded ({n_features})"
    if np.median(gains) < MIN_GAIN:
        return False, f"median AUC gain {np.median(gains):+.4f} < {MIN_GAIN}"
    if sum(g > 0 for g in gains) < 2:
        return False, "gain not positive in at least 2 of 3 folds"
    if brier_delta > BRIER_TOLERANCE:
        return False, f"calibration worse (Brier {brier_delta:+.5f})"
    return True, "kept"


def score(x, y_by_side, clean_by_side, anchors, fold_list) -> tuple[list[float], float]:
    aucs, briers = [], []
    for train_rows, test in fold_list:
        fold_auc, fold_brier = [], []
        for side in ("long", "short"):
            y, ok = y_by_side[side], clean_by_side[side]
            tr = train_rows[ok[train_rows]]
            cut = tr[int(len(tr) * 0.8)]
            fit = tr[anchors[tr] < anchors[cut]]
            cal = tr[anchors[tr] >= anchors[cut]]
            te = test[ok[test]]
            booster, iso = fit_classifier(x, y, anchors, fit, cal)
            p = predict(booster, iso, x[te])
            fold_auc.append(auc(y[te], p) or 0.5)
            fold_brier.append(float(np.mean((p - y[te]) ** 2)))
        aucs.append(float(np.mean(fold_auc)))
        briers.append(float(np.mean(fold_brier)))
    return aucs, float(np.mean(briers))


def run(work: Path, symbol: str, horizon: int) -> dict:
    import pyarrow.parquet as pq
    started = time.monotonic()
    feats = pq.read_table(work / "features" / symbol / "v4.parquet").to_pydict()
    names = [k for k in feats if k != "anchor_time_ms"]
    base = [n for n in names if not n.startswith(GROUPS)]
    groups = {g: [n for n in names if n.startswith(g + "_")] for g in GROUPS}
    f_anchor = np.asarray(feats["anchor_time_ms"], np.int64)
    t = {k: np.asarray(v) for k, v in pq.read_table(work / "targets" / symbol / f"h{horizon}.parquet").to_pydict().items()}
    anchors_all = t["anchor_time_ms"].astype(np.int64)
    pos = np.searchsorted(f_anchor, anchors_all)
    has = (pos < len(f_anchor)) & (f_anchor[np.minimum(pos, len(f_anchor) - 1)] == anchors_all)
    train_m, _ = splits.dev_train_val(anchors_all)
    rows = np.nonzero(has & (t["status"] == VALID) & train_m)[0]          # dev_train ONLY
    assert anchors_all[rows].max() < splits.holdout_start_ms(anchors_all)
    anchors = anchors_all[rows]
    matrix = {n: np.asarray(feats[n], float)[pos[rows]] for n in names}

    m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
    grid, funding, conflicts = dense_minutes(m1), load_funding(work, symbol), conflict_buckets(m1, m5)
    y, clean = {}, {}
    for side in ("long", "short"):
        ref = reference_levels(t, rows, side, COST, MIN_EDGE)
        sim = pol.simulate(None, anchors, horizon, side, ref["sl"], ref["act"], ref["trail"], COST, funding, conflicts, grid=grid)
        sim = pol.apply_worst_case(sim, grid, anchors, horizon, side, ref["sl"], ref["act"], ref["trail"], COST, funding)
        y[side] = (sim["net_pct"] >= MIN_EDGE).astype(int)
        clean[side] = (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"]

    fold_list = folds(anchors, np.arange(len(anchors)), horizon)
    current = list(base)
    x = np.column_stack([matrix[n] for n in current])
    base_auc, base_brier = score(x, y, clean, anchors, fold_list)
    report = {"symbol": symbol, "horizon_minutes": horizon, "rule": {"min_gain": MIN_GAIN, "brier_tolerance": BRIER_TOLERANCE,
              "feature_cap": FEATURE_CAP, "folds": FOLD_STARTS, "fold_length": FOLD_LENGTH},
              "baseline": {"features": len(base), "fold_auc": [round(a, 4) for a in base_auc], "brier": round(base_brier, 5)}, "steps": []}
    for g in GROUPS:
        candidate = current + groups[g]
        x = np.column_stack([matrix[n] for n in candidate])
        cand_auc, cand_brier = score(x, y, clean, anchors, fold_list)
        gains = [c - b for c, b in zip(cand_auc, base_auc)]
        kept, why = keep_group(gains, cand_brier - base_brier, len(candidate))
        report["steps"].append({"group": g, "features_added": len(groups[g]), "fold_auc": [round(a, 4) for a in cand_auc],
                                "fold_gain": [round(v, 4) for v in gains], "brier_delta": round(cand_brier - base_brier, 6),
                                "kept": kept, "reason": why})
        if kept:
            current, base_auc, base_brier = candidate, cand_auc, cand_brier
    report["selected_groups"] = [s["group"] for s in report["steps"] if s["kept"]]
    report["selected_feature_count"] = len(current)
    report["seconds"] = round(time.monotonic() - started, 1)
    out = work / "reports" / "p4b"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}.json").write_text(json.dumps(report, indent=1), "utf-8")
    return report
