"""P3 (D-036 roadmap, D-039 pre-registration) — predict the trade's economics directly.

Instead of P(success) x constant payoffs, LightGBM regressors predict the after-cost NET return of a fixed,
executable policy for each side, plus a downside quantile (q20). WAIT = 0 is an explicit option: trade only
the side whose predicted net is above 0. Development only (dev_train D-025 folds; dev_val reserved; holdout
sealed). Fixed V3 base schema (+ optional context layer), so no feature selection has seen the folds.

Policy (fixed, = P2 exit E1): stop 1.5 x ATR14(5m) at T0, no trailing, time exit at H. Worst-case
execution, cost from v4/costs.py (0.12 % default), funding included.
Per fold: fit on the fold's train rows (fit / calibration split purged by H; the calibration slice only
reports how predicted net maps to realised net), predict the fold test rows, then:
  deciles     predicted-net decile -> realised net (does a higher prediction mean a higher outcome?)
  spearman    rank correlation of predicted vs realised net per side
  arms        executed non-overlapping: PRED_GT_0 (best side if its prediction > 0),
              PRED_GT_0_AND_Q20 (also q20 > -stop), TOP10 (top 10 % of max prediction), ALWAYS_LONG baseline
Pass rule (D-039): PRED_GT_0 executed avg net > 0 with block-bootstrap CI90 above 0 in >= 2 of 3 folds.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import strategy_v13 as v13
from .ablation import folds as d025_folds
from .p1_signal import block_bootstrap_ci, executed
from .p5_run import Context
from .targets import MINUTE
from .train_v4 import COST, TREE, _weights

K_STOP = 1.5
NEVER = 1e6


def _atr_pct(c: Context) -> np.ndarray:
    """ATR14 of 5m bars at each anchor, in % of the close (bars up to and including the anchor bar)."""
    import pyarrow.parquet as pq
    from . import binance_data as bd
    from .targets import anchors_from_5m
    m5 = bd.load_klines(c.work, c.symbol, "5m")
    a = anchors_from_5m(m5)
    atr = v13.rma(v13.true_range(m5["high"], m5["low"], m5["close"]), 14) / m5["close"] * 100.0
    pos = np.searchsorted(a, c.anchors)
    ok = (pos < len(a)) & (a[np.minimum(pos, len(a) - 1)] == c.anchors)
    out = np.full(len(c.anchors), np.nan)
    out[ok] = atr[pos[ok]]
    return out


def _regressor(x, y, w, alpha=None):
    import lightgbm as lgb
    params = dict(TREE)
    if alpha is None:
        m = lgb.LGBMRegressor(objective="huber", alpha=1.0, **params)
    else:
        m = lgb.LGBMRegressor(objective="quantile", alpha=alpha, **params)
    return m.fit(x, y, sample_weight=w).booster_


def _fold(c: Context, train_rows, test, sims, stop):
    h, anchors = c.h, c.anchors
    order = train_rows[np.argsort(anchors[train_rows])]
    cut = order[int(len(order) * 0.8)]
    fit = order[anchors[order] < anchors[cut] - h * MINUTE]
    cal = order[anchors[order] >= anchors[cut]]
    out = {"rows": {"fit": int(len(fit)), "cal": int(len(cal)), "test": int(len(test))}}
    pred, q20 = {}, {}
    for s in ("long", "short"):
        y = sims[s]["net_pct"]
        f = fit[np.isfinite(y[fit])]
        w = _weights(anchors[f])
        mean_m = _regressor(c.x[f], y[f], w)
        q_m = _regressor(c.x[f], y[f], w, alpha=0.2)
        pred[s] = mean_m.predict(c.x[test])
        q20[s] = q_m.predict(c.x[test])
        pc = mean_m.predict(c.x[cal])
        yc = y[cal]
        ok = np.isfinite(yc)
        out[f"{s}_calibration"] = {"pred_mean": round(float(pc[ok].mean()), 5), "realised_mean": round(float(yc[ok].mean()), 5)}
        yt = y[test]
        okt = np.isfinite(yt)
        from scipy.stats import spearmanr
        out[f"{s}_spearman"] = round(float(spearmanr(pred[s][okt], yt[okt]).statistic), 4) if okt.sum() > 100 else None
        e = np.quantile(pred[s][okt], np.linspace(0, 1, 11))
        d = np.clip(np.searchsorted(e, pred[s][okt], side="right") - 1, 0, 9)
        out[f"{s}_deciles"] = [{"decile": k + 1, "pred": round(float(pred[s][okt][d == k].mean()), 4) if (d == k).any() else None,
                                "realised": round(float(yt[okt][d == k].mean()), 4) if (d == k).any() else None} for k in range(10)]
    at = anchors[test]
    nl, ns = sims["long"]["net_pct"][test], sims["short"]["net_pct"][test]
    el, es = sims["long"]["exit_time_ms"][test], sims["short"]["exit_time_ms"][test]
    valid = np.isfinite(nl) & np.isfinite(ns)
    best_long = pred["long"] >= pred["short"]
    best = np.where(best_long, pred["long"], pred["short"])
    best_q = np.where(best_long, q20["long"], q20["short"])
    side = np.where(best_long, 1, -1)
    st = stop[test]
    top = best >= np.quantile(best[valid], 0.9)
    arms = {"PRED_GT_0": valid & (best > 0), "PRED_GT_0_AND_Q20": valid & (best > 0) & (best_q > -st),
            "TOP10": valid & top, "ALWAYS_LONG": valid}
    out["arms"] = {}
    for name, m in arms.items():
        sgn = np.where(m, side if name != "ALWAYS_LONG" else 1, 0)
        out["arms"][name] = executed(at, sgn, nl, ns, el, es)
    out["share_pred_gt_0"] = round(float((valid & (best > 0)).mean() * 100), 3)
    return out


def run(work: Path, symbol: str, horizon: int, extra: str | None = None) -> dict:
    started = time.monotonic()
    c = Context(work, symbol, horizon, "arm2")
    if extra:
        from .optional_layers import _load_layer
        names, xl = _load_layer(work / "features" / symbol / f"{extra}.parquet", c.anchors)
        c.x = np.column_stack([c.x, xl])
    tr = np.nonzero(c.is_train)[0]
    stop = K_STOP * _atr_pct(c)
    rows = tr[np.isfinite(stop[tr])]
    sims = {}
    for s in ("long", "short"):
        sim = {"net_pct": np.full(len(c.anchors), np.nan), "exit_time_ms": np.full(len(c.anchors), -1, np.int64)}
        r = c.simulate(rows, s, stop[rows], np.full(len(rows), NEVER), np.full(len(rows), NEVER))
        sim["net_pct"][rows], sim["exit_time_ms"][rows] = r["net_pct"], r["exit_time_ms"]
        sims[s] = sim
    report = {"symbol": symbol, "horizon_minutes": horizon, "features": c.feature_source + (f" + {extra}" if extra else ""),
              "policy": f"stop {K_STOP} ATR, time exit H, cost {COST}", "data": "dev_train D-025 folds", "folds": []}
    for a, b in d025_folds(c.anchors, rows, horizon):
        report["folds"].append(_fold(c, a, b, sims, stop))
    good = [f for f in report["folds"] if (f["arms"]["PRED_GT_0"].get("boot90") or [-1])[0] > 0]
    report["d039_pass"] = len(good) >= 2
    from .runinfo import stamp
    report["seconds"] = round(time.monotonic() - started, 1)
    report["identity"] = stamp()
    out = work / "reports" / "p3"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}{'_' + extra if extra else ''}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
