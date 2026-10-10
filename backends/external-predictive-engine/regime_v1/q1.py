"""Q1: does the regime label carry information about FORWARD behaviour? (plan section 5, Q1)

Per coin, label timeframe and forward horizon cell: per-regime forward return / hit rate / absolute move / realised volatility versus the
unconditional windows, with a circular-shift label null (1,000 lags >= 7 days) and per-year breakdown. Non-overlapping windows
(index % H == 0). Forward window = open of bar t+1 .. close of bar t+H.
"""

from __future__ import annotations

import zlib

import numpy as np

from edge_search.core import outcomes_time

from . import data as D
from .features import C0, NAMES

CELLS = [(tf, H) for tf in ("15m", "1h", "4h") for H in (1, 4)]
HTF = {"15m": "1h", "1h": "4h", "4h": "1d"}
N_NULL = 1000
YEARS = (2023, 2024, 2025)
NREG = 9


def years_of(t_ms):
    return np.asarray(t_ms, "datetime64[ms]").astype("datetime64[Y]").astype(int) + 1970


def forward_rv(c5, k: int, n: int, H: int) -> np.ndarray:
    """Realised vol (%) over the forward window of tf bar t (5m indices (t+1)k .. (t+H+1)k-1), sqrt of summed squared 5m log returns."""
    lr = np.log(c5)
    r = np.r_[np.nan, np.diff(lr)]
    bad = ~np.isfinite(r)
    cs = np.r_[0.0, np.cumsum(np.where(bad, 0.0, r * r))]
    cb = np.r_[0, np.cumsum(bad)]
    t = np.arange(n)
    a, b = (t + 1) * k, np.minimum((t + H + 1) * k, len(c5))
    ok = (t + H + 1) * k <= len(c5)
    rv = np.full(n, np.nan)
    good = ok & ((cb[np.minimum(b, len(c5))] - cb[np.minimum(a, len(c5))]) == 0)
    rv[good] = np.sqrt(cs[b[good]] - cs[a[good]]) * 100.0
    return rv


def window_arrays(coin, tf: str, H: int):
    """g (long forward return %), rv, mask (non-overlapping, in window, valid), close_time of the signal bar."""
    b = coin.bars[tf]
    out = outcomes_time(b, H)
    g = out.g_long
    rv = forward_rv(coin.bars["5m"].c, b.tf_min // 5, b.n, H)
    ct = b.close_time
    mask = out.valid & np.isfinite(g) & np.isfinite(rv) & (ct >= D.WINDOW_LO) & (np.arange(b.n) % H == 0)
    return g, rv, mask, ct


def _stats(lab, g, rv, mask):
    """Per-regime sums over the masked windows."""
    L, G, V = lab[mask], g[mask], rv[mask]
    n = np.bincount(L, minlength=NREG).astype(float)
    sg = np.bincount(L, weights=G, minlength=NREG)
    sa = np.bincount(L, weights=np.abs(G), minlength=NREG)
    sv = np.bincount(L, weights=V, minlength=NREG)
    sp = np.bincount(L, weights=(G > 0).astype(float), minlength=NREG)
    sc = np.bincount(L, weights=(np.abs(G) > C0).astype(float), minlength=NREG)
    return n, sg, sa, sv, sp, sc


def cell_stats(lab, g, rv, mask, ct, seed_key: str, n_null: int = N_NULL):
    """Statistics for one cell. lab: int8 regime code per bar (aligned to g)."""
    lab = np.asarray(lab).astype(np.int64)
    pos = np.nonzero(mask)[0]
    if len(pos) < 50:
        return {"n_all": int(len(pos)), "note": "too few windows"}
    lo_i, hi_i = int(pos.min()), int(pos.max()) + 1
    Ls = hi_i - lo_i
    lab_s = lab[lo_i:hi_i]
    p = pos - lo_i
    G, V = g[pos], rv[pos]
    A = np.abs(G)
    n_all = len(pos)
    mg_all, ma_all, mv_all, hit_all = G.mean(), A.mean(), V.mean(), (G > 0).mean()
    n, sg, sa, sv, sp, sc = _stats(lab, g, rv, mask)
    with np.errstate(invalid="ignore", divide="ignore"):
        mg, ma, mv, hit, gtc = sg / n, sa / n, sv / n, sp / n, sc / n
    # null: label at window position q taken from (q - s) mod Ls
    rng = np.random.default_rng(zlib.crc32(seed_key.encode()))
    bars_per_week = max(1, int(round(7 * 86_400_000 / (ct[1] - ct[0]))))
    lo_s, hi_s = bars_per_week, max(bars_per_week + 1, Ls - bars_per_week)
    shifts = rng.integers(lo_s, hi_s, size=n_null) if hi_s > lo_s else rng.integers(1, max(2, Ls), size=n_null)
    nd, nm, nh = np.full((n_null, NREG), np.nan), np.full((n_null, NREG), np.nan), np.full((n_null, NREG), np.nan)
    Gp = (G > 0).astype(float)
    for i, s in enumerate(shifts):
        L = lab_s[(p - s) % Ls]
        cnt = np.bincount(L, minlength=NREG).astype(float)
        with np.errstate(invalid="ignore", divide="ignore"):
            nd[i] = np.bincount(L, weights=G, minlength=NREG) / cnt - mg_all
            nm[i] = np.bincount(L, weights=A, minlength=NREG) / cnt / ma_all
            nh[i] = np.bincount(L, weights=Gp, minlength=NREG) / cnt - hit_all
    yrs = years_of(ct[pos])
    out = {"n_all": int(n_all), "mean_g_all": float(mg_all), "mean_abs_all": float(ma_all), "mean_rv_all": float(mv_all),
           "hit_all": float(hit_all), "sd_g_all": float(G.std(ddof=1)), "share_gt_c0_all": float((A > C0).mean()), "regimes": {}}
    for r in range(NREG):
        if n[r] < 1:
            out["regimes"][NAMES[r]] = {"n": 0}
            continue
        sel = lab[pos] == r
        d_obs, R_obs, h_obs = mg[r] - mg_all, ma[r] / ma_all, hit[r] - hit_all
        res = {"n": int(n[r]), "share": float(n[r] / n_all), "mean_g": float(mg[r]), "hit": float(hit[r]), "mean_abs": float(ma[r]),
               "mean_rv": float(mv[r]), "share_gt_c0": float(gtc[r]), "abs_over_c0": float(ma[r] / C0), "dD": float(d_obs),
               "R": float(R_obs), "R_rv": float(mv[r] / mv_all), "hit_diff": float(h_obs),
               "sd_g": float(G[sel].std(ddof=1)) if n[r] > 1 else None,
               "mde_g": float(2.8 * G[sel].std(ddof=1) / np.sqrt(n[r])) if n[r] > 1 else None}
        ok = n[r] >= 30
        def pv(null, obs, fn=lambda x: x):
            x = null[np.isfinite(null[:, r]), r]
            if len(x) < 100:
                return None
            return float((1 + np.sum(np.abs(fn(x)) >= abs(fn(obs)) - 1e-15)) / (len(x) + 1))
        res["p_dir"] = pv(nd, d_obs) if ok else 1.0
        res["p_mag"] = pv(nm, R_obs, np.log) if (ok and R_obs > 0) else 1.0
        res["p_hit"] = pv(nh, h_obs) if ok else 1.0
        by = {}
        for y in YEARS:
            m = sel & (yrs == y)
            ya = yrs == y
            ny = int(m.sum())
            if ny >= 1 and ya.sum() >= 1:
                by[str(y)] = {"n": ny, "dD": float(G[m].mean() - G[ya].mean()), "R": float(A[m].mean() / A[ya].mean()),
                              "mean_g": float(G[m].mean())}
            else:
                by[str(y)] = {"n": ny, "dD": None, "R": None, "mean_g": None}
        res["by_year"] = by
        out["regimes"][NAMES[r]] = res
    return out
