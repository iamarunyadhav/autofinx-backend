"""Edge Search v2: the 10 frozen events (plan section 3). Fixed thresholds, no grids. +1 long, -1 short, 0 no signal.

Features at index t come from closed buckets <= t (features.py); a signal at t is filled at the open of bar t+1.
"""

from __future__ import annotations

import numpy as np

EVENTS = ("FLOW_FOLLOW", "FLOW_FADE", "LARGE_FOLLOW", "LARGE_FADE", "ABSORPTION", "VOL_EXPANSION_BREAKOUT", "THIN_BOOK_FLOW",
          "LARGE_VS_SMALL", "DEPTH_IMB_FOLLOW", "DEPTH_FLOW_CONFLUENCE")


def _g(x, thr):      # NaN-safe comparisons: NaN never satisfies a condition
    with np.errstate(invalid="ignore"):
        return x >= thr


def _l(x, thr):
    with np.errstate(invalid="ignore"):
        return x <= thr


def signals(f: dict) -> dict[str, np.ndarray]:
    zD, zL, zS = f["z_D1h"], f["z_L1h"], f["z_S1h"]
    out: dict[str, np.ndarray] = {}
    s = lambda up, dn: np.where(up, 1, np.where(dn, -1, 0)).astype(np.int8)
    out["FLOW_FOLLOW"] = s(_g(zD, 2), _l(zD, -2))
    out["FLOW_FADE"] = -out["FLOW_FOLLOW"]
    out["LARGE_FOLLOW"] = s(_g(zL, 2), _l(zL, -2))
    out["LARGE_FADE"] = -out["LARGE_FOLLOW"]
    R = f["R1h"]
    with np.errstate(invalid="ignore"):
        out["ABSORPTION"] = s(_l(zD, -2) & (R >= 0), _g(zD, 2) & (R <= 0))
        zR = f["z_R1h"]
        vol = _g(f["z_RV1h"], 2) & (np.abs(zR) >= 1)
        out["VOL_EXPANSION_BREAKOUT"] = np.where(vol, np.sign(R), 0).astype(np.int8)
        thin = _g(f["z_LAM"], 2) & (np.abs(zD) >= 1.5)
        out["THIN_BOOK_FLOW"] = np.where(thin, np.sign(f["D1h"]), 0).astype(np.int8)
    out["LARGE_VS_SMALL"] = s(_g(zL, 1.5) & _l(zS, -1.5), _l(zL, -1.5) & _g(zS, 1.5))
    z2 = f["z_DI2"]
    out["DEPTH_IMB_FOLLOW"] = s(_g(z2, 2), _l(z2, -2))
    z1 = f["z_DI1"]
    out["DEPTH_FLOW_CONFLUENCE"] = s(_g(z1, 1.5) & _g(zD, 1.5), _l(z1, -1.5) & _l(zD, -1.5))
    for k in out:
        out[k] = np.nan_to_num(out[k]).astype(np.int8)
    return out
