"""Experiment E v1 analysis (plan sections 6-10): assembles the pooled walk-forward test sets from the saved OOF predictions, fits the calibrators on the calibration windows only,
and computes calibration, bucket, decision, economics, family and verdict results. Pure numpy; day-block bootstrap everywhere."""

from __future__ import annotations

import gc
import json
from pathlib import Path

import numpy as np
from scipy import stats as sps

from . import data as D
from . import job as J
from . import leakage as LK
from . import metrics as MT

SYMS = D.LIVE_SYMBOLS
VERD = (0, 1, 2)                                   # BTC, ETH, BNB carry the verdict; SOL (index 3, 2025 only) is report-only
YEARS = (2023, 2024, 2025)
N_DAYS = int((D.CUT - D.TEST_LO) // D.DAY)
COST0 = 0.12
COSTS = {"meas_0.12": 0.12, "code_0.14": 0.14, "maker_code_0.11": 0.11, "maker_meas_0.08": 0.08}
TGTS = ("A", "BL", "BS", "CL", "CS")
ABL = ("VOL", "TREND", "SOFT")
ABL_TGTS = ("A", "CL", "CS")
METHODS = ("raw", "platt", "iso", "base")
Q_KEY = {"A": "Q_A", "BL": "Q_B_L", "BS": "Q_B_S", "CL": "Q_C_L", "CS": "Q_C_S"}
ABL_KEY = {"A": "A", "CL": "C_L", "CS": "C_S"}
MAT_BRIER, MDE_BRIER = 0.0005, 0.001
MAT_NET, MDE_NET = 0.03, 0.06
H_MS = J.H_MS


# ------------------------------------------------------------------------------------------ assembling
def _iso_reg(anchor, rows, raw, y, t_cal0, t_test0, label_ms):
    """Unbounded isotonic regression of a realised net return on a predicted one (calibration window only, guarded)."""
    from sklearn.isotonic import IsotonicRegression
    LK.assert_calibration_rows(anchor, rows, t_cal0, t_test0, label_ms)
    ok = np.isfinite(raw) & np.isfinite(y)
    m = IsotonicRegression(out_of_bounds="clip")
    m.fit(raw[ok], y[ok])
    return m


def available_folds(out: Path) -> list:
    res = []
    for si, sym in enumerate(SYMS):
        for y in YEARS:
            if (out / "oof" / sym / f"{y}.done").exists():
                res.append((si, sym, y))
    return res


class Acc:
    """Per-preset accumulator of per-row arrays over folds."""

    def __init__(self):
        self.d: dict[str, list] = {}

    def add(self, key, arr):
        arr = np.asarray(arr)
        if arr.dtype == np.float64:
            arr = arr.astype(np.float32)               # halves the analysis memory (the PC has little free commit); sums are accumulated in float64
        self.d.setdefault(key, []).append(arr)

    def cat(self) -> dict:
        return {k: np.concatenate(v) for k, v in self.d.items()}


def assemble(out: Path, P: str, log=print) -> dict:
    """Pooled test arrays of preset P. Keys: coin, year, anchor, day, labels / nets / rules, prob[(model, tgt, method)], ev[(model, kind, side)], native, meta."""
    acc, pr, evacc, native = Acc(), {}, {}, {}
    cache: dict = {}
    meta = {"folds": []}
    for si, sym, year in available_folds(out):
        z = np.load(out / "oof" / sym / f"{year}.npz")
        if f"idx_test_{P}" not in z.files:
            continue
        if sym not in cache:
            cache.clear()                                   # one coin's event table in memory at a time
            cache[sym] = dict(np.load(out / "events" / f"{sym}.npz"))
        ev = cache[sym]
        anchor_all = ev["anchor"]
        cal, test = z[f"idx_cal_{P}"].astype(np.int64), z[f"idx_test_{P}"].astype(np.int64)
        t_cal0, t_test0, t_test1 = J.windows_for(year)
        lms = H_MS[P]
        L = J.q_labels(ev, P)
        nt = len(test)
        meta["folds"].append({"coin": sym, "year": year, "cal_rows": int(len(cal)), "test_rows": int(nt), "cal_window": [t_cal0, t_test0], "test_window": [t_test0, t_test1]})
        acc.add("coin", np.full(nt, si, np.int8))
        acc.add("year", np.full(nt, year, np.int16))
        acc.add("anchor", anchor_all[test])
        acc.add("idx", test)
        for k in ("yA", "yBL", "yBS", "yCL", "yCS", "netL", "netS", "gX1"):
            acc.add(k, L[k][test])
        acc.add("fX1", ev[f"fX1_{P}"][test])
        for s, sg in (("L", 1.0), ("S", -1.0)):
            acc.add("gB" + s, ev[f"gB{s}_{P}"][test])
            acc.add("rB" + s, ev[f"rB{s}_{P}"][test])
            acc.add("netB" + s, ev[f"gB{s}_{P}"][test] - COST0 - sg * ev[f"fB{s}_{P}"][test])
        pp = "P1" if P in ("P1", "P3") else P
        acc.add("sigB", ev[f"sigB_{pp}"][test])
        acc.add("sigC", ev[f"sigC_{pp}"][test])
        acc.add("M", ev[f"M_{pp}"][test])
        acc.add("on15", ev["on15"][test])
        net_cal = {"L": L["netL"][cal], "S": L["netS"][cal]}
        Wb, Lb = {}, {}
        for s in ("L", "S"):
            n = net_cal[s][np.isfinite(net_cal[s])]
            Wb[s] = float(n[n > 0].mean()) if (n > 0).any() else 0.0
            Lb[s] = float(-n[n < 0].mean()) if (n < 0).any() else 0.0

        def raws(model, tgt):
            """(raw_cal, raw_test) or None."""
            if model == "Q":
                k = Q_KEY[tgt]
            elif model in ABL:
                if tgt not in ABL_KEY:
                    return None
                k = f"{model}_{ABL_KEY[tgt]}"
            else:                                                       # V3 / V4: the native long / short scores mapped onto the target
                kl, ks = f"{P}|{model}_L", f"{P}|{model}_S"
                if f"cal|{kl}" not in z.files or f"cal|{ks}" not in z.files:
                    return None
                sl_c, ss_c = z[f"cal|{kl}"].astype(float), z[f"cal|{ks}"].astype(float)
                sl_t, ss_t = z[f"test|{kl}"].astype(float), z[f"test|{ks}"].astype(float)
                if tgt == "A":
                    return 0.5 + (sl_c - ss_c) / 2, 0.5 + (sl_t - ss_t) / 2
                return (sl_c, sl_t) if tgt.endswith("L") else (ss_c, ss_t)
            kk = f"cal|{P}|{k}"
            if kk not in z.files:
                return None
            return z[kk].astype(float), z[f"test|{P}|{k}"].astype(float)

        models = ["Q"] + (["V3", "V4"] + list(ABL) if P == "P1" else [])
        for model in models:
            for tgt in TGTS:
                if model in ABL and tgt not in ABL_TGTS:
                    continue
                r = raws(model, tgt)
                if r is None:
                    continue
                rc, rt = r
                yc = L["y" + tgt][cal]
                ok = np.isfinite(yc)
                for method in METHODS:
                    cal_obj = MT.fit_calibrator_checked(method, anchor_all, cal[ok], rc[ok], yc[ok], t_cal0, t_test0, lms)
                    pr.setdefault((model, tgt, method), []).append(cal_obj.predict(rt).astype(np.float32))
        # expected values and decisions: EVp from the isotonic after-cost-success probability, EVD from the calibrated D regressors
        for model in models:
            if all((model, t, "iso") in pr and len(pr[(model, t, "iso")]) == len(meta["folds"]) for t in ("CL", "CS")):
                pl, ps = pr[(model, "CL", "iso")][-1].astype(float), pr[(model, "CS", "iso")][-1].astype(float)
                evacc.setdefault((model, "EVp", "L"), []).append(pl * Wb["L"] - (1 - pl) * Lb["L"])
                evacc.setdefault((model, "EVp", "S"), []).append(ps * Wb["S"] - (1 - ps) * Lb["S"])
        if f"test|{P}|Q_D_L" in z.files:
            for s in ("L", "S"):
                ok = np.isfinite(net_cal[s])
                m = _iso_reg(anchor_all, cal[ok], z[f"cal|{P}|Q_D_{s}"].astype(float)[ok], net_cal[s][ok], t_cal0, t_test0, lms)
                evacc.setdefault(("Q", "EVD", s), []).append(m.predict(z[f"test|{P}|Q_D_{s}"].astype(float)))
                acc.add(f"Dhat{s}", z[f"test|{P}|Q_D_{s}"].astype(float))
        if P == "P1":                                                    # native-label calibration of the existing V3 / V4 models (raw vs their own label)
            nc = len(cal)
            for model in ("V3", "V4"):
                for s in ("L", "S"):
                    kk = f"cal|{P}|{model}_{s}"
                    if kk not in z.files:
                        continue
                    yv = z[f"y{model}_{s}"].astype(float)
                    yv = np.where(yv < 0, np.nan, yv)
                    yc, yt = yv[:nc], yv[nc:]
                    rc, rt = z[kk].astype(float), z[f"test|{P}|{model}_{s}"].astype(float)
                    ok = np.isfinite(yc)
                    for method in METHODS:
                        c = MT.fit_calibrator_checked(method, anchor_all, cal[ok], rc[ok], yc[ok], t_cal0, t_test0, lms)
                        native.setdefault((model, s, method), []).append(c.predict(rt).astype(np.float32))
                    native.setdefault((model, s, "y"), []).append(yt)
                    native.setdefault((model, s, "coin"), []).append(np.full(nt, si, np.int8))
                    native.setdefault((model, s, "year"), []).append(np.full(nt, year, np.int16))
        log(f"assembled {P} {sym} {year}: test {nt} rows")
    ts = acc.cat()
    ts["day"] = ((ts["anchor"] - D.TEST_LO) // D.DAY).astype(np.int64)
    ts["prob"] = {k: np.concatenate(v) for k, v in pr.items()}
    ts["ev"] = {k: np.concatenate(v).astype(np.float32) for k, v in evacc.items()}
    ts["native"] = {k: np.concatenate(v) for k, v in native.items()}
    ts["meta"] = meta
    ts["P"] = P
    ts["H_ms"] = H_MS["P2" if P == "P2" else "P1"]
    return ts


def subset(ts: dict, mask: np.ndarray, keep_models=("Q",), keep_tgts=("A", "CL", "CS")) -> dict:
    """Row subset (P3 = the 15-minute anchors). Only the Q model's A / CL / CS probabilities are carried (memory)."""
    n = len(ts["anchor"])
    out = {}
    for k, v in ts.items():
        if k == "native":
            out[k] = {}
        elif isinstance(v, np.ndarray) and len(v) == n:
            out[k] = v[mask]
        elif k == "prob":
            out[k] = {kk: vv[mask] for kk, vv in v.items() if kk[0] in keep_models and kk[1] in keep_tgts}
        elif k == "ev":
            out[k] = {kk: vv[mask] for kk, vv in v.items() if kk[0] in keep_models}
        else:
            out[k] = v
    return out


# ------------------------------------------------------------------------------------------ item machinery
def verd_mask(ts) -> np.ndarray:
    return np.isin(ts["coin"], VERD)


def _by(ts, rowmask, num, den):
    """Effects by test year and by coin (verdict coins), plain sums."""
    res = {"year": {}, "coin": {}}
    for y in YEARS:
        m = rowmask & (ts["year"] == y) & np.isin(ts["coin"], VERD)
        d = den[m].sum(dtype=np.float64)
        res["year"][y] = float(num[m].sum(dtype=np.float64) / d) if d > 0 else None
    for c in VERD:
        m = rowmask & (ts["coin"] == c)
        d = den[m].sum(dtype=np.float64)
        res["coin"][SYMS[c]] = float(num[m].sum(dtype=np.float64) / d) if d > 0 else None
    return res


def make_item(ts, rowmask, value, key, den=None, B: int = MT.N_BOOT) -> dict:
    """Mean of `value` over rowmask (verdict coins), day-block bootstrap, plus effects by year / coin."""
    rowmask = rowmask & verd_mask(ts) & np.isfinite(value)
    den = np.ones(len(value)) if den is None else den
    num = np.where(rowmask, value, 0.0)
    dd = np.where(rowmask, den, 0.0)
    nd, dn = np.bincount(ts["day"], weights=num, minlength=N_DAYS), np.bincount(ts["day"], weights=dd, minlength=N_DAYS)
    r = MT.effect_stats(nd, dn, key, B)
    r.update(_by(ts, rowmask, num, dd))
    r["key"] = key
    return r


def pos_count(d: dict) -> int:
    return sum(1 for v in d.values() if v is not None and v > 0)


def verdict_item(r: dict, q, kind: str, extra_ok: bool = True) -> str:
    """LEAD / FAIL / INCONCLUSIVE (plan section 9). kinds: f1 (Brier), econ (F2 net per trade), f3 (paired difference, no year / coin / MDE condition for a LEAD)."""
    mat, mde_ok = (MAT_BRIER, MDE_BRIER) if kind == "f1" else (MAT_NET, MDE_NET)
    if r.get("effect") is None or r.get("se") is None or r.get("mde") is None:
        return "INCONCLUSIVE"
    if kind == "f3":
        lead = q is not None and q < 0.05 and r["ci90"][0] > 0 and r["effect"] >= mat
    else:
        lead = (q is not None and q < 0.05 and r["ci90"][0] > 0 and r["effect"] >= mat and pos_count(r["year"]) >= 2 and pos_count(r["coin"]) >= 2 and extra_ok
                and (kind == "f1" or r["mde"] <= mde_ok))
    if lead:
        return "LEAD"
    if r["ci90"][1] < mat and r["mde"] <= mde_ok:
        return "FAIL"
    return "INCONCLUSIVE"


def family(items: list, kind: str) -> list:
    qs = MT.bh([it["p"] for it in items])
    for it, q in zip(items, qs):
        it["q"] = float(q)
        it["verdict"] = verdict_item(it, float(q), kind, it.get("extra_ok", True))
    return items


# ------------------------------------------------------------------------------------------ calibration
def sq(p, y):
    return (p - y) ** 2


def ll(p, y):
    pc = np.clip(p, MT.CLIP, 1 - MT.CLIP)
    return -(y * np.log(pc) + (1 - y) * np.log(1 - pc))


def f1_items(ts, models, tgts_for, methods, tag: str) -> list:
    items = []
    for model in models:
        for tgt in tgts_for(model):
            for method in methods:
                if (model, tgt, method) not in ts["prob"]:
                    continue
                y = ts["y" + tgt].astype(float)
                p, pb = ts["prob"][(model, tgt, method)].astype(float), ts["prob"][(model, tgt, "base")].astype(float)
                valid = np.isfinite(y)
                d = np.where(valid, sq(pb, y) - sq(p, y), np.nan)
                dl = np.where(valid, ll(pb, y) - ll(p, y), np.nan)
                it = make_item(ts, valid, d, f"F1|{tag}|{model}|{tgt}|{method}")
                it.update(model=model, target=tgt, method=method, preset=tag)
                vm = valid & verd_mask(ts)
                it["logloss_delta"] = float(np.nanmean(dl[vm])) if vm.any() else None
                items.append(it)
    return items


def calibration_tables(ts, models, tgts_for, methods_full=("raw", "platt", "iso"), ci_models=("Q", "V3", "V4")) -> dict:
    """Pooled / per-year / per-coin point metrics for every (model, target, method); binned-bootstrap CIs for the primary items; reliability tables."""
    out: dict = {"pooled": [], "by_year": [], "by_coin": [], "reliability": {}, "ci": {}}
    vm = verd_mask(ts)
    for model in models:
        for tgt in tgts_for(model):
            y = ts["y" + tgt].astype(float)
            valid = np.isfinite(y) & vm
            if not valid.any():
                continue
            base = ts["prob"].get((model, tgt, "base"))
            for method in ("raw", "platt", "iso", "base", "c50"):
                if method != "c50" and (model, tgt, method) not in ts["prob"]:
                    continue
                p = np.full(len(y), 0.5) if method == "c50" else ts["prob"][(model, tgt, method)].astype(float)
                bp = float(base[valid].mean()) if base is not None else None
                m = MT.point_metrics(p[valid], y[valid], base_p=None)
                bb = float(np.mean((base[valid].astype(float) - y[valid]) ** 2))
                m["bss"] = float(1 - m["brier"] / bb) if bb > 0 else None
                m.update(model=model, target=tgt, method=method)
                out["pooled"].append(m)
                if method in methods_full:
                    for yr in YEARS:
                        mm = valid & (ts["year"] == yr)
                        if mm.sum() > 100:
                            r = MT.point_metrics(p[mm], y[mm])
                            bbm = float(np.mean((base[mm].astype(float) - y[mm]) ** 2))
                            r["bss"] = float(1 - r["brier"] / bbm) if bbm > 0 else None
                            r.update(model=model, target=tgt, method=method, year=yr)
                            out["by_year"].append(r)
                    for c in range(len(SYMS)):
                        mm = valid & (ts["coin"] == c) if c in VERD else (np.isfinite(y) & (ts["coin"] == c))
                        if mm.sum() > 100:
                            r = MT.point_metrics(p[mm], y[mm])
                            r.update(model=model, target=tgt, method=method, coin=SYMS[c])
                            out["by_coin"].append(r)
                if model in ci_models and method in ("raw", "platt"):
                    bs = MT.BinStats(p[valid], y[valid], ts["day"][valid], N_DAYS)
                    out["ci"][f"{model}|{tgt}|{method}"] = MT.binned_cal_ci(bs, f"cal|{ts['P']}|{model}|{tgt}|{method}")
                    out["reliability"][f"{model}|{tgt}|{method}"] = MT.reliability(p[valid], y[valid])
    return out


def cal_flags(pooled: list, ci: dict) -> list:
    """CAL_CALIBRATED(item): ECE (equal-mass) <= 0.02 AND slope CI contains 1 AND intercept CI contains 0 (plan section 6)."""
    res = []
    for m in pooled:
        k = f"{m['model']}|{m['target']}|{m['method']}"
        c = ci.get(k)
        if c is None:
            continue
        ok = (m["ece_mass"] <= 0.02 and c["slope_ci90"][0] <= 1 <= c["slope_ci90"][1] and c["intercept_ci90"][0] <= 0 <= c["intercept_ci90"][1])
        res.append({"item": k, "ece_mass": m["ece_mass"], "slope": m["slope"], "slope_ci90": c["slope_ci90"], "intercept": m["intercept"],
                    "intercept_ci90": c["intercept_ci90"], "CAL_CALIBRATED": bool(ok)})
    return res


def native_calibration(ts) -> list:
    """The existing V3 / V4 raw probability vs ITS OWN label (the question 'was the model probability ever calibrated?')."""
    rows = []
    nat = ts["native"]
    for model in ("V3", "V4"):
        for s in ("L", "S"):
            if (model, s, "y") not in nat:
                continue
            y, coin, year = nat[(model, s, "y")], nat[(model, s, "coin")], nat[(model, s, "year")]
            m = np.isfinite(y) & np.isin(coin, VERD)
            for method in METHODS:
                p = nat[(model, s, method)].astype(float)
                r = MT.point_metrics(p[m], y[m])
                bb = float(np.mean((nat[(model, s, "base")][m].astype(float) - y[m]) ** 2))
                r["bss"] = float(1 - r["brier"] / bb) if bb > 0 else None
                r.update(model=model, side=s, method=method)
                rows.append(r)
    return rows


def d_regression(ts) -> list:
    rows = []
    vm = verd_mask(ts)
    for s in ("L", "S"):
        if f"Dhat{s}" not in ts:
            continue
        x, y = ts[f"Dhat{s}"], ts["net" + s]
        for lab, m in [("pooled", vm)] + [(str(yr), vm & (ts["year"] == yr)) for yr in YEARS]:
            m = m & np.isfinite(y)
            if m.sum() < 100:
                continue
            slope, icpt = np.polyfit(x[m], y[m], 1)
            const = float(np.mean((y[m] - y[m].mean()) ** 2))
            mse = float(np.mean((y[m] - x[m]) ** 2))
            dec = np.clip(np.searchsorted(np.quantile(x[m], np.linspace(0, 1, 11)), x[m], side="right") - 1, 0, 9)
            rows.append({"side": s, "slice": lab, "n": int(m.sum()), "spearman": float(sps.spearmanr(x[m], y[m])[0]), "slope_realised_on_pred": float(slope),
                         "intercept": float(icpt), "mse_model": mse, "mse_const_sample_mean": const,
                         "deciles": [[int((dec == k).sum()), float(x[m][dec == k].mean()), float(y[m][dec == k].mean())] for k in range(10)]})
    return rows


# ------------------------------------------------------------------------------------------ decisions and economics
def decide(evl, evs, kind="EVp"):
    side = np.zeros(len(evl), np.int8)
    if kind == "SIDE":
        side[:] = np.where(evl >= evs, 1, -1)
        return side
    side[(evl > 0) & (evl >= evs)] = 1
    side[(evs > 0) & (evs > evl)] = -1
    return side


def policies(ts) -> dict:
    """name -> side array (+1 BUY, -1 SELL, 0 WAIT) for every pre-registered policy plus the rule baselines."""
    pol = {}
    for model in ("Q", "V3", "V4", "VOL", "TREND", "SOFT"):
        if (model, "EVp", "L") in ts["ev"]:
            pol[f"{model}-EVp"] = decide(ts["ev"][(model, "EVp", "L")], ts["ev"][(model, "EVp", "S")])
    if ("Q", "EVp", "L") in ts["ev"]:
        pol["Q-SIDE"] = decide(ts["ev"][("Q", "EVp", "L")], ts["ev"][("Q", "EVp", "S")], "SIDE")
    if ("Q", "EVD", "L") in ts["ev"]:
        pol["Q-EVD"] = decide(ts["ev"][("Q", "EVD", "L")], ts["ev"][("Q", "EVD", "S")])
    n = len(ts["anchor"])
    pol["RULE-B"] = ts["sigB"].astype(np.int8)
    pol["RULE-C"] = ts["sigC"].astype(np.int8)
    pol["ALWAYS-LONG"] = np.ones(n, np.int8)
    pol["ALWAYS-SHORT"] = -np.ones(n, np.int8)
    pol["G1a-ALWAYS-LONG"] = np.where(ts["M"] >= 0.28, 1, 0).astype(np.int8)
    return pol


def chosen_net(ts, side, cost: float = COST0, which: str = "X1"):
    a, b = ("netL", "netS") if which == "X1" else ("netBL", "netBS")
    net = np.where(side > 0, ts[a], np.where(side < 0, ts[b], np.nan))
    return net - (cost - COST0)


def chain_stats(ts, side, rowmask, cost=COST0):
    """Greedy non-overlapping chain per coin on rowmask rows; summary of the executed trades (one position at a time) + per-year net sums."""
    nets, years, dds, per_coin = [], [], [], {}
    H = ts["H_ms"]
    net_all = chosen_net(ts, side, cost)
    for c in range(len(SYMS)):
        if c not in VERD:
            continue
        m = rowmask & (ts["coin"] == c) & (side != 0) & np.isfinite(net_all)
        idx = np.nonzero(m)[0]
        if len(idx) == 0:
            continue
        ch = MT.greedy_chain(ts["anchor"][idx], side[idx], ts["anchor"][idx] + H)
        sel = idx[ch]
        nets.append(net_all[sel])
        years.append(ts["year"][sel])
        s = MT.trade_summary(net_all[sel])
        per_coin[SYMS[c]] = s
        dds.append(s["max_dd"])
    if not nets:
        return {"n": 0}
    net, yr = np.concatenate(nets), np.concatenate(years)
    out = MT.trade_summary(net)
    out["max_dd_worst_coin"] = max(dds)
    out["by_coin"] = per_coin
    ysum = {int(y): float(net[yr == y].sum()) for y in YEARS}
    tot_pos = sum(v for v in ysum.values() if v > 0)
    out["net_sum_by_year"] = ysum
    out["max_year_share_of_positive"] = float(max(v for v in ysum.values() if v > 0) / tot_pos) if tot_pos > 0 else None
    return out


def policy_item(ts, name, side, key) -> dict:
    mask = (side != 0)
    net = chosen_net(ts, side)
    r = make_item(ts, mask, net, key)
    r["policy"] = name
    vm = mask & verd_mask(ts) & np.isfinite(net)
    r["trades"] = int(vm.sum())
    r["coverage"] = float(vm.sum() / max(int(verd_mask(ts).sum()), 1))
    r["n_eff_blocks"] = MT.effective_n(ts["coin"][vm], ts["anchor"][vm], ts["H_ms"]) if vm.any() else 0
    r["n_days"] = int(len(np.unique(ts["day"][vm]))) if vm.any() else 0
    r["effect_cost"] = {}
    for cn, c in COSTS.items():
        nn = chosen_net(ts, side, c)
        r["effect_cost"][cn] = float(nn[vm].mean()) if vm.any() else None
    gr = np.where(side > 0, ts["gX1"], -ts["gX1"])
    r["gross"] = float(gr[vm].mean()) if vm.any() else None
    r["chain"] = chain_stats(ts, side, np.ones(len(side), bool))
    r["extra_ok"] = bool(vm.any() and r["effect_cost"]["code_0.14"] is not None and r["effect_cost"]["code_0.14"] >= 0)
    return r


def comparator(ts, side, kind, pol):
    """(rowmask, comparator net) for the paired comparison of a policy's trades."""
    nl, ns = ts["netL"], ts["netS"]
    if kind == "random":
        return side != 0, (nl + ns) / 2
    if kind == "opposite":
        return side != 0, np.where(side > 0, ns, nl)
    if kind == "always-long":
        return side != 0, nl
    if kind in ("RULE-B", "RULE-C"):
        o = pol[kind]
        return (side != 0) & (o != 0), np.where(o > 0, nl, ns)
    o = pol[kind]                                              # another model policy: WAIT counts as net 0
    return side != 0, np.where(o > 0, nl, np.where(o < 0, ns, 0.0))


def paired_item(ts, name, side, kind, pol, key) -> dict:
    mask, comp = comparator(ts, side, kind, pol)
    net = chosen_net(ts, side)
    r = make_item(ts, mask, net - comp, key)
    r.update(policy=name, comparator=kind)
    r["trades"] = int((mask & verd_mask(ts) & np.isfinite(net)).sum())
    return r


# ------------------------------------------------------------------------------------------ buckets
def bucket_rows(ts, conf, side, hit, net, gross, tag: str, rowmask=None, item_key: str | None = None, B: int = MT.N_BOOT) -> list:
    """One row per pre-registered confidence bucket for the CHOSEN side: counts, effective n, predicted vs observed, gross / net, win rate, PF, drawdown, coverage."""
    vm = verd_mask(ts) if rowmask is None else (rowmask & verd_mask(ts))
    vm = vm & np.isfinite(conf) & np.isfinite(net)
    bk = MT.bucket_of(conf)
    rows = []
    for k, name in enumerate(MT.BUCKET_NAMES):
        m = vm & (bk == k)
        n = int(m.sum())
        row = {"conf": tag, "bucket": name, "n": n, "coverage": n / max(int(vm.sum()), 1)}
        if n:
            row.update(n_eff_blocks=MT.effective_n(ts["coin"][m], ts["anchor"][m], ts["H_ms"]), n_days=int(len(np.unique(ts["day"][m]))),
                       mean_pred=float(conf[m].mean()), observed=float(hit[m].mean()), gross=float(gross[m].mean()), net=float(net[m].mean()),
                       win_rate=float((net[m] > 0).mean()), avg_win=float(net[m][net[m] > 0].mean()) if (net[m] > 0).any() else None,
                       avg_loss=float(net[m][net[m] < 0].mean()) if (net[m] < 0).any() else None)
            if n >= 30:
                ob = make_item(ts, m, hit.astype(float), f"bk|{tag}|{name}|obs", B=B)
                nt = make_item(ts, m, net, f"bk|{tag}|{name}|net", B=B)
                row.update(observed_ci90=ob["ci90"], net_ci90=nt["ci90"], net_p=nt["p"], net_se=nt["se"], net_year=nt["year"], net_coin=nt["coin"])
                s2 = np.where(side > 0, 1, -1).astype(np.int8)
                cs = chain_stats(ts, np.where(m, s2, 0).astype(np.int8), np.ones(len(m), bool)) if tag.startswith("A") or tag.startswith("C") else None
                if cs and cs.get("n"):
                    row.update(chain_n=cs["n"], pf=cs["pf"], max_dd=cs["max_dd_worst_coin"])
        rows.append(row)
    return rows


def confidence_tables(ts, method: str, B: int = MT.N_BOOT) -> dict:
    out = {}
    pr = ts["prob"]
    if ("Q", "A", method) in pr:
        pA = pr[("Q", "A", method)].astype(float)
        conf, side = np.maximum(pA, 1 - pA), np.where(pA >= 0.5, 1, -1)
        hit = np.where(side > 0, ts["yA"], 1 - ts["yA"])
        net = np.where(side > 0, ts["netL"], ts["netS"])
        gross = np.where(side > 0, ts["gX1"], -ts["gX1"])
        valid = np.isfinite(ts["yA"])
        out["A"] = bucket_rows(ts, conf, side, hit, net, gross, f"A|{method}", rowmask=valid, B=B)
    if all(("Q", t, method) in pr for t in ("CL", "CS")):
        pl, ps = pr[("Q", "CL", method)].astype(float), pr[("Q", "CS", method)].astype(float)
        conf, side = np.maximum(pl, ps), np.where(pl >= ps, 1, -1)
        hit = np.where(side > 0, ts["yCL"], ts["yCS"])
        net = np.where(side > 0, ts["netL"], ts["netS"])
        gross = np.where(side > 0, ts["gX1"], -ts["gX1"])
        out["C"] = bucket_rows(ts, conf, side, hit, net, gross, f"C|{method}", B=B)
    if all(("Q", t, method) in pr for t in ("BL", "BS")):
        pl, ps = pr[("Q", "BL", method)].astype(float), pr[("Q", "BS", method)].astype(float)
        conf, side = np.maximum(pl, ps), np.where(pl >= ps, 1, -1)
        hit = np.where(side > 0, ts["yBL"], ts["yBS"])
        net = np.where(side > 0, ts["netBL"], ts["netBS"])
        gross = np.where(side > 0, ts["gBL"], ts["gBS"])
        out["B"] = bucket_rows(ts, conf, side, hit, net, gross, f"B|{method}", B=B)
    return out


def ev_deciles(ts, kind="EVp") -> list:
    """E9: realised net of the better side by decile of the predicted EV (and the slope of net on EV)."""
    if ("Q", kind, "L") not in ts["ev"]:
        return []
    evl, evs = ts["ev"][("Q", kind, "L")], ts["ev"][("Q", kind, "S")]
    side = np.where(evl >= evs, 1, -1)
    ev = np.where(side > 0, evl, evs)
    net = chosen_net(ts, side)
    m = verd_mask(ts) & np.isfinite(net)
    q = np.quantile(ev[m], np.linspace(0, 1, 11))
    dec = np.clip(np.searchsorted(q, ev, side="right") - 1, 0, 9)
    rows = []
    for k in range(10):
        mm = m & (dec == k)
        if mm.any():
            rows.append({"decile": k + 1, "n": int(mm.sum()), "ev_pred": float(ev[mm].mean()), "net": float(net[mm].mean()), "win_rate": float((net[mm] > 0).mean())})
    slope = float(np.polyfit(ev[m], net[m], 1)[0]) if m.sum() > 100 else None
    return {"deciles": rows, "slope_net_on_ev": slope, "spearman": float(sps.spearmanr(ev[m], net[m])[0]) if m.sum() > 100 else None}


def study75(ts) -> list:
    """E13: events with calibrated confidence >= 0.75 (max(p, 1-p) for A; p for the side-specific targets), per model / target / probability type."""
    rows = []
    vm = verd_mask(ts)
    for (model, tgt, method), p in sorted(ts["prob"].items()):
        if method not in ("raw", "platt", "iso"):
            continue
        p = p.astype(float)
        y = ts["y" + tgt].astype(float)
        conf = np.maximum(p, 1 - p) if tgt == "A" else p
        m = vm & np.isfinite(y)
        hi = m & (conf >= 0.75)
        row = {"model": model, "target": tgt, "method": method, "n_ge_75": int(hi.sum()), "max_conf": float(conf[m].max()) if m.any() else None,
               "n_ge_60": int((m & (conf >= 0.60)).sum()), "n_ge_70": int((m & (conf >= 0.70)).sum()),
               "by_year": {str(yr): int((hi & (ts["year"] == yr)).sum()) for yr in YEARS},
               "by_coin": {SYMS[c]: int((hi & (ts["coin"] == c)).sum()) for c in VERD}}
        if hi.any():
            hitv = np.where(p >= 0.5, y, 1 - y) if tgt == "A" else y
            row["observed_rate"] = float(hitv[hi].mean())
            lo, up = MT.wilson(float(hitv[hi].sum()), float(hi.sum()))
            row["observed_wilson95"] = [lo, up]
            if tgt in ("A", "CL", "CS"):
                side = (np.where(p >= 0.5, 1, -1) if tgt == "A" else (1 if tgt == "CL" else -1) * np.ones(len(p)))
                net = np.where(side > 0, ts["netL"], ts["netS"])
                row["mean_net_pct"] = float(net[hi].mean())
        rows.append(row)
    return rows


# ------------------------------------------------------------------------------------------ top level
def run_all(out: Path, results: Path, log=print) -> dict:
    summ: dict = {"preset_meta": {}}
    sets = {}
    for P in ("P1", "P2"):
        ts = assemble(out, P, log)
        if "anchor" not in ts or len(ts["anchor"]) == 0:
            continue
        sets[P] = ts
        summ["preset_meta"][P] = ts["meta"]
    p1 = sets["P1"]
    sets["P3"] = subset(p1, p1["on15"].astype(bool))
    sets["P3"]["P"] = "P3"
    sets["P3"]["sigB"], sets["P3"]["sigC"] = _p3_signals(out, p1)
    sets["P3"]["M"] = _p3_m(out, p1)
    models_p1 = ["V3", "V4", "Q"] + list(ABL)
    tg1 = lambda m: TGTS if m in ("V3", "V4", "Q") else ABL_TGTS

    # ---- calibration (B) and F1
    log("F1 + calibration tables")
    f1 = f1_items(p1, ["V3", "V4", "Q"], lambda m: TGTS, ("platt", "iso"), "P1")
    f1 += f1_items(sets["P2"], ["Q"], lambda m: TGTS, ("platt", "iso"), "P2") if "P2" in sets else []
    f1 += f1_items(p1, list(ABL), lambda m: ABL_TGTS, ("platt",), "P1")
    family(f1, "f1")
    cal = {P: calibration_tables(ts, ["Q"] if P != "P1" else models_p1, (lambda m: TGTS) if P != "P1" else tg1) for P, ts in sets.items() if P != "P3"}
    p3m = calibration_tables(sets["P3"], ["Q"], lambda m: ("A", "CL", "CS"))
    summ["calibration"] = {"f1": f1, "tables": cal, "p3_tables": p3m, "native": native_calibration(p1), "d_regression": d_regression(p1),
                           "cal_flags": {P: cal_flags(cal[P]["pooled"], cal[P]["ci"]) for P in cal}}
    p1["native"] = {}
    gc.collect()

    # ---- buckets (C)
    log("buckets")
    summ["buckets"] = {"platt": confidence_tables(p1, "platt"), "raw": confidence_tables(p1, "raw"), "iso": confidence_tables(p1, "iso")}
    summ["buckets"]["by_year"] = {str(y): confidence_tables(subset(p1, p1["year"] == y), "platt", B=300) for y in YEARS}
    summ["ev_deciles"] = {"EVp": ev_deciles(p1, "EVp"), "EVD": ev_deciles(p1, "EVD")}
    summ["study75"] = study75(p1)

    # ---- decisions and economics (D, F)
    log("policies")
    pol1 = policies(p1)
    f2, f3 = [], []
    for name in ("Q-EVp", "Q-EVD", "Q-SIDE", "V3-EVp", "V4-EVp", "VOL-EVp", "TREND-EVp", "SOFT-EVp", "RULE-B", "RULE-C", "ALWAYS-LONG"):
        if name in pol1:
            f2.append(policy_item(p1, name, pol1[name], f"F2|P1|{name}"))
    if "P2" in sets:
        pol2 = policies(sets["P2"])
        if "Q-EVp" in pol2:
            it = policy_item(sets["P2"], "Q-EVp", pol2["Q-EVp"], "F2|P2|Q-EVp")
            it["preset"] = "P2"
            f2.append(it)
    # bucket items (CONF_A buckets of Q, Platt)
    for row in summ["buckets"]["platt"].get("A", []):
        k = row["bucket"]
        pA = p1["prob"].get(("Q", "A", "platt"))
        if pA is None:
            continue
        conf = np.maximum(pA, 1 - pA).astype(float)
        side = np.where(pA >= 0.5, 1, -1)
        m = (MT.bucket_of(conf) == MT.BUCKET_NAMES.index(k)) & np.isfinite(p1["yA"])
        it = policy_item(p1, f"Q-CONF_A[{k}]", np.where(m, side, 0).astype(np.int8), f"F2|P1|Q-CONF_A|{k}")
        f2.append(it)
    family(f2, "econ")
    for name, kinds in (("Q-EVp", ("random", "opposite", "always-long", "RULE-B", "RULE-C", "TREND-EVp", "VOL-EVp")), ("Q-SIDE", ("random",)),
                        ("V3-EVp", ("random",)), ("V4-EVp", ("random",)), ("Q-EVD", ("random",)), ("SOFT-EVp", ("random",))):
        if name not in pol1:
            continue
        for kd in kinds:
            if kd in pol1 or kd in ("random", "opposite", "always-long"):
                f3.append(paired_item(p1, name, pol1[name], kd, pol1, f"F3|P1|{name}|vs|{kd}"))
    family(f3, "f3")
    summ["families"] = {"F1": f1, "F2": f2, "F3": f3}

    # baselines / economics tables for every preset, every cost (E8, E10)
    log("economics tables")
    econ = {}
    for P in ("P1", "P2", "P3"):
        if P not in sets:
            continue
        pol = policies(sets[P])
        econ[P] = {}
        for name, side in pol.items():
            it = policy_item(sets[P], name, side, f"E8|{P}|{name}")
            it.pop("extra_ok", None)
            econ[P][name] = it
        # random-side null: both sides, equal weight
        ts = sets[P]
        vm = verd_mask(ts) & np.isfinite(ts["netL"])
        econ[P]["RANDOM-SIDE"] = {"policy": "RANDOM-SIDE", "trades": int(vm.sum()), "effect": float(((ts["netL"] + ts["netS"]) / 2)[vm].mean()),
                                  "gross": 0.0, "effect_cost": {cn: float(((ts["netL"] + ts["netS"]) / 2)[vm].mean() - (c - COST0)) for cn, c in COSTS.items()}}
    summ["economics"] = econ

    # walk-forward by year (E11) and coin
    log("walk-forward tables")
    wf = []
    for P, ts in sets.items():
        pol = policies(ts)
        for name in ("Q-EVp", "Q-SIDE", "V3-EVp", "V4-EVp", "ALWAYS-LONG", "RULE-C"):
            if name not in pol:
                continue
            for yr in YEARS:
                for c in range(len(SYMS)):
                    m = (ts["year"] == yr) & (ts["coin"] == c)
                    if not m.any():
                        continue
                    side = pol[name]
                    net = chosen_net(ts, side)
                    mm = m & (side != 0) & np.isfinite(net)
                    wf.append({"preset": P, "policy": name, "year": yr, "coin": SYMS[c], "rows": int(m.sum()), "trades": int(mm.sum()),
                               "net": float(net[mm].mean()) if mm.any() else None, "coverage": float(mm.sum() / m.sum())})
    summ["walkforward"] = wf
    summ["verdict"] = verdict(summ, p1)
    summ["sets_rows"] = {P: int(len(ts["anchor"])) for P, ts in sets.items()}
    return summ


def _p3_signals(out, p1):
    """Rule signals at the 15m chart for the P3 subset (stored in the event table as sigB_P3 / sigC_P3)."""
    sb, sc = np.zeros(len(p1["anchor"]), np.int8), np.zeros(len(p1["anchor"]), np.int8)
    for si, sym in enumerate(SYMS):
        m = p1["coin"] == si
        if not m.any():
            continue
        ev = np.load(out / "events" / f"{sym}.npz")
        sb[m], sc[m] = ev["sigB_P3"][p1["idx"][m]], ev["sigC_P3"][p1["idx"][m]]
    on = p1["on15"].astype(bool)
    return sb[on], sc[on]


def _p3_m(out, p1):
    m_ = np.full(len(p1["anchor"]), np.nan)
    for si, sym in enumerate(SYMS):
        m = p1["coin"] == si
        if not m.any():
            continue
        ev = np.load(out / "events" / f"{sym}.npz")
        m_[m] = ev["M_P3"][p1["idx"][m]]
    return m_[p1["on15"].astype(bool)]


# ------------------------------------------------------------------------------------------ verdict (plan section 9 + AMENDMENT 1)
def verdict(summ: dict, p1: dict) -> dict:
    f1, f2, f3 = summ["families"]["F1"], summ["families"]["F2"], summ["families"]["F3"]
    flags = {f["item"]: f for f in summ["calibration"]["cal_flags"]["P1"]}
    q_f1 = [i for i in f1 if i["model"] == "Q" and i["preset"] == "P1" and i["target"] in ("A", "CL", "CS")]
    f1_lead = [i for i in q_f1 if i["verdict"] == "LEAD"]
    cal_ok = [i for i in f1_lead if flags.get(f"Q|{i['target']}|{i['method']}", {}).get("CAL_CALIBRATED")]
    f2d = {i["policy"]: i for i in f2 if i["key"].startswith("F2|P1|") and "CONF_A" not in i["key"]}
    f3d = {(i["policy"], i["comparator"]): i for i in f3}
    prim = f2d.get("Q-EVp")
    prim_f3 = f3d.get(("Q-EVp", "random"))
    side_f2, side_f3 = f2d.get("Q-SIDE"), f3d.get(("Q-SIDE", "random"))
    chain = prim.get("chain", {}) if prim else {}
    econ_pass = bool(prim and prim_f3 and prim["verdict"] == "LEAD" and prim_f3["verdict"] == "LEAD" and prim["trades"] >= 1000 and prim["n_eff_blocks"] >= 300
                     and (chain.get("pf") or 0) >= 1.15 and (chain.get("max_year_share_of_positive") or 1) <= 0.5)
    secondary_lead = [n for n in ("Q-EVD", "V3-EVp", "V4-EVp", "VOL-EVp", "TREND-EVp", "SOFT-EVp") if n in f2d and f2d[n]["verdict"] == "LEAD"
                      and f3d.get((n, "random")) is not None and f3d[(n, "random")]["verdict"] == "LEAD"]
    wait_dominant = bool(prim and prim["trades"] < 1000)
    if econ_pass and cal_ok:
        final = "PASS"
    elif prim and not wait_dominant and prim["verdict"] == "FAIL" and not secondary_lead:
        final = "FAIL"
    elif wait_dominant and side_f2 and side_f3 and side_f2["verdict"] == "FAIL" and side_f3["verdict"] == "FAIL" and not secondary_lead:
        final = "FAIL"
    else:
        final = "INCONCLUSIVE"
    return {"final": final, "econ_pass": econ_pass, "wait_dominant": wait_dominant, "primary_trades": prim["trades"] if prim else None,
            "primary": {k: prim.get(k) for k in ("effect", "ci90", "p", "q", "mde", "verdict", "trades", "n_eff_blocks", "coverage", "gross")} if prim else None,
            "primary_vs_random": {k: prim_f3.get(k) for k in ("effect", "ci90", "p", "q", "mde", "verdict")} if prim_f3 else None,
            "q_side": {k: side_f2.get(k) for k in ("effect", "ci90", "p", "q", "mde", "verdict", "trades")} if side_f2 else None,
            "q_side_vs_random": {k: side_f3.get(k) for k in ("effect", "ci90", "p", "q", "mde", "verdict")} if side_f3 else None,
            "f1_q_leads": [f"{i['target']}|{i['method']}" for i in f1_lead], "f1_q_leads_calibrated": [f"{i['target']}|{i['method']}" for i in cal_ok],
            "f1_leads_all": [i["key"] for i in f1 if i["verdict"] == "LEAD"], "f2_leads": [i["key"] for i in f2 if i["verdict"] == "LEAD"],
            "f3_leads": [i["key"] for i in f3 if i["verdict"] == "LEAD"], "secondary_leads": secondary_lead,
            "calibration_verdict": "CALIBRATED_AND_INFORMATIVE" if cal_ok else ("INFORMATIVE_NOT_CALIBRATED" if f1_lead else "NOT_INFORMATIVE"),
            "economics_verdict": "ECON_PASS" if econ_pass else ("WAIT_DOMINANT" if wait_dominant else (prim["verdict"] if prim else "NA"))}
