"""Stage E (D-033) TAKE/SKIP meta-label + Stage G (D-035) execution-cost comparison, development only.

Per symbol x horizon, on the three D-025 folds INSIDE dev_train (dev_val stays reserved for P5, the
holdout stays sealed). Inside each fold's train rows, forward-chained:
    base part (first 60 %)  -> direction classifiers (REFERENCE labels) + MFE/adverse/pullback quantiles,
                               SL cap, uncertainty limit, OOD stats, candidate-policy calibration (D-029)
    meta part (last 40 %, purged by H) -> base models predict out of sample -> proposals -> meta model fit
    fold test rows          -> base + meta models predict out of sample -> evaluation
Exit policy fixed in advance: a1.0 / b0.8 / vol trailing (D-033: the default when nothing was selected).

Proposal: an anchor where at least one side passes the P5 level gates (reason 0); the side with the higher
candidate EV is proposed. Meta label: that candidate trade's worst-case net >= MIN_EDGE (ambiguous rows kept
in economics, excluded from fitting). Arms on the fold test proposals, executed non-overlapping:
    OFF          every proposal
    EV_GATE      P5-style: EV >= MIN_EDGE
    META_50/30   proposals whose meta score is in the top 50 % / 30 % of the meta-part scores
and each under C0 (taker, 0.12 %) and C1 (maker entry with trade-through fills, 0.08 %, missed fills = no trade).
Verdict (D-033 as amended, D-030 rev): USEFUL if the executed avg net improves vs OFF by >= 0.01 %/trade
in >= 2/3 folds AND winners are kept at least in proportion (profit retention >= trade retention);
PROFITABLE if, in addition, the arm's executed avg net is > 0 in >= 2/3 folds.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import p5
from . import policy as pol
from .ablation import folds as d025_folds
from .execution import COST_C1, simulate_c1
from .lab import reference_levels
from .optional_layers import _load_layer, wait_metrics
from .p5_run import QUANT_TARGETS, Context, _fit_quantiles, _predict_q
from .targets import MINUTE
from .train_v4 import COST, MIN_EDGE, TREE, _weights, fit_classifier

POLICY = p5.ExitPolicy(1.0, 0.8, "vol")
BASE_SHARE, KEEP = 0.60, (0.5, 0.3)
MIN_IMPROVEMENT = 0.01


def verdict(fold_metrics: list[dict]) -> str:
    """D-033 / D-030 rev rule on wait_metrics() dicts (OFF vs ON executed avg net per fold)."""
    better = 0
    positive = 0
    for m in fold_metrics:
        off, on = m["executed_off"]["avg_net_pct"], m["executed_on"]["avg_net_pct"]
        if off is None or on is None:
            continue
        proportional = (m["profit_retention_pct"] or 0.0) >= m["trade_retention_pct"]
        if on - off >= MIN_IMPROVEMENT and proportional:
            better += 1
        if on > 0:
            positive += 1
    if better < 2:
        return "NO_VALUE"
    return "PROFITABLE" if positive >= 2 else "USEFUL"


def _fold(c: Context, g7t: np.ndarray, train_rows: np.ndarray, test: np.ndarray) -> dict:
    import lightgbm as lgb
    from sklearn.isotonic import IsotonicRegression
    h, anchors = c.h, c.anchors
    order = train_rows[np.argsort(anchors[train_rows])]
    split = int(len(order) * BASE_SHARE)
    base, meta = order[:split], order[split:]
    meta = meta[anchors[meta] >= anchors[base].max() + h * MINUTE]
    cut = int(len(base) * 0.8)
    bfit = base[:cut]
    bfit = bfit[anchors[bfit] < anchors[base[cut]] - h * MINUTE]
    bcal = base[cut:]

    clf, quant, cap = {}, {}, {}
    for s in ("long", "short"):
        ref = reference_levels(c.t, base, s, COST, MIN_EDGE)
        sim = c.simulate(base, s, ref["sl"], ref["act"], ref["trail"])
        y = np.zeros(len(anchors), int)
        y[base] = (sim["net_pct"] >= MIN_EDGE).astype(int)
        ok = np.zeros(len(anchors), bool)
        ok[base] = (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"]
        clf[s] = fit_classifier(c.x, y, anchors, bfit[ok[bfit]], bcal[ok[bcal]])[0]
        quant[s] = {k: _fit_quantiles(c.x, c.t[f"{s}_{col}"], bfit, anchors) for k, col in QUANT_TARGETS.items()}
        cap[s] = p5.sl_cap(c.t[f"{s}_max_adverse_before_profit_pct"][base], c.t[f"{s}_profit_bar_ambiguous"][base])
    mean, std = np.nanmean(c.x[bfit], axis=0), np.nanstd(c.x[bfit], axis=0)

    def base_predict(rows):
        out = {}
        z = np.abs((c.x[rows] - mean) / np.where(std > 0, std, 1.0))
        ood_share = np.nanmean(z > p5.OOD_Z, axis=1)
        for s in ("long", "short"):
            q = {k: _predict_q(quant[s][k], c.x[rows]) for k in QUANT_TARGETS}
            out[s] = {"q": q, "raw": clf[s].predict(c.x[rows])}
        out["ood_share"], out["ood"] = ood_share, p5.ood_flags(c.x[rows], mean, std)
        return out

    pc = base_predict(bcal)
    unc = {s: float(np.nanpercentile((pc[s]["q"]["mfe"][0.75] - pc[s]["q"]["mfe"][0.25]) / np.maximum(pc[s]["q"]["mfe"][0.5], 1e-12), 90))
           for s in ("long", "short")}

    def levels(pr, rows, s, ood):
        q = pr[s]["q"]
        return p5.levels(POLICY, q["mfe"][0.25], q["mfe"][0.5], q["mfe"][0.75], q["adv"][0.75], q["pull"][0.5],
                         c.sigma[rows], cap[s], COST, MIN_EDGE, unc[s], ood)

    calib = {}
    for s in ("long", "short"):                                     # D-029: candidate-policy calibration
        lv = levels(pc, bcal, s, np.zeros(len(bcal), bool))
        use = lv["reason"] == 0
        if use.sum() < 50:
            calib[s] = None
            continue
        sim = c.simulate(bcal[use], s, lv["sl"][use], lv["act"][use], lv["trail"][use])
        win = sim["net_pct"] >= MIN_EDGE
        iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip").fit(pc[s]["raw"][use], win.astype(int))
        calib[s] = (iso, float(sim["net_pct"][win].mean()) if win.any() else 0.0, float(sim["net_pct"][~win].mean()) if (~win).any() else 0.0)

    def proposals(rows):
        pr = base_predict(rows)
        lv, ev, p = {}, {}, {}
        for s in ("long", "short"):
            lv[s] = levels(pr, rows, s, pr["ood"])
            if calib[s] is None:
                p[s], ev[s] = np.zeros(len(rows)), np.full(len(rows), -np.inf)
            else:
                p[s] = calib[s][0].predict(pr[s]["raw"])
                ev[s] = p[s] * calib[s][1] + (1 - p[s]) * calib[s][2]
            ev[s] = np.where(lv[s]["reason"] == 0, ev[s], -np.inf)
        has = np.isfinite(ev["long"]) | np.isfinite(ev["short"])
        side_long = ev["long"] >= ev["short"]
        idx = np.nonzero(has)[0]
        res = {"rows": rows[idx], "long": side_long[idx], "ev": np.where(side_long, ev["long"], ev["short"])[idx]}
        feats = []
        for j in idx:
            s, o = ("long", "short") if side_long[j] else ("short", "long")
            q = pr[s]["q"]
            mfe25, mfe50, mfe75 = q["mfe"][0.25][j], q["mfe"][0.5][j], q["mfe"][0.75][j]
            feats.append([pr[s]["raw"][j], pr[o]["raw"][j], p[s][j], p[s][j] - p[o][j], ev[s][j] if np.isfinite(ev[s][j]) else -1.0,
                          mfe25, mfe50, mfe75, q["adv"][0.75][j], q["pull"][0.5][j], mfe25 / COST, mfe50 / max(lv[s]["sl"][j], 1e-9),
                          (mfe75 - mfe25) / max(mfe50, 1e-9), pr["ood_share"][j], 1.0 if s == "long" else -1.0])
        f = np.asarray(feats, float).reshape(len(idx), 15)
        res["x"] = np.column_stack([f, g7t[rows[idx]]]) if len(idx) else np.zeros((0, 15 + g7t.shape[1]))
        # outcomes of the proposed side: C0 worst-case, C1 maker entry
        net0, exit0, clean = np.full(len(idx), np.nan), np.full(len(idx), -1, np.int64), np.zeros(len(idx), bool)
        net1, exit1, filled = np.full(len(idx), np.nan), np.full(len(idx), -1, np.int64), np.zeros(len(idx), bool)
        for s, mask in (("long", side_long[idx]), ("short", ~side_long[idx])):
            k = np.nonzero(mask)[0]
            if len(k) == 0:
                continue
            r = rows[idx[k]]
            sl, act, tr = (lv[s][n][idx[k]] for n in ("sl", "act", "trail"))
            sim = c.simulate(r, s, sl, act, tr)
            net0[k], exit0[k] = sim["net_pct"], sim["exit_time_ms"]
            clean[k] = (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"]
            m1 = simulate_c1(c.grid, anchors[r], h, s, sl, act, tr, c.funding, c.conflicts)
            net1[k], exit1[k], filled[k] = m1["net_pct"], m1["exit_time_ms"], m1["filled"]
        res.update({"net0": net0, "exit0": exit0, "clean": clean, "net1": net1, "exit1": exit1, "filled": filled})
        return res

    pm, pt = proposals(meta), proposals(test)
    out = {"rows": {"base": int(len(base)), "meta": int(len(meta)), "test": int(len(test))},
           "proposals": {"meta": int(len(pm["rows"])), "test": int(len(pt["rows"]))}}
    if len(pm["rows"]) < 200 or len(pt["rows"]) < 50:
        out["status"] = "TOO_FEW_PROPOSALS"
        return out
    ym = (pm["net0"] >= MIN_EDGE).astype(int)
    fit = pm["clean"] & np.isfinite(pm["net0"])
    model = lgb.LGBMClassifier(**TREE).fit(pm["x"][fit], ym[fit], sample_weight=_weights(anchors[pm["rows"][fit]]))
    s_meta, s_test = model.booster_.predict(pm["x"]), model.booster_.predict(pt["x"])
    a_test = anchors[pt["rows"]]
    ok0 = np.isfinite(pt["net0"])
    arms = {"EV_GATE": pt["ev"] >= MIN_EDGE}
    for share in KEEP:
        arms[f"META_{int(share * 100)}"] = s_test >= np.quantile(s_meta, 1.0 - share)
    out["c0"] = {name: wait_metrics(pt["net0"][ok0], keep[ok0], a_test[ok0], pt["exit0"][ok0]) for name, keep in arms.items()}
    ok1 = pt["filled"] & np.isfinite(pt["net1"])
    out["c1"] = {name: wait_metrics(pt["net1"][ok1], keep[ok1], a_test[ok1], pt["exit1"][ok1]) for name, keep in arms.items()}
    out["c1_fill_rate_pct"] = round(float(pt["filled"].mean() * 100), 2)
    out["adverse_selection_c0_net"] = {
        "filled": round(float(np.nanmean(pt["net0"][pt["filled"]])), 5) if pt["filled"].any() else None,
        "missed": round(float(np.nanmean(pt["net0"][~pt["filled"]])), 5) if (~pt["filled"]).any() else None}
    from .train_v4 import auc
    out["meta_auc_test"] = auc((pt["net0"][ok0] >= MIN_EDGE).astype(int), s_test[ok0])
    out["base_rate_test"] = round(float((pt["net0"][ok0] >= MIN_EDGE).mean()), 4)
    return out


def run(work: Path, symbol: str, horizon: int) -> dict:
    started = time.monotonic()
    c = Context(work, symbol, horizon, "arm3")
    gp = work / "features" / symbol / "g7t.parquet"
    g7t = _load_layer(gp, c.anchors)[1] if gp.exists() else np.zeros((len(c.anchors), 0))
    tr = np.nonzero(c.is_train)[0]
    report = {"symbol": symbol, "horizon_minutes": horizon, "features": c.feature_source, "policy": "a1.0_b0.8_vol",
              "costs": {"C0": COST, "C1": COST_C1}, "data": "dev_train folds only", "folds": []}
    for train_rows, test in d025_folds(c.anchors, tr, horizon):
        report["folds"].append(_fold(c, g7t, train_rows, test))
    done = [f for f in report["folds"] if "c0" in f]
    report["verdicts"] = {}
    if len(done) == 3:
        for cost in ("c0", "c1"):
            for arm in done[0][cost]:
                report["verdicts"][f"{cost}_{arm}"] = verdict([f[cost][arm] for f in done])
    report["seconds"] = round(time.monotonic() - started, 1)
    out = work / "reports" / "meta"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
