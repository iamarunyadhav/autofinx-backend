"""Quick Trade replay v1: exit-path simulator on the 5m bars (plan sections 2 and 3).

All trades of one chain are simulated at once (vectorised over trades, loop over the bars of the horizon). The position PnL space is the price move in the
trade direction relative to the entry open (fraction, not %). Order inside a bar: (1) gap at the open through the stop (fill at the open = worse),
(2) stop / take-profit against the bar extremes with the stop level fixed at the previous bar close; both in one bar = AMBIGUOUS_INTRABAR counted as the stop,
(3) the stop is only allowed to tighten; a tightening whose new level is already reached by the same bar's adverse extreme is AMBIGUOUS_INTRABAR and stops
the trade at the new level. No oracle: every distance is an input known at the signal bar.
"""

from __future__ import annotations

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw

M5 = 300_000
DAY = 86_400_000
TIME, SL, TP, GAP, STOP_TB, AMBIG = range(6)
REASONS = ("TIME", "SL", "TP", "GAP", "STOP_TRAIL_BE", "AMBIGUOUS_INTRABAR")


def windows(b5, e0, H5: int):
    """Open / high / low / close windows (n, H5) of the 5m bars e0 .. e0+H5-1 for each entry bar index e0."""
    e0 = np.asarray(e0, np.int64)
    return tuple(sw(x, H5)[e0] for x in (b5.o, b5.h, b5.l, b5.c))


def entry_index(b5, entry_t) -> np.ndarray:
    """5m bar index whose OPEN is the entry time (the signal bar close)."""
    return ((np.asarray(entry_t, np.int64) - b5.t0) // M5).astype(np.int64)


def simulate(W, side, stop=None, tp=None, arm=None, trail=None, be=False, trace=False) -> dict:
    """W = (Wo, Wh, Wl, Wc) windows. side +1 / -1 (scalar or (n,)). stop / tp / arm / trail are fractions of the entry price ((n,) or scalar);
    stop None or inf = no stop. be=True: once the favourable excursion >= arm the stop moves to break-even (0). trail: once armed the stop follows the
    running peak minus `trail`. Returns gross (in %), exit_k (bar index 0..H5-1), reason codes, ambiguous flag."""
    Wo, Wh, Wl, Wc = W
    n, H5 = Wo.shape
    E = Wo[:, 0]
    sg = np.broadcast_to(np.asarray(side, np.int8), (n,)).astype(np.int8)
    up = (sg > 0)[:, None]
    r_o = np.where(up, Wo / E[:, None] - 1.0, -(Wo / E[:, None] - 1.0))
    r_c = np.where(up, Wc / E[:, None] - 1.0, -(Wc / E[:, None] - 1.0))
    fav = np.where(up, Wh / E[:, None] - 1.0, -(Wl / E[:, None] - 1.0))
    adv = np.where(up, Wl / E[:, None] - 1.0, -(Wh / E[:, None] - 1.0))
    full = lambda v, d: np.full(n, d, float) if v is None else np.broadcast_to(np.asarray(v, float), (n,)).copy()
    stop_lvl = -full(stop, np.inf)
    tp_lvl = full(tp, np.inf)
    arm_lvl = full(arm, np.inf)
    trail_d = full(trail, np.nan)
    alive = np.ones(n, bool)
    pnl = r_c[:, -1].copy()
    exit_k = np.full(n, H5 - 1, np.int64)
    reason = np.zeros(n, np.int8)
    tight = np.zeros(n, bool)                    # True once the stop has been moved from its initial level
    peak = np.zeros(n)
    path = np.full((n, H5), np.nan) if trace else None
    for k in range(H5):
        if trace:
            path[:, k] = np.where(alive, stop_lvl, np.nan)
        op, fv, ad = r_o[:, k], fav[:, k], adv[:, k]
        gap = alive & (op <= stop_lvl)
        sh = alive & ~gap & (ad <= stop_lvl)
        th = alive & ~gap & (fv >= tp_lvl)
        both = sh & th
        stp = sh                                  # a same-bar stop + TP is the stop (worse)
        tpx = th & ~sh
        pnl[gap] = op[gap]
        pnl[stp] = stop_lvl[stp]
        pnl[tpx] = tp_lvl[tpx]
        reason[gap] = GAP
        reason[stp & ~both] = np.where(tight[stp & ~both], STOP_TB, SL)
        reason[both] = AMBIG
        reason[tpx] = TP
        done = gap | stp | tpx
        exit_k[done] = k
        alive &= ~done
        if (be or trail is not None) and alive.any():
            peak = np.where(alive, np.maximum(peak, fv), peak)
            armed = alive & (peak >= arm_lvl)
            new = stop_lvl.copy()
            if be:
                new = np.where(armed, np.maximum(new, 0.0), new)
            if trail is not None:
                new = np.where(armed, np.maximum(new, peak - trail_d), new)
            moved = alive & (new > stop_lvl)
            amb = moved & (ad <= new)             # the same bar already reaches the new level (adverse ordering assumed)
            pnl[amb] = new[amb]
            reason[amb] = AMBIG
            exit_k[amb] = k
            alive &= ~amb
            tight |= moved
            stop_lvl = np.where(alive, new, stop_lvl)
    res = {"gross": pnl * 100.0, "exit_k": exit_k, "reason": reason}
    if trace:
        res["stop_path"] = path
    return res


def exit_times(entry_t, exit_k):
    return np.asarray(entry_t, np.int64) + (np.asarray(exit_k, np.int64) + 1) * M5


# ------------------------------------------------------------------------------------------ causal MAE / MFE quantile table (X4)
def excursions(b5, H5: int):
    """Per 5m entry bar j (entry at open j, window j..j+H5-1): long MAE, long MFE (fractions, >= 0), valid flag. Short MAE = long MFE and vice versa."""
    n = b5.n
    ok = np.zeros(n, bool)
    mae = np.full(n, np.nan)
    mfe = np.full(n, np.nan)
    m = n - H5 + 1
    if m <= 0:
        return mae, mfe, ok
    lo = sw(b5.l, H5).min(axis=1)[:m]
    hi = sw(b5.h, H5).max(axis=1)[:m]
    o = b5.o[:m]
    v = np.isfinite(lo) & np.isfinite(hi) & np.isfinite(o) & (o > 0)
    ok[:m] = v
    mae[:m] = np.where(v, 1.0 - lo / np.where(o > 0, o, 1.0), np.nan)
    mfe[:m] = np.where(v, hi / np.where(o > 0, o, 1.0) - 1.0, np.nan)
    return mae, mfe, ok


QS_SL = (0.75, 0.90)
QS_TP = (0.50, 0.75)


def mae_mfe_table(b5, H5: int, lookback_days: int = 30, min_n: int = 1000):
    """tab[day, side(0 long / 1 short), kind(0 SL, 1 TP), q-index] in fractions. Row `day` (UTC day since b5.t0) only uses entries whose whole horizon window had
    COMPLETED at or before the start of that day and started within the previous `lookback_days` days. NaN if fewer than min_n entries."""
    mae, mfe, ok = excursions(b5, H5)
    comp = b5.open_time + H5 * M5                      # time the window of entry j is complete
    n_days = int((b5.n * M5) // DAY) + 1
    tab = np.full((n_days, 2, 2, 2), np.nan)
    idx = np.nonzero(ok)[0]
    ct = comp[idx]
    for d in range(n_days):
        start = b5.t0 + d * DAY
        a = np.searchsorted(ct, start - lookback_days * DAY, side="left")
        z = np.searchsorted(ct, start, side="right")
        if z - a < min_n:
            continue
        sel = idx[a:z]
        mae_s, mfe_s = mae[sel], mfe[sel]
        tab[d, 0, 0] = np.quantile(mae_s, QS_SL)       # long: SL from adverse, TP from favourable
        tab[d, 0, 1] = np.quantile(mfe_s, QS_TP)
        tab[d, 1, 0] = np.quantile(mfe_s, QS_SL)       # short: adverse = long's favourable
        tab[d, 1, 1] = np.quantile(mae_s, QS_TP)
    return tab
