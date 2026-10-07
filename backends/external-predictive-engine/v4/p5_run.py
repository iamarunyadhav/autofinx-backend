"""P5 orchestration (SPEC §103, D-028) for one symbol x horizon. Development data only; holdout sealed.

  1. models on dev_train (fit 80% / calibration 20%, purged): BUY/SELL classifiers (reference-policy
     labels, clean rows) + quantile models (MFE q25/50/75, adverse-before-profit q25/50/75, pullback
     q25/50/75), selected features (P4B report if present, else V3 base)
  2. dev_val predictions (out of sample) -> windows P5-A / P5-B / P5-C
  3. P5-A: quantile quality gate, then the exit policy (12) with the default gate
  4. P5-B: the decision gate (12) for that exit policy
  5. P5-C: one confirmation run, no selection
  6. final retrain under the frozen policy with forward-chaining quantile predictions (§103.9)
  7. freeze record JSON + artifacts with sha256 (D-028); status FROZEN_FOR_P6 or REJECTED
Economics everywhere use apply_worst_case (ambiguity never dropped, §103.8).
"""

from __future__ import annotations

import hashlib
import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import p5
from . import policy as pol
from . import splits
from .download import load_funding
from .lab import reference_levels
from .targets import MINUTE, VALID, dense_minutes
from .train_v4 import COST, MIN_EDGE, TREE, _weights, fit_classifier, predict
from .verify import conflict_buckets

QUANT_TARGETS = {"mfe": "mfe_pct", "adv": "max_adverse_before_profit_pct", "pull": "pullback_before_continuation_pct"}
QS = (0.25, 0.5, 0.75)


def _fit_quantiles(x, y, rows, anchors) -> dict[float, object]:
    import lightgbm as lgb
    ok = rows[np.isfinite(y[rows])]
    models = {}
    for q in QS:
        m = lgb.LGBMRegressor(objective="quantile", alpha=q, **TREE)
        m.fit(x[ok], y[ok], sample_weight=_weights(anchors[ok]))
        models[q] = m.booster_
    return models


def _predict_q_raw(models, x) -> dict[float, np.ndarray]:
    """Unrepaired predictions: the quality gate measures crossing on these (D-036 P0 fix B)."""
    return {q: models[q].predict(x) for q in QS}


def _predict_q(models, x) -> dict[float, np.ndarray]:
    lo, mid, hi, _ = p5.repair_crossing(models[0.25].predict(x), models[0.5].predict(x), models[0.75].predict(x))
    return {0.25: lo, 0.5: mid, 0.75: hi}


class Context:
    """Everything one symbol x horizon needs, loaded once."""

    def __init__(self, work: Path, symbol: str, horizon: int, arm: str = "arm3"):
        import pyarrow.parquet as pq
        self.work, self.symbol, self.h = work, symbol, horizon
        sel_path = work / "reports" / "p4b" / f"{symbol}_h{horizon}.json"
        v4_path = work / "features" / symbol / "v4.parquet"
        if arm == "arm3" and sel_path.exists() and v4_path.exists():
            selected = json.loads(sel_path.read_text("utf-8"))["selected_groups"]
            table = pq.read_table(v4_path).to_pydict()
            names = [n for n in table if n != "anchor_time_ms" and (not n.startswith(("g1", "g2", "g3", "g4", "g5", "g6")) or n[:2] in selected)]
            self.feature_source = f"v4 base + {selected or 'no groups'} (P4B)"
        else:
            table = pq.read_table(work / "features" / symbol / "v3.parquet").to_pydict()
            names = [n for n in table if n != "anchor_time_ms"]
            self.feature_source = "v3 base (no P4B report)"
        self.names = names
        f_anchor = np.asarray(table["anchor_time_ms"], np.int64)
        t = {k: np.asarray(v) for k, v in pq.read_table(work / "targets" / symbol / f"h{horizon}.parquet").to_pydict().items()}
        anchors_all = t["anchor_time_ms"].astype(np.int64)
        pos = np.searchsorted(f_anchor, anchors_all)
        has = (pos < len(f_anchor)) & (f_anchor[np.minimum(pos, len(f_anchor) - 1)] == anchors_all)
        train_m, val_m = splits.dev_train_val(anchors_all)
        rows = np.nonzero(has & (t["status"] == VALID) & (train_m | val_m))[0]
        assert anchors_all[rows].max() < splits.holdout_start_ms(anchors_all), "holdout leaked into P5"
        self.t = {k: v[rows] for k, v in t.items()}
        self.anchors = anchors_all[rows]
        self.x = np.column_stack([np.asarray(table[n], float)[pos[rows]] for n in names])
        self.is_train, self.is_val = train_m[rows], val_m[rows]
        vol = np.asarray(table["vol_48"], float)[pos[rows]] if "vol_48" in table else np.full(len(rows), np.nan)
        self.sigma = vol * np.sqrt(horizon / 5.0) * 100.0
        m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
        self.grid, self.funding, self.conflicts = dense_minutes(m1), load_funding(work, symbol), conflict_buckets(m1, m5)
        tr = np.nonzero(self.is_train)[0]
        cut = tr[int(len(tr) * 0.8)]
        self.fit = tr[self.anchors[tr] < self.anchors[cut] - horizon * MINUTE]
        self.cal = tr[self.anchors[tr] >= self.anchors[cut]]
        self.val = np.nonzero(self.is_val)[0]

    def simulate(self, rows, side, sl, act, trail):
        sim = pol.simulate(None, self.anchors[rows], self.h, side, sl, act, trail, COST, self.funding, self.conflicts, grid=self.grid)
        return pol.apply_worst_case(sim, self.grid, self.anchors[rows], self.h, side, sl, act, trail, COST, self.funding)


def _execute(anchors, signal, outcome) -> np.ndarray:
    """Executed (non-overlapping) trade nets for +1/-1 signals."""
    nets, free_at = [], -1
    for i in np.nonzero(signal != 0)[0]:
        if anchors[i] < free_at:
            continue
        o = outcome["long" if signal[i] > 0 else "short"]
        nets.append(o["net_pct"][i])
        free_at = o["exit_time_ms"][i]
    return np.asarray(nets)


def run(work: Path, symbol: str, horizon: int, arm: str = "arm3") -> dict:
    """arm3 = V4 labels + P4B-selected features (PRIMARY, L1); arm2 = V4 labels + V3 features (benchmark)."""
    started = time.monotonic()
    c = Context(work, symbol, horizon, arm)
    report = {"symbol": symbol, "horizon_minutes": horizon, "arm": arm, "features": c.feature_source, "feature_count": len(c.names),
              "feature_names": c.names, "status": None, "stages": {}}

    # 1. models on dev_train
    ref = {s: reference_levels({f"{s}_{k}": c.t[f"{s}_{k}"] for k in ("max_adverse_before_profit_pct", "peak_retracement_pct")},
                               np.nonzero(c.is_train)[0], s, COST, MIN_EDGE) for s in ("long", "short")}
    ref_sim = {s: c.simulate(np.arange(len(c.anchors)), s, ref[s]["sl"], ref[s]["act"], ref[s]["trail"]) for s in ("long", "short")}
    clf, base_rate, quant = {}, {}, {}
    for s in ("long", "short"):
        y = (ref_sim[s]["net_pct"] >= MIN_EDGE).astype(int)
        clean = (ref_sim[s]["reason"] != pol.AMBIGUOUS_INTRABAR) & ~ref_sim[s]["same_bar_trail"]
        clf[s] = fit_classifier(c.x, y, c.anchors, c.fit[clean[c.fit]], c.cal[clean[c.cal]])
        base_rate[s] = float(y[c.cal][clean[c.cal]].mean())
        quant[s] = {k: _fit_quantiles(c.x, c.t[f"{s}_{col}"], c.fit, c.anchors) for k, col in QUANT_TARGETS.items()}

    # caps, OOD reference, uncertainty limit (dev_train only)
    tr = np.nonzero(c.is_train)[0]
    cap = {s: p5.sl_cap(c.t[f"{s}_max_adverse_before_profit_pct"][tr], c.t[f"{s}_profit_bar_ambiguous"][tr]) for s in ("long", "short")}
    mean, std = np.nanmean(c.x[c.fit], axis=0), np.nanstd(c.x[c.fit], axis=0)
    pred_cal = {s: {k: _predict_q(quant[s][k], c.x[c.cal]) for k in QUANT_TARGETS} for s in ("long", "short")}
    unc_limit = {s: float(np.nanpercentile((pred_cal[s]["mfe"][0.75] - pred_cal[s]["mfe"][0.25]) / np.maximum(pred_cal[s]["mfe"][0.5], 1e-12), 90))
                 for s in ("long", "short")}
    report["caps"] = {s: round(v, 4) for s, v in cap.items()}
    report["uncertainty_limit"] = {s: round(v, 4) for s, v in unc_limit.items()}

    windows = p5.nested_windows(c.anchors, c.val, horizon)
    report["windows_rows"] = {k: int(len(v)) for k, v in windows.items()}

    # quality gate on P5-A sub-folds
    gates = {}
    for s in ("long", "short"):
        for comp, q_used in (("mfe", 0.25), ("adv", 0.75), ("pull", 0.5)):
            col = f"{s}_{QUANT_TARGETS[comp]}"
            folds = []
            for part in p5.sub_folds(c.anchors, windows["A"]):
                y = c.t[col][part]; ok = np.isfinite(y)
                pr = _predict_q_raw(quant[s][comp], c.x[part][ok])
                const = {q: float(np.nanquantile(c.t[col][c.fit], q)) for q in QS}
                folds.append({"y": y[ok], "q25": pr[0.25], "q50": pr[0.5], "q75": pr[0.75], "const": const})
            gates[f"{s}_{comp}"] = p5.quality_gate(folds, q_used)
    report["quality_gate"] = gates
    pull_ok = all(gates[f"{s}_pull"]["passed"] for s in ("long", "short"))
    core_ok = all(gates[f"{s}_{k}"]["passed"] for s in ("long", "short") for k in ("mfe", "adv"))
    if not core_ok:
        report["status"] = "REJECTED"
        report["reason"] = "MFE / adverse quantile model failed its quality gate (no simple fallback inside the family)"
        return _finish(work, report, started)
    family = [pol_ for pol_ in p5.exit_family() if pull_ok or pol_.trail == "vol"]

    ev_cache: dict = {}

    def ev_params(policy, s):
        """avg_win / avg_other under THIS candidate policy on the calibration slice (cached per policy/side)."""
        key = (policy, s)
        if key not in ev_cache:
            prc = pred_cal[s]
            lvc = p5.levels(policy, prc["mfe"][0.25], prc["mfe"][0.5], prc["mfe"][0.75], prc["adv"][0.75], prc["pull"][0.5],
                            c.sigma[c.cal], cap[s], COST, MIN_EDGE, unc_limit[s], np.zeros(len(c.cal), bool))
            use = lvc["reason"] == 0
            if use.sum() < 50:
                ev_cache[key] = None
            else:
                from sklearn.isotonic import IsotonicRegression
                simc = c.simulate(c.cal[use], s, lvc["sl"][use], lvc["act"][use], lvc["trail"][use])
                win = simc["net_pct"] >= MIN_EDGE
                # Probabilities must describe success UNDER THIS CANDIDATE POLICY (not the reference policy
                # the classifier was trained on): re-calibrate the classifier's raw score to the candidate's
                # realized success on the calibration slice (out-of-fit predictions, so no leakage).
                iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip")
                iso.fit(clf[s][0].predict(c.x[c.cal[use]]), win.astype(int))
                ev_cache[key] = (float(simc["net_pct"][win].mean()) if win.any() else 0.0,
                                 float(simc["net_pct"][~win].mean()) if (~win).any() else 0.0,
                                 iso, float(win.mean()))
        return ev_cache[key]

    def evaluate(rows, policy, gate):
        preds = {s: {k: _predict_q(quant[s][k], c.x[rows]) for k in QUANT_TARGETS} for s in ("long", "short")}
        ood = p5.ood_flags(c.x[rows], mean, std)
        lv, outcome, ev, prob, cand_base = {}, {}, {}, {}, {}
        for s in ("long", "short"):
            pr = preds[s]
            lv[s] = p5.levels(policy, pr["mfe"][0.25], pr["mfe"][0.5], pr["mfe"][0.75], pr["adv"][0.75], pr["pull"][0.5],
                              c.sigma[rows], cap[s], COST, MIN_EDGE, unc_limit[s], ood)
            sl = np.where(lv[s]["reason"] == 0, lv[s]["sl"], 1.0); act = np.where(lv[s]["reason"] == 0, lv[s]["act"], 1.0)
            trail = np.where(lv[s]["reason"] == 0, lv[s]["trail"], 0.5)
            outcome[s] = c.simulate(rows, s, sl, act, trail)
            params = ev_params(policy, s)
            if params is None:
                prob[s] = np.zeros(len(rows)); ev[s] = np.full(len(rows), -np.inf); cand_base[s] = 1.0
            else:
                prob[s] = params[2].predict(clf[s][0].predict(c.x[rows]))
                ev[s] = prob[s] * params[0] + (1 - prob[s]) * params[1]
                cand_base[s] = params[3]
        signal = p5.decide(prob["long"], prob["short"], ev["long"], ev["short"], cand_base["long"], cand_base["short"],
                           gate[0], gate[1], lv["long"]["reason"] == 0, lv["short"]["reason"] == 0, MIN_EDGE)
        waits = {f"{s}_{code}": int((lv[s]["reason"] == i).sum()) for s in ("long", "short") for i, code in enumerate(p5.WAIT_CODES)}
        amb = float(np.mean([(o["reason"] == pol.AMBIGUOUS_INTRABAR) | o["same_bar_trail"] for o in outcome.values()]))
        for s in ("long", "short"):
            ok = lv[s]["reason"] == 0
            params = ev_params(policy, s)
            waits[f"{s}_ev_params"] = None if params is None else [round(params[0], 4), round(params[1], 4), round(params[3], 4)]
            waits[f"{s}_p_mean_tradeable"] = round(float(prob[s][ok].mean()), 4) if ok.any() else None
            waits[f"{s}_ev_max_tradeable"] = round(float(ev[s][ok].max()), 4) if ok.any() and np.isfinite(ev[s][ok]).any() else None
            waits[f"{s}_ev_ge_gate"] = int((ok & (ev[s] >= MIN_EDGE + gate[1])).sum())
        return signal, outcome, waits, amb

    def stage(window, candidates):
        scored = []
        rows_info = {}
        for key, policy, gate, complexity in candidates:
            fold_nets, ambs, wait_tot = [], [], {}
            for part in p5.sub_folds(c.anchors, window):
                signal, outcome, waits, amb_part = evaluate(part, policy, gate)
                fold_nets.append(_execute(c.anchors[part], signal, outcome))
                ambs.append(amb_part)
                for code, n in waits.items():
                    if isinstance(n, (int, np.integer)):
                        wait_tot[code] = wait_tot.get(code, 0) + int(n)
                    else:
                        wait_tot[code] = n                    # diagnostics: keep the last sub-fold value
            amb = float(np.mean(ambs)) if ambs else 0.0
            ok, score, why = p5.qualifies(fold_nets, horizon)
            allnet = np.concatenate(fold_nets) if fold_nets else np.zeros(0)
            rows_info[key] = {"qualified": ok, "reason": why, "score": None if not np.isfinite(score) else round(score, 5),
                              "trades": int(len(allnet)), "avg_net": round(float(allnet.mean()), 5) if len(allnet) else None,
                              "fold_avg": [round(float(f.mean()), 5) if len(f) else None for f in fold_nets],
                              "rows_by_level_reason": wait_tot}
            if ok:
                scored.append((score, complexity, amb, key))
        return p5.pick(scored), rows_info

    # P5-A: exit policy with the default gate
    cand_a = [(f"a{p.a}_b{p.b}_{p.trail}", p, p5.DEFAULT_GATE, p.complexity) for p in family]
    best_a, info_a = stage(windows["A"], cand_a)
    report["stages"]["P5-A"] = info_a
    if best_a is None:
        report["status"] = "REJECTED"; report["reason"] = "no exit policy qualified in P5-A"
        return _finish(work, report, started)
    chosen_policy = next(p for k, p, _, _ in cand_a if k == best_a[3])

    # P5-B: decision gate for that policy
    cand_b = [(f"lift{lift}_margin{m}", chosen_policy, (lift, m), (p5.GATE_LIFTS.index(lift), p5.GATE_MARGINS.index(m)))
              for lift in p5.GATE_LIFTS for m in p5.GATE_MARGINS]
    best_b, info_b = stage(windows["B"], cand_b)
    report["stages"]["P5-B"] = info_b
    if best_b is None:
        report["status"] = "REJECTED"; report["reason"] = "no decision gate qualified in P5-B"
        return _finish(work, report, started)
    chosen_gate = next(g for k, _, g, _ in cand_b if k == best_b[3])

    # P5-C: confirmation only
    signal, outcome, waits, amb = evaluate(windows["C"], chosen_policy, chosen_gate)
    nets_c = _execute(c.anchors[windows["C"]], signal, outcome)
    report["stages"]["P5-C"] = {**pol.trade_stats(nets_c), "rows_by_level_reason": waits, "ambiguity_rate": round(amb, 4)}
    report["frozen"] = {"exit_policy": {"a": chosen_policy.a, "b": chosen_policy.b, "trail": chosen_policy.trail},
                        "gate": {"lift": chosen_gate[0], "margin": chosen_gate[1]}, "cost": COST, "edge": MIN_EDGE,
                        "caps": report["caps"], "uncertainty_limit": report["uncertainty_limit"],
                        "ood": {"share": p5.OOD_SHARE, "z": p5.OOD_Z, "max_z": p5.OOD_MAX_Z}, "conflict_margin": p5.CONFLICT_MARGIN,
                        "stress_matrix": {"cost": [1.0, 1.25, 1.5], "sl": [0.9, 1.1], "activation": [0.9, 1.1], "trailing": [0.9, 1.1],
                                          "delayed_entry_minutes": 1}}
    if len(nets_c) == 0 or nets_c.sum() <= 0:
        report["status"] = "REJECTED"; report["reason"] = "P5-C confirmation not positive"
        return _finish(work, report, started)

    # 6. final retrain under the frozen policy, forward-chaining quantile predictions over dev rows
    dev = np.nonzero(c.is_train | c.is_val)[0]
    oof = {s: {k: {q: np.full(len(c.anchors), np.nan) for q in QS} for k in QUANT_TARGETS} for s in ("long", "short")}
    predicted = np.zeros(len(c.anchors), bool)
    for train_rows, predict_rows in p5.forward_blocks(c.anchors, dev, horizon):
        for s in ("long", "short"):
            for k, col in QUANT_TARGETS.items():
                pr = _predict_q(_fit_quantiles(c.x, c.t[f"{s}_{col}"], train_rows, c.anchors), c.x[predict_rows])
                for q in QS:
                    oof[s][k][q][predict_rows] = pr[q]
        predicted[predict_rows] = True
    rows = np.nonzero(predicted)[0]
    final = {"rows_labelled": int(len(rows)), "artifacts": {}}
    out_dir = work / "models" / symbol / f"h{horizon}" / ("p5_final" if arm == "arm3" else f"p5_final_{arm}")
    decision = {"feature_names": c.names, "exit_policy": report["frozen"]["exit_policy"], "gate": report["frozen"]["gate"],
                "caps": cap, "uncertainty_limit": unc_limit, "ood_mean": mean.tolist(), "ood_std": std.tolist(), "sides": {}}
    out_dir.mkdir(parents=True, exist_ok=True)
    for s in ("long", "short"):
        o = oof[s]
        lv = p5.levels(chosen_policy, o["mfe"][0.25][rows], o["mfe"][0.5][rows], o["mfe"][0.75][rows], o["adv"][0.75][rows],
                       o["pull"][0.5][rows], c.sigma[rows], cap[s], COST, MIN_EDGE, unc_limit[s], np.zeros(len(rows), bool))
        tradeable = lv["reason"] == 0
        sim = c.simulate(rows[tradeable], s, lv["sl"][tradeable], lv["act"][tradeable], lv["trail"][tradeable])
        y = np.zeros(len(c.anchors), int); clean = np.zeros(len(c.anchors), bool)
        y[rows[tradeable]] = (sim["net_pct"] >= MIN_EDGE).astype(int)
        clean[rows[tradeable]] = (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"]
        lab = rows[clean[rows]]
        cut = lab[int(len(lab) * 0.8)]
        fit_rows, cal_rows = lab[c.anchors[lab] < c.anchors[cut] - horizon * MINUTE], lab[c.anchors[lab] >= c.anchors[cut]]
        booster, iso = fit_classifier(c.x, y, c.anchors, fit_rows, cal_rows)
        # Frozen decision inputs for P6 (D-032): calibration map, EV params and base rate from the final
        # calibration slice under the frozen policy — the holdout only applies them.
        cal_ok = cal_rows[np.isin(cal_rows, rows[tradeable])]
        pos_of = {int(r): i for i, r in enumerate(rows[tradeable])}
        net_cal = np.array([sim["net_pct"][pos_of[int(r)]] for r in cal_ok]) if len(cal_ok) else np.zeros(0)
        win_cal = net_cal >= MIN_EDGE
        decision["sides"][s] = {"iso_x": iso.X_thresholds_.tolist(), "iso_y": iso.y_thresholds_.tolist(),
                                "avg_win": float(net_cal[win_cal].mean()) if win_cal.any() else 0.0,
                                "avg_other": float(net_cal[~win_cal].mean()) if (~win_cal).any() else 0.0,
                                "base_rate": float(win_cal.mean()) if len(win_cal) else 1.0}
        path = out_dir / f"classifier_{s}.txt"
        booster.save_model(str(path))
        final["artifacts"][path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
        final[f"{s}_labels"] = {"rows": int(len(lab)), "positive_rate": round(float(y[lab].mean()), 4)}
        for k, col in QUANT_TARGETS.items():
            models = _fit_quantiles(c.x, c.t[f"{s}_{col}"], dev, c.anchors)
            for q, m in models.items():
                qp = out_dir / f"q{int(q * 100)}_{s}_{k}.txt"
                m.save_model(str(qp))
                final["artifacts"][qp.name] = hashlib.sha256(qp.read_bytes()).hexdigest()
    dpath = out_dir / "decision.json"
    dpath.write_text(json.dumps(decision), "utf-8")
    final["artifacts"][dpath.name] = hashlib.sha256(dpath.read_bytes()).hexdigest()
    report["final_retrain"] = final
    report["status"] = "FROZEN_FOR_P6"
    return _finish(work, report, started)


def _finish(work: Path, report: dict, started: float) -> dict:
    from .runinfo import stamp
    report["seconds"] = round(time.monotonic() - started, 1)
    report["identity"] = stamp()
    out = work / "reports" / "p5"
    out.mkdir(parents=True, exist_ok=True)
    suffix = "" if report.get("arm", "arm3") == "arm3" else f"_{report['arm']}"
    (out / f"{report['symbol']}_h{report['horizon_minutes']}{suffix}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
