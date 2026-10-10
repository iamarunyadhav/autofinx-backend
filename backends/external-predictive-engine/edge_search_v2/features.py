"""Edge Search v2: derived causal features on the 5-minute grid (plan section 2).

Every value at index t uses buckets <= t only (closed buckets). The large-trade threshold of UTC day D comes from day D-1
only. z-scores use the previous 2016 buckets (7 days) EXCLUDING t.
"""

from __future__ import annotations

import datetime as dt
from pathlib import Path

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw

from . import depth as depthmod
from .flow import M5, N_BINS

BPD = 288                      # buckets per day
W1H = 12
Z_WIN = 2016
Z_MIN = 1440
DEPTH_MIN_BUCKETS = 6


# ------------------------------------------------------------------------------------------ loading
def load_flow(edge: Path, symbol: str, months: list[str]) -> tuple[dict, dict]:
    from v4 import binance_data as bd
    parts, hparts = [], []
    for m in months:
        base = edge / "features" / symbol / "flow"
        parts.append(bd.read_parquet(base / f"{m}.parquet"))
        with np.load(base / f"{m}.npz") as z:
            hparts.append({k: z[k] for k in z.files})
    flow = {k: np.concatenate([p[k] for p in parts]) for k in parts[0]}
    hist = {k: np.concatenate([p[k] for p in hparts]) for k in hparts[0]}
    return flow, hist


def load_depth(edge: Path, symbol: str, t0_ms: int, n5: int, days: list[dt.date]) -> dict:
    """Depth columns on the full grid (NaN where a day is missing)."""
    from v4 import binance_data as bd
    out = {}
    for d in days:
        p = edge / "features" / symbol / "depth" / f"{d.isoformat()}.parquet"
        if not p.exists():
            continue
        cols = bd.read_parquet(p)
        i0 = int((cols["bucket_open_ms"][0] - t0_ms) // M5)
        if i0 < 0 or i0 + len(cols["bucket_open_ms"]) > n5:
            continue
        for k, v in cols.items():
            if k == "bucket_open_ms":
                continue
            if k not in out:
                out[k] = np.full(n5, np.nan)
            out[k][i0:i0 + len(v)] = v
    return out


# ------------------------------------------------------------------------------------------ rolling helpers
def roll_sum(x: np.ndarray, w: int = W1H) -> np.ndarray:
    """Sum of x[t-w+1..t]; NaN if any element is NaN or the window is incomplete."""
    n = len(x)
    out = np.full(n, np.nan)
    if n >= w:
        out[w - 1:] = sw(x, w).sum(axis=1)
    return out


def roll_mean_min(x: np.ndarray, w: int, minp: int) -> np.ndarray:
    n = len(x)
    out = np.full(n, np.nan)
    if n >= w:
        win = sw(x, w)
        fin = np.isfinite(win)
        cnt = fin.sum(axis=1)
        s = np.where(fin, win, 0.0).sum(axis=1)
        out[w - 1:] = np.where(cnt >= minp, s / np.maximum(cnt, 1), np.nan)
    return out


def z_trailing(x: np.ndarray, win: int = Z_WIN, minp: int = Z_MIN, chunk: int = 2048) -> np.ndarray:
    """z_t = (x_t - mean(x_{t-win..t-1})) / sd(x_{t-win..t-1}), ddof=1, needs >= minp finite values, sd 0 -> NaN."""
    n = len(x)
    xp = np.r_[np.full(win, np.nan), np.asarray(x, float)]
    windows = sw(xp, win)[:n]            # windows[t] = xp[t:t+win] = x[t-win:t]
    out = np.full(n, np.nan)
    for a in range(0, n, chunk):
        w = windows[a:a + chunk]
        fin = np.isfinite(w)
        cnt = fin.sum(axis=1)
        wz = np.where(fin, w, 0.0)
        mean = wz.sum(axis=1) / np.maximum(cnt, 1)
        dev = np.where(fin, w - mean[:, None], 0.0)
        sd = np.sqrt((dev ** 2).sum(axis=1) / np.maximum(cnt - 1, 1))
        ok = (cnt >= minp) & (sd > 0)
        out[a:a + chunk] = np.where(ok, (x[a:a + chunk] - mean) / np.where(sd > 0, sd, 1), np.nan)
    return out


# ------------------------------------------------------------------------------------------ large-trade threshold
def large_threshold_bins(h_cnt: np.ndarray, complete: np.ndarray) -> np.ndarray:
    """Per UTC day D: bin k such that trades in bins >= k are >= 1 % of the trades of day D-1 (largest such k).
    Day D-1 must be fully complete; otherwise -1 (no threshold -> large features NaN)."""
    n = len(h_cnt)
    days = n // BPD
    k = np.full(days, -1, np.int64)
    for d in range(1, days):
        p = slice((d - 1) * BPD, d * BPD)
        if not complete[p].all():
            continue
        c = h_cnt[p].sum(axis=0).astype(float)
        tot = c.sum()
        if tot <= 0:
            continue
        ge = np.cumsum(c[::-1])[::-1] / tot            # share of trades in bins >= k
        ok = np.nonzero(ge >= 0.01)[0]
        if len(ok):
            k[d] = int(ok.max())
    return k


def large_signed_volume(h_buy, h_sell, kday: np.ndarray, complete: np.ndarray) -> np.ndarray:
    n = len(h_buy)
    out = np.full(n, np.nan)
    net = h_buy.astype(float) - h_sell.astype(float)
    for d, k in enumerate(kday):
        if k < 0:
            continue
        sl = slice(d * BPD, (d + 1) * BPD)
        out[sl] = net[sl, k:].sum(axis=1)
    out[~complete.astype(bool)] = np.nan
    return out


# ------------------------------------------------------------------------------------------ feature table
def build_features(flow: dict, hist: dict, depth: dict | None) -> dict:
    """All bucket-level and 1h-level features; arrays aligned to flow['bucket_open_ms'] (length multiple of 288)."""
    n = len(flow["bucket_open_ms"])
    assert n % BPD == 0, "grid must be whole days"
    comp = flow["complete"].astype(bool)
    nan = lambda a: np.where(comp, a, np.nan)
    delta_q = nan(flow["qv_buy"] - flow["qv_sell"])
    delta_b = nan(flow["qty_buy"] - flow["qty_sell"])
    kday = large_threshold_bins(hist["h_cnt"], comp)
    large = large_signed_volume(hist["h_buy"], hist["h_sell"], kday, comp)
    small = delta_q - large
    cnt = flow["n_buy"] + flow["n_sell"]
    with np.errstate(invalid="ignore", divide="ignore"):
        ret = np.where(cnt > 0, np.log(flow["p_last"] / flow["p_first"]), 0.0)
        mean = flow["s1"] / cnt
        var = flow["s2"] / cnt - mean ** 2
        m3 = flow["s3"] / cnt - 3 * mean * flow["s2"] / cnt + 2 * mean ** 3
        skew = np.where((cnt > 2) & (var > 0), m3 / var ** 1.5, np.nan)
    ret, rv = nan(ret), nan(flow["rv"])
    d6 = delta_q / 1e6
    f = {"delta_q": delta_q, "delta_b": delta_b, "large_q": large, "small_q": small, "ret": ret, "rv": rv,
         "n_buy": nan(flow["n_buy"]), "n_sell": nan(flow["n_sell"]), "skew": nan(skew), "complete": comp.astype(float)}
    f["D1h"] = roll_sum(delta_q)
    f["L1h"] = roll_sum(large)
    f["S1h"] = roll_sum(small)
    f["RV1h"] = roll_sum(rv)
    r1 = np.full(n, np.nan)
    with np.errstate(invalid="ignore", divide="ignore"):
        lr = np.log(flow["p_last"][W1H - 1:] / flow["p_first"][: n - W1H + 1])
    ok = roll_sum(comp.astype(float)) == W1H
    r1[W1H - 1:] = lr
    f["R1h"] = np.where(ok, r1, np.nan)
    num, den = roll_sum(ret * d6), roll_sum(d6 * d6)
    with np.errstate(invalid="ignore", divide="ignore"):
        f["LAM"] = np.where(den > 0, num / den, np.nan)
    di = {}
    if depth:
        for lv in depthmod.LEVELS:
            if f"bid{lv}" in depth:
                imb = depthmod.imbalance(depth[f"bid{lv}"], depth[f"ask{lv}"])
                di[lv] = roll_mean_min(imb, W1H, DEPTH_MIN_BUCKETS)
    for lv in depthmod.LEVELS:
        f[f"DI{lv}"] = di.get(lv, np.full(n, np.nan))
    for name in ("D1h", "L1h", "S1h", "RV1h", "R1h", "LAM", "DI1", "DI2"):
        f["z_" + name] = z_trailing(f[name])
    return f
