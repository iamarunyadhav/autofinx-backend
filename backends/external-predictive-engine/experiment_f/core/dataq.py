"""Bar quality checks (missing candles, duplicates, OHLC validity) used by the tests and the POC pre-flight."""

from __future__ import annotations

import numpy as np


def ohlc_problems(o, h, l, c, v=None) -> dict:
    """Counts of invalid finite bars: high < max(open, close, low), low > min(open, close, high), non-positive prices, negative volume."""
    o, h, l, c = (np.asarray(x, float) for x in (o, h, l, c))
    fin = np.isfinite(o) & np.isfinite(h) & np.isfinite(l) & np.isfinite(c)
    with np.errstate(invalid="ignore"):
        bad_h = fin & (h < np.maximum.reduce([o, c, l]))
        bad_l = fin & (l > np.minimum.reduce([o, c, h]))
        bad_p = fin & ((o <= 0) | (h <= 0) | (l <= 0) | (c <= 0))
        bad_v = np.zeros(len(o), bool) if v is None else (np.isfinite(np.asarray(v, float)) & (np.asarray(v, float) < 0))
    return {"bars": int(len(o)), "missing": int((~fin).sum()), "high_invalid": int(bad_h.sum()), "low_invalid": int(bad_l.sum()),
            "non_positive": int(bad_p.sum()), "negative_volume": int(bad_v.sum())}


def duplicate_times(open_time) -> int:
    t = np.asarray(open_time, np.int64)
    return int(len(t) - len(np.unique(t)))
