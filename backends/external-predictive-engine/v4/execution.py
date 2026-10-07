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
COST_C0, COST_C1 = 0.12, 0.08


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
    """C1 outcome per anchor (net NaN where not filled). Worst-case same-bar handling as C0."""
    anchors = np.asarray(anchors, np.int64)
    filled, fill_ms, limit = maker_fills(grid, anchors, side)
    out = {"filled": filled, "net_pct": np.full(len(anchors), np.nan), "exit_time_ms": np.full(len(anchors), -1, np.int64),
           "gross_pct": np.full(len(anchors), np.nan)}
    rows = np.nonzero(filled)[0]
    if len(rows) == 0:
        return out

    def pick(v):
        return np.asarray(v, float)[rows] if np.ndim(v) else v
    sim = pol.simulate(None, fill_ms[rows], horizon, side, pick(sl), pick(act), pick(trail), COST_C1, funding, conflicts,
                       grid=grid, entry_price=limit[rows])
    sim = pol.apply_worst_case(sim, grid, fill_ms[rows], horizon, side, pick(sl), pick(act), pick(trail), COST_C1, funding,
                               entry_price=limit[rows])
    out["net_pct"][rows] = sim["net_pct"]
    out["gross_pct"][rows] = sim["gross_pct"]
    out["exit_time_ms"][rows] = sim["exit_time_ms"]
    return out
