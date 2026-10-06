"""P4A — V4 models on the unchanged V3 features (SPEC §28-34, §102 P4A; holdout sealed, D-005).

Per symbol x horizon, development data only:
  dev_train  = fit slice (first 80%) + calibration slice (last 20%), purge = H between them
  dev_val    = later 30% of development (never used for fitting or calibration)

Models (LightGBM, same tree settings as V3 so the comparison isolates the label/exit change):
  BUY / SELL success classifiers   target = net under the P3 REFERENCE policy >= MIN_EDGE and the
                                   outcome is not AMBIGUOUS_INTRABAR (realizable path, Layer B)
  MFE / MAE quantile regressors    q25 / q50 / q75 of Layer A long/short MFE and MAE (policy-free)

Decision used for the dev_val diagnostic (NOT the final P5 policy): a side is taken when its
calibrated probability >= FLOOR and its expected net (calibration-slice averages) > 0; if both sides
pass and their expected nets differ by less than CONFLICT_MARGIN => WAIT (§31).

Comparison on the same dev_val rows (costs 0.12% + actual funding, non-overlapping):
  ARM1_V3            V3 endpoint-label classifier, endpoint exit (time exit at H)
  V3_SIGNAL_NEW_EXIT V3 classifier signals, executed with the REFERENCE path policy (P3 item)
  ARM2_V4            V4 path-label classifier, executed with the REFERENCE path policy
These are diagnostics on validation data; the pre-registered verdict is P6 (L1).
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
from .verify import conflict_buckets

COST, REQUIRED, MIN_EDGE = 0.12, 0.18, 0.06
FLOOR, CONFLICT_MARGIN = 0.55, 0.02
CAL_FRACTION, HALF_LIFE_DAYS = 0.20, 180.0
QUANTILES = (0.25, 0.5, 0.75)
TREE = dict(n_estimators=300, learning_rate=0.03, num_leaves=31, min_child_samples=200, subsample=0.8,
            subsample_freq=1, colsample_bytree=0.8, reg_lambda=1.0, random_state=42, deterministic=True,
            force_row_wise=True, n_jobs=-1, verbose=-1)


def _weights(times: np.ndarray) -> np.ndarray:
    return np.power(0.5, (float(times[-1]) - times.astype(np.float64)) / (HALF_LIFE_DAYS * 86_400_000))


def fit_classifier(x, y, times, fit, cal):
    import lightgbm as lgb
    from sklearn.isotonic import IsotonicRegression
    if y[fit].min() == y[fit].max():
        raise ValueError("single class in the fit slice")
    model = lgb.LGBMClassifier(**TREE)
    model.fit(x[fit], y[fit], sample_weight=_weights(times[fit]))
    iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip")
    iso.fit(model.booster_.predict(x[cal]), y[cal])
    return model.booster_, iso


def predict(booster, iso, x):
    return iso.predict(booster.predict(x))


def auc(y: np.ndarray, p: np.ndarray) -> float | None:
    pos, neg = y == 1, y == 0
    if pos.sum() == 0 or neg.sum() == 0:
        return None
    order = np.argsort(p, kind="mergesort")
    ranks = np.empty(len(p)); ranks[order] = np.arange(1, len(p) + 1)
    return float((ranks[pos].sum() - pos.sum() * (pos.sum() + 1) / 2) / (pos.sum() * neg.sum()))


def decide(p_buy, p_sell, ev_buy, ev_sell, floor: float = FLOOR) -> np.ndarray:
    take_buy = (p_buy >= floor) & (ev_buy > 0)
    take_sell = (p_sell >= floor) & (ev_sell > 0)
    signal = np.zeros(len(p_buy), np.int8)
    signal[take_buy & ~take_sell] = 1
    signal[take_sell & ~take_buy] = -1
    both = take_buy & take_sell
    signal[both & (ev_buy - ev_sell >= CONFLICT_MARGIN)] = 1
    signal[both & (ev_sell - ev_buy >= CONFLICT_MARGIN)] = -1
    return signal


def execute(anchors, signal, outcomes) -> dict:
    """Non-overlapping execution of +1/-1 signals; outcomes[side] = simulate() result per anchor."""
    order = np.nonzero(signal != 0)[0]
    chosen, nets, reasons, free_at = [], [], [], -1
    for i in order:
        if anchors[i] < free_at:
            continue
        o = outcomes["long" if signal[i] > 0 else "short"]
        chosen.append(i); nets.append(o["net_pct"][i]); reasons.append(o["reason"][i])
        free_at = o["exit_time_ms"][i]
    stats = pol.trade_stats(np.array(nets), np.array(reasons, np.int8) if reasons else None)
    stats["long_trades"] = int(sum(1 for i in chosen if signal[i] > 0))
    stats["short_trades"] = int(sum(1 for i in chosen if signal[i] < 0))
    stats["signal_rows"] = {"buy": int((signal > 0).sum()), "sell": int((signal < 0).sum())}
    return stats


def run(work: Path, symbol: str, horizon: int) -> dict:
    import lightgbm as lgb
    import pyarrow.parquet as pq
    started = time.monotonic()
    feats = pq.read_table(work / "features" / symbol / "v3.parquet").to_pydict()
    names = [k for k in feats if k != "anchor_time_ms"]
    f_anchor = np.asarray(feats["anchor_time_ms"], np.int64)
    t = {k: np.asarray(v) for k, v in pq.read_table(work / "targets" / symbol / f"h{horizon}.parquet").to_pydict().items()}
    anchors_all = t["anchor_time_ms"].astype(np.int64)
    pos = np.searchsorted(f_anchor, anchors_all)
    has = (pos < len(f_anchor)) & (f_anchor[np.minimum(pos, len(f_anchor) - 1)] == anchors_all)
    train_m, val_m = splits.dev_train_val(anchors_all)
    keep = has & (t["status"] == VALID) & (train_m | val_m)
    rows = np.nonzero(keep)[0]
    assert anchors_all[rows].max() < splits.holdout_start_ms(anchors_all), "holdout leaked into P4A"
    anchors = anchors_all[rows]
    x = np.column_stack([np.asarray(feats[n], float)[pos[rows]] for n in names])
    is_train, is_val = train_m[rows], val_m[rows]

    # Realizable outcomes under the REFERENCE policy (labels + execution) and the endpoint exit (Arm 1).
    m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
    grid, funding, conflicts = dense_minutes(m1), load_funding(work, symbol), conflict_buckets(m1, m5)
    train_rows_all = np.nonzero(train_m & (t["status"] == VALID))[0]
    ref = {side: reference_levels(t, train_rows_all, side, COST, MIN_EDGE) for side in ("long", "short")}
    path = {side: pol.simulate(None, anchors, horizon, side, ref[side]["sl"], ref[side]["act"], ref[side]["trail"], COST, funding, conflicts, grid=grid)
            for side in ("long", "short")}
    never = 1e9
    endpoint = {side: pol.simulate(None, anchors, horizon, side, never, never, never, COST, funding, conflicts, grid=grid) for side in ("long", "short")}

    y_v4 = {s: ((path[s]["net_pct"] >= MIN_EDGE) & (path[s]["reason"] != pol.AMBIGUOUS_INTRABAR)).astype(int) for s in ("long", "short")}
    gross = t["long_final_time_exit_gross_pct"][rows]; fund = t["funding_long_pct"][rows]
    y_v3 = {"long": (gross > REQUIRED + fund).astype(int), "short": (-gross > REQUIRED - fund).astype(int)}

    tr = np.nonzero(is_train)[0]
    cal_start = tr[int(len(tr) * (1 - CAL_FRACTION))]
    fit = tr[anchors[tr] < anchors[cal_start] - horizon * MINUTE]
    cal = tr[anchors[tr] >= anchors[cal_start]]
    val = np.nonzero(is_val)[0]

    report = {"symbol": symbol, "horizon_minutes": horizon, "rows": {"fit": int(len(fit)), "calibration": int(len(cal)), "dev_val": int(len(val))},
              "reference_policy": ref, "label_base_rates_train": {}, "classifiers": {}, "arms_dev_val": {}, "quantiles": {}}
    model_dir = work / "models" / symbol / f"h{horizon}"
    model_dir.mkdir(parents=True, exist_ok=True)

    signals = {}
    for arm, ys, outcome in (("V3", y_v3, endpoint), ("V4", y_v4, path)):
        p_val, ev_val = {}, {}
        for side in ("long", "short"):
            y = ys[side]
            booster, iso = fit_classifier(x, y, anchors, fit, cal)
            booster.save_model(str(model_dir / f"{arm.lower()}_{side}.txt"))
            p_cal = predict(booster, iso, x[cal]); p_v = predict(booster, iso, x[val])
            net_cal = outcome[side]["net_pct"][cal]
            avg_win = float(net_cal[y[cal] == 1].mean()) if (y[cal] == 1).any() else 0.0
            avg_other = float(net_cal[y[cal] == 0].mean()) if (y[cal] == 0).any() else 0.0
            p_val[side], ev_val[side] = p_v, p_v * avg_win + (1 - p_v) * avg_other
            report["label_base_rates_train"][f"{arm}_{side}"] = round(float(y[tr].mean() * 100), 2)
            report["classifiers"][f"{arm}_{side}"] = {
                "val_auc": round(auc(y[val], p_v), 4) if auc(y[val], p_v) is not None else None,
                "val_base_rate_pct": round(float(y[val].mean() * 100), 2),
                "val_brier": round(float(np.mean((p_v - y[val]) ** 2)), 5),
                "val_brier_climatology": round(float(np.mean((y[cal].mean() - y[val]) ** 2)), 5),
                "avg_win_net_pct": round(avg_win, 4), "avg_other_net_pct": round(avg_other, 4),
                "val_rows_p_ge_floor_pct": round(float((p_v >= FLOOR).mean() * 100), 3)}
        signals[arm] = decide(p_val["long"], p_val["short"], ev_val["long"], ev_val["short"])
        # Diagnostic only (not tuned, not frozen): expected-net gate without the probability floor.
        signals[arm + "_EV_ONLY"] = decide(p_val["long"], p_val["short"], ev_val["long"], ev_val["short"], floor=0.0)

    va = anchors[val]
    sub = lambda o: {k: v[val] for k, v in o.items()}
    report["arms_dev_val"]["ARM1_V3"] = execute(va, signals["V3"], {s: sub(endpoint[s]) for s in endpoint})
    report["arms_dev_val"]["V3_SIGNAL_NEW_EXIT"] = execute(va, signals["V3"], {s: sub(path[s]) for s in path})
    report["arms_dev_val"]["ARM2_V4"] = execute(va, signals["V4"], {s: sub(path[s]) for s in path})
    report["arms_dev_val"]["DIAG_ARM1_V3_EV_ONLY"] = execute(va, signals["V3_EV_ONLY"], {s: sub(endpoint[s]) for s in endpoint})
    report["arms_dev_val"]["DIAG_V3_SIGNAL_NEW_EXIT_EV_ONLY"] = execute(va, signals["V3_EV_ONLY"], {s: sub(path[s]) for s in path})
    report["arms_dev_val"]["DIAG_ARM2_V4_EV_ONLY"] = execute(va, signals["V4_EV_ONLY"], {s: sub(path[s]) for s in path})
    always = {s: execute(va, np.full(len(val), 1 if s == "long" else -1, np.int8), {k: sub(path[k]) for k in path}) for s in ("long", "short")}
    report["arms_dev_val"]["BASELINE_ALWAYS_LONG_REF"] = always["long"]
    report["arms_dev_val"]["BASELINE_ALWAYS_SHORT_REF"] = always["short"]

    for side in ("long", "short"):
        for target in ("mfe_pct", "mae_pct"):
            y = t[f"{side}_{target}"][rows]
            preds = {}
            for q in QUANTILES:
                model = lgb.LGBMRegressor(objective="quantile", alpha=q, **TREE)
                model.fit(x[fit], y[fit], sample_weight=_weights(anchors[fit]))
                model.booster_.save_model(str(model_dir / f"q{int(q * 100)}_{side}_{target}.txt"))
                preds[q] = model.booster_.predict(x[val])
            lo, mid, hi = preds[0.25], preds[0.5], preds[0.75]
            yv = y[val]
            base = {q: float(np.quantile(y[fit], q)) for q in QUANTILES}
            pinball = lambda p, q: float(np.mean(np.maximum(q * (yv - p), (q - 1) * (yv - p))))
            report["quantiles"][f"{side}_{target}"] = {
                "val_coverage_q25_q75_pct": round(float(((yv >= lo) & (yv <= hi)).mean() * 100), 2),
                "val_pinball_q50_model": round(pinball(mid, 0.5), 5), "val_pinball_q50_constant": round(pinball(np.full(len(yv), base[0.5]), 0.5), 5),
                "val_median_width_q75_q25": round(float(np.median(hi - lo)), 4)}

    report["seconds"] = round(time.monotonic() - started, 1)
    meta = {"feature_schema": "qt-shadow-features-v3", "target_schema": "qt-path-targets-v4", "policy": "P3 REFERENCE (diagnostic)",
            "features": names, "tree": TREE, "floor": FLOOR, "min_edge": MIN_EDGE, "cost": COST, "status": "P4A_DEV_ONLY_NOT_FROZEN"}
    (model_dir / "metadata.json").write_text(json.dumps({**meta, "report": report}, indent=1, default=str), "utf-8")
    out = work / "reports" / "p4a"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
