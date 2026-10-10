"""Optional soft confirmations (plan section 3.6). Separately removable: nothing in the structure engine imports this module; only arms C / D and the
descriptive analysis use it. Every series is causal (index t uses bars <= t). They are NOT probabilities and carry no weights."""

from __future__ import annotations

import numpy as np

from edge_search.rules import pct_rank
from v4 import strategy_v13 as v13

from . import params as P


def volume_ratio(v) -> np.ndarray:
    """v[t] / mean(v[t-20 .. t-1]) (previous 20 closed bars, current excluded)."""
    v = np.asarray(v, float)
    base = v13.shift(v13.sma(v, P.VOL_WIN), 1)
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.where(base > 0, v / base, np.nan)


def volume_expansion(v) -> np.ndarray:
    with np.errstate(invalid="ignore"):
        return np.isfinite(volume_ratio(v)) & (volume_ratio(v) >= P.VOL_MULT)


def atr_pct_rank(atr, c) -> np.ndarray:
    """Percentile (0-100) of ATR% within the last 100 bars including t."""
    with np.errstate(invalid="ignore", divide="ignore"):
        return pct_rank(np.asarray(atr, float) / np.asarray(c, float) * 100.0, P.VOLA_RANK_N)


def volatility_ok(atr, c) -> np.ndarray:
    r = atr_pct_rank(atr, c)
    with np.errstate(invalid="ignore"):
        return np.isfinite(r) & (r >= P.VOLA_MIN_PCT)


def ema_context(c, n: int = 50) -> np.ndarray:
    """+1 close above EMA50, -1 below, 0 equal / NaN."""
    e = v13.ema(np.asarray(c, float), n)
    with np.errstate(invalid="ignore"):
        return np.nan_to_num(np.sign(np.asarray(c, float) - e)).astype(np.int8)


def rsi14(c) -> np.ndarray:
    return v13.rsi(np.asarray(c, float), 14)


def breakout_strength(c, level, atr) -> np.ndarray:
    """|close - broken level| / ATR (only meaningful on a BOS bar)."""
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.where(np.asarray(atr) > 0, np.abs(np.asarray(c, float) - np.asarray(level, float)) / np.asarray(atr, float), np.nan)


def retest_flag(bos, level, h, l, c, atr) -> np.ndarray:
    """+1 / -1 at bar t if, within the last RETEST_BARS bars, a BOS (up / down) happened at bar b < t and bar t pulled back to within RETEST_ATR x ATR of the broken
    level but closed on the breakout side. Uses only bars <= t."""
    n = len(c)
    out = np.zeros(n, np.int8)
    for b in np.nonzero(bos != 0)[0]:
        d = int(bos[b])
        lvl = level[b]
        for t in range(b + 1, min(b + P.RETEST_BARS, n - 1) + 1):
            a = atr[t]
            if not np.isfinite(a) or not np.isfinite(c[t]):
                continue
            if d > 0 and l[t] <= lvl + P.RETEST_ATR * a and c[t] > lvl:
                out[t] = 1
                break
            if d < 0 and h[t] >= lvl - P.RETEST_ATR * a and c[t] < lvl:
                out[t] = -1
                break
    return out
