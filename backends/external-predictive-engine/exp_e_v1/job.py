"""Experiment E v1 walk-forward jobs (plan sections 4-5): per coin and test year, fit-window models -> raw predictions on the calibration window and on the test year.

The recipes are the existing V3 / V4 LightGBM recipes (v4.train_v4.TREE, 37 V3 features, 180-day half-life weights); only the fold windows, the labels of the Q models and the
(uniform, later) calibrators are new. Everything that depends on data (weights reference, V4 reference exit levels, calibrators, EV averages) uses fit / calibration rows only.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import time
from pathlib import Path

import numpy as np

from v4 import policy as pol
from v4.costs import round_trip_pct
from v4.lab import reference_levels
from v4.targets import dense_minutes
from v4.train_v4 import MIN_EDGE, REQUIRED, TREE as V4_TREE
from v4.verify import conflict_buckets

from . import data as D
from . import leakage as LK
from . import metrics as MT
from .targets import COMP_KEYS, SOFT_BASE

M5 = D.M5
H_MS = {"P1": 12 * M5, "P2": 6 * M5}
COST_V4 = round_trip_pct("taker")        # 0.12, the V4 recipe constant
MIN_FIT, MIN_CAL = 20_000, 10_000
V3_FEATURES = None                       # filled from the parquet at run time (37 names, file order)
VOL_COLS = ("vol_12", "vol_48", "vol_24h", "vol_regime", "range_1", "range_mean_12")
TREND_COLS = ("ret_1", "ret_3", "ret_6", "ret_12", "ret_24", "ret_48", "ema_gap_12", "ema_gap_48", "rsi_14", "dist_high_48", "dist_low_48",
              "ret_12h", "ret_24h", "ret_72h", "dist_high_24h", "dist_low_24h", "range_pos_72h", "vwap_gap_24h")
SOFT_COLS = tuple("soft_" + k for k in SOFT_BASE + COMP_KEYS) + ("R_P1", "M_P1")
TREE = {**V4_TREE, "n_jobs": int(os.environ.get("EXP_E_JOBS", "4"))}
HALF_LIFE_DAYS = 180.0
YEARS = (2023, 2024, 2025)
FIRST_YEAR = {"BTCUSDT": 2023, "ETHUSDT": 2023, "BNBUSDT": 2023, "SOLUSDT": 2025}


def ms(y, m, d=1):
    return D._ms(y, m, d)


def weights(times: np.ndarray) -> np.ndarray:
    """Half-life time weights, reference = the last (newest) FIT row of the fold (fold-local, as the V4 recipe)."""
    return np.power(0.5, (float(times[-1]) - times.astype(np.float64)) / (HALF_LIFE_DAYS * 86_400_000))


def fit_model(X, y, anchors, rows, kind: str):
    import lightgbm as lgb
    if kind == "clf":
        if len(rows) < MIN_FIT or y[rows].min() == y[rows].max():
            raise ValueError("too few fit rows or a single class")
        m = lgb.LGBMClassifier(**TREE)
    else:
        if len(rows) < MIN_FIT:
            raise ValueError("too few fit rows")
        m = lgb.LGBMRegressor(**TREE)
    m.fit(X[rows], y[rows], sample_weight=weights(anchors[rows]))
    return m.booster_


def booster_hash(b) -> str:
    return hashlib.sha256(b.model_to_string().encode()).hexdigest()


def load_features(work: Path, sym: str, ev_anchor: np.ndarray):
    """V3 features aligned to the event anchors (rows < CUT only)."""
    tab = D.read_anchor_table(work / "features" / sym / "v3.parquet")
    names = [k for k in tab if k != "anchor_time_ms"]
    fa = tab["anchor_time_ms"].astype(np.int64)
    pos = np.searchsorted(fa, ev_anchor)
    assert (fa[pos] == ev_anchor).all(), "event anchor without a feature row"
    X = np.column_stack([np.asarray(tab[n], float)[pos] for n in names])
    return names, X


def windows_for(year: int):
    return ms(year - 1, 7), ms(year, 1), ms(year + 1, 1)      # t_cal0, t_test0, t_test1


def fold_rows(anchor, universe, label_ms: int, year: int):
    t_cal0, t_test0, t_test1 = windows_for(year)
    mat_fit = LK.mature_mask(anchor + label_ms, t_cal0)
    mat_cal = LK.mature_mask(anchor + label_ms, t_test0)
    fit = np.nonzero(universe & mat_fit & (anchor < t_cal0))[0]
    cal = np.nonzero(universe & mat_cal & (anchor >= t_cal0))[0]
    test = np.nonzero(universe & (anchor >= t_test0) & (anchor < t_test1))[0]
    LK.check_fold(anchor, fit, cal, test, label_ms, t_cal0, t_test0)
    return fit, cal, test


# ------------------------------------------------------------------------------------------ V4 path labels (fold-local reference levels)
class V4Sim:
    """1m grid + funding + source-conflict buckets of ONE coin (restricted loader), built once per process."""

    def __init__(self, work: Path, sym: str):
        cols = ("open", "high", "low", "close", "volume")
        m1, m5 = D.load_klines(work, sym, "1m", columns=cols), D.load_klines(work, sym, "5m", columns=cols)
        self.grid = dense_minutes(m1)
        self.conflicts = conflict_buckets(m1, m5)
        self.funding = D.load_funding(work, sym)
        del m1, m5

    def labels(self, anchors, rows, ref: dict) -> dict:
        """V4 labels y (net >= MIN_EDGE under the reference levels, worst-case ambiguity) and clean flags for the anchors[rows]."""
        out = {}
        for side in ("long", "short"):
            r = ref[side]
            sim = pol.simulate(None, anchors[rows], 60, side, r["sl"], r["act"], r["trail"], COST_V4, self.funding, self.conflicts, grid=self.grid)
            sim = pol.apply_worst_case(sim, self.grid, anchors[rows], 60, side, r["sl"], r["act"], r["trail"], COST_V4, self.funding)
            out[side] = ((sim["net_pct"] >= MIN_EDGE).astype(np.int8),
                         (sim["reason"] != pol.AMBIGUOUS_INTRABAR) & ~sim["same_bar_trail"] & np.isfinite(sim["net_pct"]))
        return out


def q_labels(ev: dict, p: str, cost: float = 0.12) -> dict:
    """Quick Trade targets of preset p from the event table."""
    g, f = ev[f"gX1_{p}"], ev[f"fX1_{p}"]
    out = {"yA": np.where(g == 0, np.nan, (g > 0).astype(float)), "gX1": g}
    for s, sg in (("L", 1.0), ("S", -1.0)):
        net = sg * g - cost - sg * f
        out["net" + s] = net
        out["yC" + s] = np.where(np.isfinite(net), (net > 0).astype(float), np.nan)
        out["yB" + s] = np.where(np.isfinite(ev[f"gB{s}_{p}"]), (ev[f"rB{s}_{p}"] == 2).astype(float), np.nan)   # reason 2 = TP
    return out


# ------------------------------------------------------------------------------------------ one fold
def run_fold(work: Path, sym: str, year: int, ev: dict, names, X, v4sim: V4Sim | None, log=print) -> tuple[dict, dict]:
    t0 = time.time()
    anchor = ev["anchor"]
    col = {n: i for i, n in enumerate(names)}
    S = np.column_stack([np.asarray(ev[c], float) for c in SOFT_COLS])
    X_soft = np.column_stack([X, S])
    X_vol = X[:, [col[c] for c in VOL_COLS]]
    X_trend = X[:, [col[c] for c in TREND_COLS]]
    arrays: dict = {}
    prov: dict = {"coin": sym, "test_year": year, "models": {}, "windows": {}, "tree": TREE}
    t_cal0, t_test0, t_test1 = windows_for(year)
    auc_log = {}
    (work / "exp_e_v1" / "models" / sym / str(year)).mkdir(parents=True, exist_ok=True)

    def record(key, booster, fit_n):
        prov["models"][key] = {"sha256": booster_hash(booster), "fit_rows": int(fit_n), "n_trees": int(booster.num_trees())}

    for P in ("P1", "P2"):
        u = (ev["ok_P1"] & ev["valid_L1"]) if P == "P1" else ev["ok_P2"]
        label_ms = H_MS[P]
        fit, cal, test = fold_rows(anchor, u, label_ms, year)
        prov["windows"][P] = {"fit_rows": int(len(fit)), "cal_rows": int(len(cal)), "test_rows": int(len(test)),
                              "fit_first": int(anchor[fit].min()) if len(fit) else None, "fit_last": int(anchor[fit].max()) if len(fit) else None,
                              "cal_window": [t_cal0, t_test0], "test_window": [t_test0, t_test1], "label_horizon_ms": label_ms, "embargo_ms": LK.EMBARGO_MS}
        if len(fit) < MIN_FIT or len(cal) < MIN_CAL or len(test) == 0:
            prov["windows"][P]["skipped"] = "too few rows"
            log(f"{sym} {year} {P}: skipped (fit {len(fit)}, cal {len(cal)}, test {len(test)})")
            continue
        arrays[f"idx_cal_{P}"], arrays[f"idx_test_{P}"] = cal.astype(np.int32), test.astype(np.int32)
        L = q_labels(ev, P)
        models: list[tuple[str, np.ndarray, str, np.ndarray, np.ndarray]] = []   # (key, y, kind, features, fit rows)
        fy = lambda y: fit[np.isfinite(y[fit])]
        for tgt in ("A", "B_L", "B_S", "C_L", "C_S"):
            y = L["y" + tgt.replace("_", "")]
            models.append((f"Q_{tgt}", y, "clf", X, fy(y)))
        for s in ("L", "S"):
            y = L["net" + s]
            models.append((f"Q_D_{s}", y, "reg", X, fy(y)))
        if P == "P1":
            for tag, XX in (("VOL", X_vol), ("TREND", X_trend), ("SOFT", X_soft)):
                for tgt in ("A", "C_L", "C_S"):
                    y = L["y" + tgt.replace("_", "")]
                    models.append((f"{tag}_{tgt}", y, "clf", XX, fy(y)))
            # Layer 1: V3 endpoint labels and V4 path labels (existing native recipes, raw booster output)
            gross, fund = ev["L1_gross"], ev["L1_fund"]
            y3 = {"L": (gross > REQUIRED + fund).astype(float), "S": (-gross > REQUIRED - fund).astype(float)}
            for s in ("L", "S"):
                models.append((f"V3_{s}", y3[s], "clf", X, fit))
            for s in ("L", "S"):
                arrays[f"yV3_{s}"] = y3[s][np.r_[cal, test]].astype(np.int8)
            if v4sim is not None:
                ref = {side: reference_levels({f"{side}_{k}": ev[f"L1_{k}_{side[0].upper()}"] for k in ("max_adverse_before_profit_pct", "peak_retracement_pct")},
                                              fit, side, COST_V4, MIN_EDGE) for side in ("long", "short")}
                prov["v4_reference_levels"] = ref
                rows_all = np.r_[fit, cal, test]
                lab = v4sim.labels(anchor, rows_all, ref)
                yv4 = {}
                for side, s in (("long", "L"), ("short", "S")):
                    y = np.full(len(anchor), np.nan)
                    clean = np.zeros(len(anchor), bool)
                    y[rows_all], clean[rows_all] = lab[side][0], lab[side][1]
                    yv4[s] = (y, clean)
                    models.append((f"V4_{s}", y, "clf", X, fit[clean[fit]]))
                    arrays[f"yV4_{s}"] = np.where(clean[np.r_[cal, test]], y[np.r_[cal, test]], -1).astype(np.int8)
        arrays[f"n_cal_{P}"] = np.array([len(cal)])
        for key, y, kind, XX, rows in models:
            ts = time.time()
            try:
                b = fit_model(XX, y, anchor, rows, kind)
            except ValueError as e:
                prov["models"][f"{P}|{key}"] = {"skipped": str(e), "fit_rows": int(len(rows))}
                log(f"{sym} {year} {P} {key}: skipped ({e})")
                continue
            record(f"{P}|{key}", b, len(rows))
            arrays[f"cal|{P}|{key}"] = b.predict(XX[cal]).astype(np.float32)
            arrays[f"test|{P}|{key}"] = b.predict(XX[test]).astype(np.float32)
            if kind == "clf" and key.split("_")[0] in ("Q", "V3", "V4") and key.split("_")[1] in ("A", "B", "C", "L", "S"):
                yy = y[test]
                ok = np.isfinite(yy)
                a_ = MT.auc(yy[ok].astype(int), arrays[f"test|{P}|{key}"][ok]) if ok.sum() > 100 else None
                auc_log[f"{P}|{key}"] = a_
                prov["models"][f"{P}|{key}"]["test_auc"] = a_
                if P == "P1" and key.startswith("Q_"):
                    LK.too_good_guard(a_, f"{sym} {year} {P} {key}")
            if key.startswith("V4") or key.startswith("V3"):
                b.save_model(str(work / "exp_e_v1" / "models" / sym / str(year) / f"{P}_{key}.txt"))
            log(f"{sym} {year} {P} {key}: fit {len(rows)} rows, {time.time() - ts:.0f}s")
    prov["seconds"] = time.time() - t0
    return arrays, prov
