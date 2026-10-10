"""Per-coin replay job (plan sections 2-4): builds the X1 chains, simulates every exit arm on the same entries, applies the gates and writes compact per-day
aggregates. Net = gross - cost - f, f = side x actual funding. Per-day arrays have columns [n, sum gross, sum f]; the cost arms are linear and derived later."""

from __future__ import annotations

import datetime as dt
import time
import zlib

import numpy as np
from scipy import stats as sps

from edge_search.core import DAY, outcomes_time, select_trades
from regime_v1 import data as RD
from regime_v1 import features as F

from . import arms as A
from . import paths as P

WINDOW_LO, CUT = RD.WINDOW_LO, RD.CUT
N_DAYS = int((CUT - WINDOW_LO) // DAY)
_Y = [int(dt.datetime(y, 1, 1, tzinfo=dt.timezone.utc).timestamp() * 1000) for y in (2023, 2024, 2025, 2026)]
YEAR_START_DAY = np.array([(t - WINDOW_LO) // DAY for t in _Y], np.int64)       # 0, 365, 731, 1096
YEARS = (2023, 2024, 2025)
PRIMARY = A.COST_MEAS


def year_of_day(day):
    return np.searchsorted(YEAR_START_DAY, np.asarray(day), side="right") - 1


def day_array(day, n, g, f):
    return np.stack([np.bincount(day, weights=n, minlength=N_DAYS), np.bincount(day, weights=g, minlength=N_DAYS),
                     np.bincount(day, weights=f, minlength=N_DAYS)], axis=1)


def seed_sides(sym: str, preset: str, tag: str, n: int, S: int = A.S_SEEDS) -> np.ndarray:
    """(S, n) bool, True = long. Seed s (1..S) = default_rng([s, crc32(coin|preset|tag)]) so every seed is reproducible on its own."""
    key = zlib.crc32(f"{sym}|{preset}|{tag}".encode())
    return np.stack([np.random.default_rng([s, key]).random(n) < 0.5 for s in range(1, S + 1)])


# ------------------------------------------------------------------------------------------ exit tables on a chain
def exit_tables(coin, b5, entry_t, H5: int, R_frac, tab) -> dict:
    """For every exit arm and both sides: gross %, reason, exit bar, signed funding term fL / fS (net = g - c - f) on the SAME entries."""
    entry_t = np.asarray(entry_t, np.int64)
    e0 = P.entry_index(b5, entry_t)
    W = P.windows(b5, e0, H5)
    day_abs = ((entry_t - b5.t0) // DAY).astype(np.int64)
    out = {}
    for name in A.EXITS:
        d = {}
        for side, tag in ((1, "L"), (-1, "S")):
            tab_row = None
            if A.EXITS[name]["kind"] == "mfe":
                tab_row = tab[day_abs, 0 if side > 0 else 1]
                if not np.isfinite(tab_row).all():
                    raise RuntimeError("X4 quantile table has NaN for a chain trade (insufficient history)")
            kw = A.exit_params(name, R_frac, tab_row)
            r = P.simulate(W, side, **kw)
            xt = P.exit_times(entry_t, r["exit_k"])
            fund = coin.funding(entry_t, xt)
            d["g" + tag], d["r" + tag], d["k" + tag] = r["gross"], r["reason"], r["exit_k"]
            d["f" + tag] = fund if side > 0 else -fund
        out[name] = d
    return out


# ------------------------------------------------------------------------------------------ variants
def det_variant(ex: dict, s, mL, mS, day):
    s = np.asarray(s)
    up = s > 0
    g, f, r = np.where(up, ex["gL"], ex["gS"]), np.where(up, ex["fL"], ex["fS"]), np.where(up, ex["rL"], ex["rS"])
    m = np.where(up, mL, mS)
    w = m.astype(float)
    arr = day_array(day, w, g * w, f * w)
    reasons = np.bincount(r[m], minlength=6).astype(float)
    net = g - f - PRIMARY
    sk = ~m
    los, win = sk & (net < 0), sk & (net > 0)
    gx = np.array([los.sum(), net[los].sum(), win.sum(), net[win].sum(), m.sum(), len(m)], float)
    kn = net[m]
    if len(kn) > 1:
        eq = np.r_[0.0, np.cumsum(kn)]
        tail = np.array([kn.min(), np.quantile(kn, 0.01), np.quantile(kn, 0.05), kn.std(ddof=1), (np.maximum.accumulate(eq) - eq).max()])
    else:
        tail = np.full(5, np.nan)
    return arr, reasons, gx, tail


def rnd_variant(ex: dict, SL, mL, mS, day):
    """Random sides: SL (S, n) bool long. Per-day sums over all seeds + per-seed totals + reason counts (seed-summed)."""
    S = SL.shape[0]
    nL = SL.sum(axis=0).astype(float)
    nS = S - nL
    wL, wS = nL * mL, nS * mS
    arr = day_array(day, wL + wS, wL * ex["gL"] + wS * ex["gS"], wL * ex["fL"] + wS * ex["fS"])
    SLf = SL.astype(np.float64)
    NSf = 1.0 - SLf
    vL, vS = mL.astype(float), mS.astype(float)
    seed = np.stack([SLf @ vL + NSf @ vS, SLf @ (vL * ex["gL"]) + NSf @ (vS * ex["gS"]), SLf @ (vL * ex["fL"]) + NSf @ (vS * ex["fS"])], axis=1)
    reasons = np.zeros(6)
    for r in range(6):
        reasons[r] = float((wL * (ex["rL"] == r)).sum() + (wS * (ex["rS"] == r)).sum())
    return arr, reasons, seed


def binary_det(g, yr):
    out = np.zeros((3, 3))
    for i in range(3):
        m = yr == i
        out[i] = [(g[m] > 0).sum(), (g[m] < 0).sum(), (g[m] == 0).sum()]
    return out


def binary_rnd(gL, gS, SL, yr):
    S = SL.shape[0]
    SLf = SL.astype(np.float64)
    NSf = 1.0 - SLf
    out = np.zeros((S, 3, 3))
    cmps = (lambda x: x > 0, lambda x: x < 0, lambda x: x == 0)
    for i in range(3):
        m = (yr == i).astype(float)
        for j, cmp in enumerate(cmps):
            out[:, i, j] = SLf @ (m * cmp(gL)) + NSf @ (m * cmp(gS))
    return out.reshape(S, 9)


# ------------------------------------------------------------------------------------------ calibration (d)
def calibration(b5, b, R, ct, h: int, H5: int, valid) -> dict:
    """Predicted max move 0.55 R versus realised |return| and best excursion over the horizon, all valid in-window signal bars."""
    idx = np.nonzero(valid & np.isfinite(R) & (ct >= WINDOW_LO) & (ct + h * b.tf_ms <= CUT))[0]
    if len(idx) < 100:
        return {}
    M = A.expected_move(R)[idx]
    ent = ct[idx]
    e0 = P.entry_index(b5, ent)
    ret = np.empty(len(idx))
    exc = np.empty(len(idx))
    for a in range(0, len(idx), 40_000):
        sl = slice(a, a + 40_000)
        Wo, Wh, Wl, Wc = P.windows(b5, e0[sl], H5)
        E = Wo[:, 0]
        ret[sl] = np.abs(Wc[:, -1] / E - 1.0) * 100.0
        exc[sl] = np.maximum(Wh.max(axis=1) / E - 1.0, 1.0 - Wl.min(axis=1) / E) * 100.0
    out = {"n": int(len(idx)), "spearman_M_absret": float(sps.spearmanr(M, ret)[0]), "spearman_M_exc": float(sps.spearmanr(M, exc)[0]),
           "mean_M": float(M.mean()), "mean_absret": float(ret.mean()), "mean_exc": float(exc.mean())}
    q = np.quantile(M, np.linspace(0, 1, 11))
    dec = np.clip(np.searchsorted(q, M, side="right") - 1, 0, 9)
    rows = []
    for d in range(10):
        m = dec == d
        rows.append([int(m.sum()), float(M[m].mean()), float(ret[m].mean()), float(exc[m].mean()), float((ret[m] >= 0.28).mean()),
                     float((ret[m] >= 0.14).mean())])
    out["deciles"] = rows
    for name, thr in (("G1a", A.K_CODE * A.CODE_COST), ("G1b", A.K_ALT * A.CODE_COST)):
        pas = M >= thr
        fl = ~pas
        out[name] = {"share_pass": float(pas.mean()),
                     "pass": [int(pas.sum()), float(ret[pas].mean()) if pas.any() else None, float((ret[pas] >= 0.28).mean()) if pas.any() else None,
                              float((ret[pas] >= 0.14).mean()) if pas.any() else None],
                     "fail": [int(fl.sum()), float(ret[fl].mean()) if fl.any() else None, float((ret[fl] >= 0.28).mean()) if fl.any() else None,
                              float((ret[fl] >= 0.14).mean()) if fl.any() else None]}
    return out


# ------------------------------------------------------------------------------------------ one coin
def run_coin(work, sym: str, log=print, only_presets=None, coin=None) -> tuple[dict, dict]:
    t0 = time.time()
    coin = coin if coin is not None else RD.build_coin(work, sym, tfs=("5m", "15m"))
    world = RD.World({sym: coin}, work)
    b5 = coin.bars["5m"]
    feats = {tf: F.compute(coin.bars[tf].o, coin.bars[tf].h, coin.bars[tf].l, coin.bars[tf].c) for tf in ("5m", "15m")}
    log(f"{sym}: features {time.time() - t0:.0f}s")
    tabs: dict = {}
    arrays: dict = {}
    meta: dict = {"coin": sym, "calibration": {}, "n_trades": {}}
    for pname, (tf, h, H5, binary_only) in A.PRESETS.items():
        if only_presets and pname not in only_presets:
            continue
        b = coin.bars[tf]
        ct = b.close_time
        feat = feats[tf]
        R = A.range_unit(feat["atrp"], h)
        Rfrac = R / 100.0
        out1 = outcomes_time(b, h)
        fin = np.isfinite(R)
        if not binary_only:
            if H5 not in tabs:
                tabs[H5] = P.mae_mfe_table(b5, H5)
            # burn-in: a coin listed inside the window has no 30-day MAE / MFE history in its first days; such entries are dropped from EVERY arm (so the arms stay
            # entry-matched). No effect for coins with warm-up data (BTC, ETH, BNB).
            okd = np.isfinite(tabs[H5]).all(axis=(1, 2, 3))
            dd = np.clip((ct - b5.t0) // DAY, 0, len(okd) - 1).astype(np.int64)
            fin = fin & okd[dd]
        sigs = A.signals(world, sym, tf, h)
        chains = {
            "always": select_trades(np.where(fin, 1, 0).astype(np.int8), out1, ct, WINDOW_LO, CUT),
            "B": select_trades(np.where(fin, sigs["B"], 0).astype(np.int8), out1, ct, WINDOW_LO, CUT),
            "C": select_trades(np.where(fin, sigs["C"], 0).astype(np.int8), out1, ct, WINDOW_LO, CUT),
        }
        meta["n_trades"][pname] = {k: int(len(v)) for k, v in chains.items()}
        if not binary_only:
            meta["calibration"][pname] = calibration(b5, b, R, ct, h, H5, out1.valid)
        for cname, tr in chains.items():
            if len(tr) == 0:
                continue
            day = ((tr.entry_t - WINDOW_LO) // DAY).astype(np.int64)
            yr = year_of_day(day)
            if binary_only:
                e0 = P.entry_index(b5, tr.entry_t)
                W = P.windows(b5, e0, H5)
                gL = P.simulate(W, 1)["gross"]
                gS = P.simulate(W, -1)["gross"]
                if cname == "always":
                    SL = seed_sides(sym, pname, "A", len(tr))
                    arrays[f"{pname}|A|bin"] = binary_rnd(gL, gS, SL, yr)
                    arrays[f"{pname}|EL|bin"] = binary_det(gL, yr)
                    arrays[f"{pname}|ES|bin"] = binary_det(gS, yr)
                else:
                    arrays[f"{pname}|{cname}|bin"] = binary_det(np.where(tr.side > 0, gL, gS), yr)
                continue
            tab = tabs[H5]
            ex = exit_tables(coin, b5, tr.entry_t, H5, Rfrac[tr.idx], tab)
            if cname == "always":
                masks = A.gate_masks(R, feat, tr.idx, "trend")
                SL = seed_sides(sym, pname, "A", len(tr))
                for xn, e in ex.items():
                    for gn in A.GATES:
                        mL, mS = masks[gn]
                        arr, rs, seed = rnd_variant(e, SL, mL, mS, day)
                        k = f"{pname}|A|{xn}|{gn}"
                        arrays[k], arrays[k + "|rs"], arrays[k + "|seed"] = arr, rs, seed
                for arm, s in (("EL", np.ones(len(tr), np.int8)), ("ES", -np.ones(len(tr), np.int8))):
                    for xn, e in ex.items():
                        arr, rs, gx, tail = det_variant(e, s, masks["G0"][0], masks["G0"][1], day)
                        k = f"{pname}|{arm}|{xn}|G0"
                        arrays[k], arrays[k + "|rs"], arrays[k + "|tail"] = arr, rs, tail
                    for gn in A.GATES_MAIN:
                        if gn == "G2m":
                            continue
                        arr, rs, gx, tail = det_variant(ex["X1"], s, *masks[gn], day)
                        k = f"{pname}|{arm}|X1|{gn}"
                        arrays[k], arrays[k + "|rs"], arrays[k + "|gx"], arrays[k + "|tail"] = arr, rs, gx, tail
                arrays[f"{pname}|A|bin"] = binary_rnd(ex["X1"]["gL"], ex["X1"]["gS"], SL, yr)
                arrays[f"{pname}|EL|bin"] = binary_det(ex["X1"]["gL"], yr)
                arrays[f"{pname}|ES|bin"] = binary_det(ex["X1"]["gS"], yr)
            else:
                cls = "trend" if cname == "B" else "meanrev"
                masks = A.gate_masks(R, feat, tr.idx, cls)
                s = tr.side
                for xn, e in ex.items():
                    arr, rs, gx, tail = det_variant(e, s, masks["G0"][0], masks["G0"][1], day)
                    k = f"{pname}|{cname}|{xn}|G0"
                    arrays[k], arrays[k + "|rs"], arrays[k + "|tail"] = arr, rs, tail
                for gn in A.GATES_MAIN:
                    arr, rs, gx, tail = det_variant(ex["X1"], s, *masks[gn], day)
                    k = f"{pname}|{cname}|X1|{gn}"
                    arrays[k], arrays[k + "|rs"], arrays[k + "|gx"], arrays[k + "|tail"] = arr, rs, gx, tail
                SLn = seed_sides(sym, pname, "N" + cname, len(tr))                      # matched null: SAME entries, random sides, every exit x gate
                for xn, e in ex.items():
                    for gn in A.GATES:
                        if gn != "G0" and xn != "X1":
                            continue
                        if gn not in masks:
                            continue
                        mL, mS = masks[gn]
                        arr, rs, seed = rnd_variant(e, SLn, mL, mS, day)
                        k = f"{pname}|{cname}null|{xn}|{gn}"
                        arrays[k], arrays[k + "|rs"], arrays[k + "|seed"] = arr, rs, seed
                arrays[f"{pname}|{cname}|bin"] = binary_det(np.where(s > 0, ex["X1"]["gL"], ex["X1"]["gS"]), yr)
                arrays[f"{pname}|{cname}null|bin"] = binary_rnd(ex["X1"]["gL"], ex["X1"]["gS"], SLn, yr)
        log(f"{sym}: preset {pname} done {time.time() - t0:.0f}s; trades {meta['n_trades'][pname]}")
    return arrays, meta
