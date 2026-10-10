"""Rule Baseline v1: frozen rule definitions (docs/ml-engine-v1/RULE_BASELINE_V1.md). Everything here is fixed by the plan; nothing is tuned.

Signal on a CLOSED bar t of the preset chart timeframe; entry = open of the next bar; stop = most recent CONFIRMED fractal swing (k = 3 bars each side,
confirmed at the close of bar j + 3, never earlier) or, in the ablations, 1.5 ATR14; take-profit 2 R; timeout at the preset expiry.
"""

from __future__ import annotations

import numpy as np

from regime_v1 import features as F
from v4 import strategy_v13 as v13

K_FRACTAL = 3
RR = 2.0
FLOOR_PCT = 0.25            # minimum stop distance, % of price (cost-dominance guard: round trip 0.12 % <= 0.48 R)
CAP_PCT = 1.50              # maximum stop distance, % of price (hard cap; wider = no trade)
ATR_N, ATR_K = 14, 1.5      # ablation stop only

# name -> dict(ema = EMA length or None, brk = breakout lookback, sl = "swing" | "atr", role)
VARIANTS: dict[str, dict] = {
    "PRIMARY": {"ema": 200, "brk": 20, "sl": "swing", "role": "primary"},
    "AB_NOEMA": {"ema": None, "brk": 20, "sl": "swing", "role": "ablation"},
    "AB_NOSWING": {"ema": 200, "brk": 20, "sl": "atr", "role": "ablation"},
    "AB_PLAIN": {"ema": None, "brk": 20, "sl": "atr", "role": "ablation"},
    "SENS_EMA100": {"ema": 100, "brk": 20, "sl": "swing", "role": "sensitivity"},
    "SENS_BRK50": {"ema": 200, "brk": 50, "sl": "swing", "role": "sensitivity"},
}
PRESETS = {"P1": ("5m", 12, 12), "P2": ("5m", 6, 6), "P3": ("15m", 4, 12)}      # name -> (chart tf, horizon bars on tf, 5m bars of the horizon)
PRESET_SECONDS = {"P1": 3600, "P2": 1800, "P3": 3600}
COSTS = {"meas": 0.12, "code": 0.14, "maker_code": 0.11, "maker_meas": 0.08}
PRIMARY_COINS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT")
ROBUST_COINS = ("XRPUSDT", "SUIUSDT")
S_SEEDS = 100


def prior_extreme(x, n: int, kind: str) -> np.ndarray:
    """Highest high / lowest low of the PREVIOUS n closed bars (bars t-n .. t-1); NaN if any of them is missing."""
    s = v13.shift(x, 1)
    return v13.highest(s, n) if kind == "high" else v13.lowest(s, n)


def swing_levels(h, l, t_idx=None, k: int = K_FRACTAL):
    """(last confirmed swing-low price, last confirmed swing-high price) as known at the close of every bar t (NaN if none yet).
    A fractal at bar j is confirmed at bar j + k (regime_v1.features.pivots)."""
    n = len(h)
    th, vh, tl, vl = F.pivots(h, l, k)
    t = np.arange(n)
    pl = np.searchsorted(tl, t, side="right") - 1
    ph = np.searchsorted(th, t, side="right") - 1
    low = np.where(pl >= 0, vl[np.maximum(pl, 0)] if len(vl) else np.nan, np.nan)
    high = np.where(ph >= 0, vh[np.maximum(ph, 0)] if len(vh) else np.nan, np.nan)
    return low, high


def signals(b, var: dict) -> dict:
    """Per bar of the chart timeframe: side (+1 / -1 / 0), stop level (swing price, NaN for ATR stops), atr distance fraction, distance at the signal close
    (fraction of close, NaN if no valid stop). Uses bars <= t only (prefix invariance is unit-tested)."""
    c, h, l = b.c, b.h, b.l
    with np.errstate(invalid="ignore"):
        hh = prior_extreme(h, var["brk"], "high")
        ll = prior_extreme(l, var["brk"], "low")
        up, dn = c > hh, c < ll
        if var["ema"]:
            e = v13.ema(c, var["ema"])
            up, dn = up & (c > e), dn & (c < e)
        side = np.where(up, 1, np.where(dn, -1, 0)).astype(np.int8)
        if var["sl"] == "swing":
            lo, hi = swing_levels(h, l)
            stop_px = np.where(side > 0, lo, np.where(side < 0, hi, np.nan))
            dist = np.where(side > 0, (c - stop_px) / c, np.where(side < 0, (stop_px - c) / c, np.nan))
        else:
            stop_px = np.full(len(c), np.nan)
            dist = ATR_K * b.atr / c
    return {"side": side, "stop_px": stop_px, "dist": dist}


def stop_fraction(var: dict, side, stop_px, dist_sig, entry_px):
    """Stop distance as a fraction of the ENTRY price (the open of the next bar). Swing: |entry - swing| / entry; ATR: the signal-bar fraction."""
    if var["sl"] == "swing":
        return np.where(side > 0, (entry_px - stop_px) / entry_px, (stop_px - entry_px) / entry_px)
    return np.asarray(dist_sig, float)


def distance_ok(frac):
    """Floor / cap (inclusive) on a stop distance given as a fraction of price."""
    with np.errstate(invalid="ignore"):
        return np.isfinite(frac) & (frac * 100.0 >= FLOOR_PCT - 1e-12) & (frac * 100.0 <= CAP_PCT + 1e-12)
