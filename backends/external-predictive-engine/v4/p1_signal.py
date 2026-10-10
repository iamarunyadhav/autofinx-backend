"""P1 (D-036 roadmap) — is there a usable DIRECTION signal? Cheapest decisive diagnostic, development only.

Per symbol x horizon on the three D-025 folds inside dev_train (dev_val reserved, holdout sealed). Fixed V3
base schema (no feature selection that saw these folds). In each fold, using ONLY the fold's train rows:
REFERENCE exit levels (fixed SL / activation / trail) -> long and short success labels -> LightGBM + isotonic
(fit / calibration split purged by H). Out-of-fold predictions are then compared on the fold test rows where
BOTH sides' outcomes are known under the SAME exits:

  paired      chosen side (higher calibrated p) minus the opposite side, overall and by margin decile
  deciles     p_long decile -> realized long gross / net (and short)
  vol strata  paired lift by volatility tercile (a "big move either way" model looks good on AUC only)
  baselines   executed non-overlapping: never trade, always long, always short, 1h momentum side,
              model side (all rows), model side top margin decile; cost 0.12 / 0.15 / 0.18
  bootstrap   moving-block bootstrap (1-day blocks) 90 % interval of the paired mean difference

Nothing here selects a model or a threshold; it only measures. The P1 continue rule (D-036): proceed to P2
model work only if the paired lift is positive with its bootstrap interval above 0 in >= 2/3 folds, or a
concrete new hypothesis is written down.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import policy as pol
from .ablation import folds as d025_folds
from .lab import reference_levels
from .p5_run import Context
from .targets import MINUTE
from .train_v4 import COST, MIN_EDGE, auc, fit_classifier, predict

COSTS = (0.12, 0.15, 0.18)
DAY = 86_400_000


def block_bootstrap_ci(values: np.ndarray, times: np.ndarray, n_boot: int = 2000, seed: int = 11) -> list[float] | None:
    """90 % interval of the mean using whole-day blocks (keeps intraday dependence)."""
    if len(values) < 30:
        return None
    day = (times // DAY)
    uniq, inv = np.unique(day, return_inverse=True)
    sums = np.bincount(inv, weights=values)
    counts = np.bincount(inv)
    rng = np.random.default_rng(seed)
    k = len(uniq)
    idx = rng.integers(0, k, size=(n_boot, k))
    means = sums[idx].sum(axis=1) / np.maximum(counts[idx].sum(axis=1), 1)
    return [round(float(np.percentile(means, 5)), 5), round(float(np.percentile(means, 95)), 5)]


def executed(anchors, side_sign, net_long, net_short, exit_long, exit_short, cost_shift=0.0) -> dict:
    """Non-overlapping execution of +1 / -1 / 0 decisions; cost_shift adds extra round-trip cost."""
    take = side_sign != 0
    exit_ms = np.where(side_sign > 0, exit_long, exit_short)
    idx = pol.non_overlapping(anchors, take, exit_ms)
    if len(idx) == 0:
        return {"trades": 0}
    net = np.where(side_sign[idx] > 0, net_long[idx], net_short[idx]) - cost_shift
    std = net.std(ddof=1) if len(net) > 1 else 0.0
    return {"trades": int(len(net)), "avg_net_pct": round(float(net.mean()), 5),
            "t": round(float(net.mean() / (std / np.sqrt(len(net)))), 2) if std > 0 else None,
            "boot90": block_bootstrap_ci(net, anchors[idx])}


def _fold(c: Context, train_rows: np.ndarray, test: np.ndarray) -> dict:
    h, anchors = c.h, c.anchors
    order = train_rows[np.argsort(anchors[train_rows])]
    cut = order[int(len(order) * 0.8)]
    out = {"rows": {"train": int(len(order)), "test": int(len(test))}}
    sims, prob = {}, {}
    for s in ("long", "short"):
        ref = reference_levels(c.t, order, s, COST, MIN_EDGE)
        sim_tr = c.simulate(order, s, ref["sl"], ref["act"], ref["trail"])
        y = np.zeros(len(anchors), int); ok = np.zeros(len(anchors), bool)
        y[order] = (sim_tr["net_pct"] >= MIN_EDGE).astype(int)
        ok[order] = (sim_tr["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim_tr["same_bar_trail"]
        fit = order[(anchors[order] < anchors[cut] - h * MINUTE) & ok[order]]
        cal = order[(anchors[order] >= anchors[cut]) & ok[order]]
        booster, iso = fit_classifier(c.x, y, anchors, fit, cal)
        prob[s] = predict(booster, iso, c.x[test])
        sims[s] = c.simulate(test, s, ref["sl"], ref["act"], ref["trail"])
        out[f"{s}_reference_levels"] = {k: round(v, 4) for k, v in ref.items()}
    at = anchors[test]
    nl, ns = sims["long"]["net_pct"], sims["short"]["net_pct"]
    gl, gs = sims["long"]["gross_pct"], sims["short"]["gross_pct"]
    el, es = sims["long"]["exit_time_ms"], sims["short"]["exit_time_ms"]
    valid = np.isfinite(nl) & np.isfinite(ns)
    yl, ys = (nl >= MIN_EDGE).astype(int), (ns >= MIN_EDGE).astype(int)
    out["auc"] = {"long": auc(yl[valid], prob["long"][valid]), "short": auc(ys[valid], prob["short"][valid])}

    choose_long = prob["long"] >= prob["short"]
    chosen = np.where(choose_long, gl, gs)                 # gross: same exits, so costs cancel in the pair
    other = np.where(choose_long, gs, gl)
    diff = (chosen - other)[valid]
    margin = np.abs(prob["long"] - prob["short"])[valid]
    out["paired"] = {"n": int(valid.sum()), "mean_chosen_minus_other_gross": round(float(diff.mean()), 5),
                     "boot90": block_bootstrap_ci(diff, at[valid])}
    edges = np.quantile(margin, np.linspace(0, 1, 11))
    dec = np.clip(np.searchsorted(edges, margin, side="right") - 1, 0, 9)
    out["paired_by_margin_decile"] = [{"decile": d + 1, "n": int((dec == d).sum()),
                                       "mean_diff": round(float(diff[dec == d].mean()), 5) if (dec == d).any() else None}
                                      for d in range(10)]
    for s, p, g, n in (("long", prob["long"], gl, nl), ("short", prob["short"], gs, ns)):
        pv = p[valid]
        e = np.quantile(pv, np.linspace(0, 1, 11))
        dd = np.clip(np.searchsorted(e, pv, side="right") - 1, 0, 9)
        out[f"{s}_deciles"] = [{"decile": d + 1, "p_mean": round(float(pv[dd == d].mean()), 4) if (dd == d).any() else None,
                                "gross": round(float(g[valid][dd == d].mean()), 5) if (dd == d).any() else None,
                                "net": round(float(n[valid][dd == d].mean()), 5) if (dd == d).any() else None}
                               for d in range(10)]
    vol = c.sigma[test][valid]
    terc = np.quantile(vol[np.isfinite(vol)], [1 / 3, 2 / 3]) if np.isfinite(vol).any() else [np.nan, np.nan]
    out["paired_by_vol_tercile"] = []
    for k, (lo, hi) in enumerate(((-np.inf, terc[0]), (terc[0], terc[1]), (terc[1], np.inf))):
        m = (vol > lo) & (vol <= hi)
        out["paired_by_vol_tercile"].append({"tercile": k + 1, "n": int(m.sum()),
                                             "mean_diff": round(float(diff[m].mean()), 5) if m.any() else None})

    # baselines on the same test rows (executed, non-overlapping)
    g = c.grid
    i0 = ((at - int(g["start"])) // MINUTE).astype(np.int64)
    ok_m = (i0 - 60 >= 0) & (i0 - 1 < len(g["close"]))
    mom = np.zeros(len(at))
    mom[ok_m] = np.sign(g["close"][i0[ok_m] - 1] - g["close"][i0[ok_m] - 60])
    v = valid
    model_side = np.where(choose_long, 1, -1)
    top = np.zeros(len(at), bool)
    top_thr = np.quantile(np.abs(prob["long"] - prob["short"])[v], 0.9)
    top[v] = np.abs(prob["long"] - prob["short"])[v] >= top_thr
    arms = {"always_long": np.where(v, 1, 0), "always_short": np.where(v, -1, 0),
            "momentum_1h": np.where(v, mom, 0).astype(int), "model_side_all": np.where(v, model_side, 0),
            "model_side_top_margin_10pct": np.where(top, model_side, 0)}
    out["baselines"] = {"never_trade": {"trades": 0, "avg_net_pct": 0.0}}
    for name, side in arms.items():
        out["baselines"][name] = {f"cost_{cst}": executed(at, side, nl, ns, el, es, cst - COST) for cst in COSTS}
    return out


def run(work: Path, symbol: str, horizon: int, extra: str | None = None) -> dict:
    started = time.monotonic()
    c = Context(work, symbol, horizon, "arm2")
    if extra:
        from .optional_layers import _load_layer
        names, xl = _load_layer(work / "features" / symbol / f"{extra}.parquet", c.anchors)
        c.x = np.column_stack([c.x, xl])
        c.names = list(c.names) + names
    tr = np.nonzero(c.is_train)[0]
    report = {"symbol": symbol, "horizon_minutes": horizon, "features": c.feature_source + (f" + {extra}" if extra else ""),
              "data": "dev_train D-025 folds",
              "folds": [_fold(c, a, b) for a, b in d025_folds(c.anchors, tr, horizon)]}
    ok = [f for f in report["folds"] if f["paired"]["boot90"] and f["paired"]["boot90"][0] > 0]
    report["p1_rule"] = {"folds_with_positive_paired_lift_ci": len(ok),
                         "continue_to_model_work": len(ok) >= 2}
    from .runinfo import stamp
    report["seconds"] = round(time.monotonic() - started, 1)
    report["identity"] = stamp()
    out = work / "reports" / "p1"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}{'_' + extra if extra else ''}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
