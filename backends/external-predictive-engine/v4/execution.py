"""Execution-cost scenarios (D-035). Same predictions and exits; only the entry execution differs.

C0  taker entry + taker exit: entry at the open of the T0 minute, cost 0.12 % round trip (current).
C1  post-only maker entry + taker exit: a limit at the T0 reference price (open of the T0 minute) rests for
    WAIT_MINUTES; it fills only when a 1m bar trades THROUGH it (long: low < limit, short: high > limit),
    at the limit, in that minute. No fill = MISSED (no trade, reported separately, never a free WAIT).
    Cost = maker 0.02 + taker 0.05 + exit slippage 0.01 = 0.08 % round trip. Exits stay taker.
Fees are assumed Binance USD-M VIP0 public rates; the owner confirms the real tier.
"""

from __future__ import annotations

import numpy as np

from . import policy as pol
from .targets import MINUTE

WAIT_MINUTES = 2
from .costs import round_trip_pct

COST_C0, COST_C1 = round_trip_pct("taker"), round_trip_pct("maker_entry")   # 0.12 / 0.08 by default (v4/costs.py)


def maker_fills(grid: dict, anchors: np.ndarray, side: str, wait_minutes: int = WAIT_MINUTES):
    """(filled, fill_minute_ms, limit_price) per anchor. Trade-through rule, causal (only minutes >= T0)."""
    anchors = np.asarray(anchors, np.int64)
    start, size = int(grid["start"]), len(grid["open"])
    idx = ((anchors - start) // MINUTE).astype(np.int64)
    ok = (idx >= 0) & (idx + wait_minutes <= size)
    safe = np.where(ok, idx, 0)
    limit = grid["open"][safe]
    filled = np.zeros(len(anchors), bool)
    fill_idx = np.full(len(anchors), -1, np.int64)
    for k in range(wait_minutes):
        j = np.minimum(safe + k, size - 1)
        through = grid["low"][j] < limit if side == "long" else grid["high"][j] > limit
        new = ok & ~filled & np.isfinite(limit) & through
        fill_idx[new] = j[new]
        filled |= new
    fill_ms = np.where(filled, start + fill_idx * MINUTE, -1)
    return filled, fill_ms, limit


def simulate_c1(grid, anchors, horizon, side, sl, act, trail, funding, conflicts=None):
    """C1 outcome per anchor (net NaN where not filled). Worst-case same-bar handling as C0.

    Fill-minute ordering (D-036 P0 fix A, Codex review): inside the fill minute the order of the fill and the
    bar's extremes is unknown, so the fill minute is treated asymmetrically and conservatively:
      * its ADVERSE extreme counts as after the fill: if it reaches the stop, the trade stops at -sl there;
      * its FAVOURABLE extreme is ignored (it may have happened before the fill; it can never arm trailing).
    The position is then simulated from the NEXT minute with entry price = limit. Expiry stays at
    signal + H (same opportunity window as C0), so the post-fill path is shorter by the fill delay.
    """
    anchors = np.asarray(anchors, np.int64)
    filled, fill_ms, limit = maker_fills(grid, anchors, side)
    n = len(anchors)
    out = {"filled": filled, "net_pct": np.full(n, np.nan), "exit_time_ms": np.full(n, -1, np.int64),
           "gross_pct": np.full(n, np.nan), "fill_minute_stop": np.zeros(n, bool)}
    rows = np.nonzero(filled)[0]
    if len(rows) == 0:
        return out
    start = int(grid["start"])
    sl_all = np.broadcast_to(np.asarray(sl, float), (n,))
    fidx = (fill_ms[rows] - start) // MINUTE
    worst = grid["low"][fidx] if side == "long" else grid["high"][fidx]
    adv = (worst / limit[rows] - 1.0) * 100.0 * (1.0 if side == "long" else -1.0)
    stopped = adv <= -sl_all[rows]
    sr = rows[stopped]
    out["gross_pct"][sr] = -sl_all[sr]
    out["net_pct"][sr] = -sl_all[sr] - COST_C1                      # funding inside one minute: none
    out["exit_time_ms"][sr] = fill_ms[sr] + MINUTE
    out["fill_minute_stop"][sr] = True

    rest = rows[~stopped]
    entry_ms = fill_ms[rest] + MINUTE
    used = (entry_ms - anchors[rest]) // MINUTE                       # minutes of the window already spent
    for u in np.unique(used):
        grp = rest[used == u]
        h_left = int(horizon - u)
        if h_left < 1:
            continue

        def pick(v):
            return np.asarray(v, float)[grp] if np.ndim(v) else v
        a = fill_ms[grp] + MINUTE
        sim = pol.simulate(None, a, h_left, side, pick(sl), pick(act), pick(trail), COST_C1, funding, conflicts,
                           grid=grid, entry_price=limit[grp])
        sim = pol.apply_worst_case(sim, grid, a, h_left, side, pick(sl), pick(act), pick(trail), COST_C1, funding,
                                   entry_price=limit[grp])
        out["net_pct"][grp] = sim["net_pct"]
        out["gross_pct"][grp] = sim["gross_pct"]
        out["exit_time_ms"][grp] = sim["exit_time_ms"]
    return out
