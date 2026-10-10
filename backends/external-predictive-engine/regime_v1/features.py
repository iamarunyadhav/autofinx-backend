"""Causal regime classification + noise / chop / tradeability score (plan sections 3.1-3.2).

Every function takes the arrays of ONE timeframe (open, high, low, close of closed bars; NaN for an incomplete bar) and returns
arrays of the same length; the value at index t uses bars <= t only. Pivot (swing) points are only used from the bar that
confirms them. tests/test_regime_v1.py proves prefix invariance (computing on data[:m] gives the same values as on all data).
"""

from __future__ import annotations

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw

from edge_search.rules import pct_rank, roll_std
from v4 import strategy_v13 as v13

UNCERTAIN, STRONG_BULL, WEAK_BULL, STRONG_BEAR, WEAK_BEAR, SIDEWAYS, HIGHVOL_RANGE, LOWVOL_COMPRESSION, BREAKOUT = range(9)
NAMES = ["UNCERTAIN", "STRONG_BULL", "WEAK_BULL", "STRONG_BEAR", "WEAK_BEAR", "SIDEWAYS_RANGE", "HIGHVOL_RANGE",
         "LOWVOL_COMPRESSION", "BREAKOUT"]
TREND = (STRONG_BULL, WEAK_BULL, STRONG_BEAR, WEAK_BEAR)
RANGE = (SIDEWAYS, HIGHVOL_RANGE, LOWVOL_COMPRESSION)
K_SWING = 3
RAMPS = {"ER": (0.20, 0.40), "CHOP": (61.8, 38.2), "PERSIST": (0.50, 0.70), "REVERS": (0.60, 0.40), "NETATR": (1.5, 4.0),
         "OVERLAP": (0.65, 0.40), "WICK": (0.70, 0.45)}
H_STRAT = 4
C0 = 0.12


def shift(x, k=1):
    return v13.shift(x, k)


def roll_sum(x, n):
    """Trailing sum over n bars, NaN if any value in the window is NaN."""
    return v13.sma(x, n) * n


def roll_any(b, n):
    """True at t if any of b[t-n+1..t] (as far as available) is True."""
    cs = np.r_[0.0, np.cumsum(np.asarray(b, bool).astype(float))]
    lo = np.maximum(np.arange(len(b)) + 1 - n, 0)
    return (cs[1:] - cs[lo]) > 0


def ramp(x, bad, good):
    x = np.asarray(x, float)
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.clip((x - bad) / (good - bad), 0.0, 1.0)


# ------------------------------------------------------------------------------------------ swing structure
def pivots(h, l, k=K_SWING):
    """(pivot_high_confirm_index, pivot_high_value, pivot_low_confirm_index, pivot_low_value). A pivot at bar j is confirmed at
    bar j+k (the close of j+k is the first moment it is known)."""
    h, l = np.asarray(h, float), np.asarray(l, float)
    n = len(h)
    if n < 2 * k + 1:
        z = np.zeros(0, np.int64)
        return z, np.zeros(0), z, np.zeros(0)
    Wh, Wl = sw(h, k), sw(l, k)                     # window i covers i .. i+k-1
    j = np.arange(k, n - k)
    with np.errstate(invalid="ignore"):
        ph = (h[j] > Wh[j - k].max(axis=1)) & (h[j] > Wh[j + 1].max(axis=1))
        pl = (l[j] < Wl[j - k].min(axis=1)) & (l[j] < Wl[j + 1].min(axis=1))
    jh, jl = j[ph], j[pl]
    return jh + k, h[jh], jl + k, l[jl]


def structure(h, l, k=K_SWING):
    """+1 bull (last high > previous high and last low > previous low, both confirmed), -1 bear, 0 neutral."""
    n = len(h)
    th, vh, tl, vl = pivots(h, l, k)
    t = np.arange(n)
    ch, cl = np.searchsorted(th, t, side="right"), np.searchsorted(tl, t, side="right")
    ok = (ch >= 2) & (cl >= 2)
    out = np.zeros(n, np.int8)
    if len(vh) >= 2 and len(vl) >= 2:
        lh, ph_ = vh[np.maximum(ch - 1, 0)], vh[np.maximum(ch - 2, 0)]
        ll, pl_ = vl[np.maximum(cl - 1, 0)], vl[np.maximum(cl - 2, 0)]
        out[ok & (lh > ph_) & (ll > pl_)] = 1
        out[ok & (lh < ph_) & (ll < pl_)] = -1
    return out


# ------------------------------------------------------------------------------------------ tradeability components
def components(o, h, l, c):
    pc = shift(c, 1)
    with np.errstate(invalid="ignore", divide="ignore"):
        absd = np.abs(c - pc)
        er = np.abs(c - shift(c, 10)) / roll_sum(absd, 10)
        tr = v13.true_range(h, l, c)
        rng14 = v13.highest(h, 14) - v13.lowest(l, 14)
        chop = np.where(rng14 > 0, 100.0 * np.log10(roll_sum(tr, 14) / np.where(rng14 > 0, rng14, 1.0)) / np.log10(14), np.nan)
        ret = np.sign(c - pc)                                          # NaN where a bar is missing
        pos = roll_sum((ret > 0).astype(float) + 0 * ret, 14)
        neg = roll_sum((ret < 0).astype(float) + 0 * ret, 14)
        net_sign = np.sign(c - shift(c, 14))
        persist = np.where(net_sign > 0, pos / 14, np.where(net_sign < 0, neg / 14, np.where(np.isfinite(net_sign), 0.0, np.nan)))
        flip = ret * shift(ret, 1)
        flip = np.where(np.isfinite(flip), (flip < 0).astype(float), np.nan)
        revers = v13.sma(flip, 13)
        netatr = np.abs(c - shift(c, 14)) / v13.rma(tr, 14)
        hh, ll_ = np.maximum(h, shift(h, 1)), np.minimum(l, shift(l, 1))
        inter = np.minimum(h, shift(h, 1)) - np.maximum(l, shift(l, 1))
        ov = np.where(hh - ll_ > 0, np.maximum(inter, 0.0) / np.where(hh - ll_ > 0, hh - ll_, 1.0), np.nan)
        overlap = v13.sma(ov, 14)
        rng, body = h - l, np.abs(c - o)
        w = np.where(np.isfinite(rng), np.where(rng > 0, 1.0 - body / np.where(rng > 0, rng, 1.0), 1.0), np.nan)
        wick = v13.sma(w, 14)
    return {"ER": er, "CHOP": chop, "PERSIST": persist, "REVERS": revers, "NETATR": netatr, "OVERLAP": overlap, "WICK": wick}


def tradeability(comp):
    sc = np.vstack([ramp(comp[k], *RAMPS[k]) for k in RAMPS])
    return sc.mean(axis=0)            # NaN if any component is NaN


# ------------------------------------------------------------------------------------------ regime
def compute(o, h, l, c):
    o, h, l, c = (np.asarray(x, float) for x in (o, h, l, c))
    n = len(c)
    tr = v13.true_range(h, l, c)
    atr = v13.rma(tr, 14)
    with np.errstate(invalid="ignore", divide="ignore"):
        atrp = atr / c * 100.0
        adx = v13.dmi(h, l, c, 14, 14)[2]
        e20, e50 = v13.ema(c, 20), v13.ema(c, 50)
        bbw = 4.0 * roll_std(c, 20) / v13.sma(c, 20)
        vol_pct, bbw_pct = pct_rank(atrp, 100) / 100.0, pct_rank(bbw, 100) / 100.0
        hi20, lo20 = shift(v13.highest(h, 20), 1), shift(v13.lowest(l, 20), 1)
        up, dn = c > hi20, c < lo20
    idx = np.arange(n)
    up_i = np.maximum.accumulate(np.where(up, idx, -1)) if n else idx
    dn_i = np.maximum.accumulate(np.where(dn, idx, -1)) if n else idx
    last_b = np.maximum(up_i, dn_i)
    recent = (last_b >= 0) & (last_b >= idx - 2)
    brk_dir = np.where(recent, np.where(up_i > dn_i, 1, -1), 0).astype(np.int8)
    squeeze_prior = roll_any(np.nan_to_num(bbw_pct, nan=1.0) <= 0.20, 11)
    st = structure(h, l)
    with np.errstate(invalid="ignore"):
        d_ema, d_px = np.sign(e20 - e50), np.sign(c - e50)
        bull = (d_ema > 0) & (d_px > 0) & (st >= 0)
        bear = (d_ema < 0) & (d_px < 0) & (st <= 0)
        fin = np.isfinite(c) & np.isfinite(h) & np.isfinite(l) & np.isfinite(o)
        for a in (atr, adx, e20, e50, vol_pct, bbw_pct):
            fin &= np.isfinite(a)
        strong, weak, rng = adx >= 25, (adx >= 20) & (adx < 25), adx < 20
        highvol, compress = vol_pct >= 0.80, bbw_pct <= 0.20
    reg = np.zeros(n, np.int8)
    brk = fin & (brk_dir != 0) & squeeze_prior
    reg[brk] = BREAKOUT
    rest = fin & ~brk
    reg[rest & strong & bull] = STRONG_BULL
    reg[rest & strong & bear] = STRONG_BEAR
    reg[rest & weak & bull] = WEAK_BULL
    reg[rest & weak & bear] = WEAK_BEAR
    reg[rest & rng & highvol] = HIGHVOL_RANGE
    reg[rest & rng & ~highvol & compress] = LOWVOL_COMPRESSION
    reg[rest & rng & ~highvol & ~compress] = SIDEWAYS
    comp = components(o, h, l, c)
    return {"regime": reg, "brk_dir": np.where(brk, brk_dir, 0).astype(np.int8), "T": tradeability(comp), "atrp": atrp, "adx": adx,
            "vol_pct": vol_pct, "bbw_pct": bbw_pct, "structure": st, "comp": comp}


def move_gate(atrp, H=H_STRAT):
    with np.errstate(invalid="ignore"):
        return np.isfinite(atrp) & (atrp * np.sqrt(H) >= 2.0 * C0)


def t_gate(T, thr):
    with np.errstate(invalid="ignore"):
        return np.isfinite(T) & (T >= thr)


# ------------------------------------------------------------------------------------------ higher-timeframe alignment
def htf_align(close_time_lo, close_time_hi, series_hi, fill=0):
    """Value of the last COMPLETED higher bar at each lower bar's close: higher bar j is usable iff close_time_hi[j] <= close_time_lo[i]."""
    j = np.searchsorted(close_time_hi, close_time_lo, side="right") - 1
    out = np.asarray(series_hi)[np.maximum(j, 0)].copy()
    out[j < 0] = fill
    return out
