"""Layer B — realizable policy simulation (SPEC §16, §20-27, §59-61; D-006, D-008, L8).

Everything is computed in "move %" space relative to the entry price E, signed so that + is
favourable for the side (LONG: price up; SHORT: price down). Levels:

  hard stop      at -sl_pct            (absolute floor; never widened)
  activation     at +act_pct           (arms profit protection; does NOT close the trade)
  trailing stop  peak% - trail_pct     (trail measured in % of E), effective stop =
                 max(-sl_pct, peak% - trail_pct) — so it only ever tightens (test L)
  time exit      close of the last path bar

Order inside one 1m bar is unknown, so (SPEC §25-26, conservative, never favourable):
  * a bar that touches BOTH the hard stop and the activation level before arming
    => AMBIGUOUS_INTRABAR, evaluated as the hard stop (the worse outcome) and flagged;
  * a stop already in force before the bar (previous peak) that the bar's low crosses => exit at
    that stop, or at the bar open if the bar gapped through it;
  * a bar that makes a new peak AND crosses the stop implied by that new peak => exit at the new
    stop (flag same_bar_trail) — the favourable order (low first, high later) is never assumed.
Exit time = end of the exit bar (stops) or T0 + H (time exit). Funding is applied only for events
with T0 < f <= exit time (tests H/I). net = gross - cost_pct - side * funding_long.
"""

from __future__ import annotations

import numpy as np

from .targets import MINUTE, VALID, dense_minutes, entry_status

HARD_STOP, TRAILING_PROFIT, TIME_EXIT_PROFIT, TIME_EXIT_LOSS, TIME_EXIT_FLAT, AMBIGUOUS_INTRABAR, INVALID_DATA = range(7)
REASONS = ("HARD_STOP", "TRAILING_PROFIT", "TIME_EXIT_PROFIT", "TIME_EXIT_LOSS", "TIME_EXIT_FLAT", "AMBIGUOUS_INTRABAR", "INVALID_DATA")
FLAT_PCT = 0.01
CELL_BUDGET = 2_000_000


def _as_col(value, rows: np.ndarray) -> np.ndarray:
    arr = np.asarray(value, dtype=float)
    return (np.full(len(rows), float(arr)) if arr.ndim == 0 else arr[rows])[:, None]


def simulate(m1: dict[str, np.ndarray] | None, anchors: np.ndarray, horizon_min: int, side: str,
             sl_pct, act_pct, trail_pct, cost_pct: float, funding: dict[str, np.ndarray] | None = None,
             conflicts: np.ndarray | None = None, grid: dict[str, np.ndarray] | None = None,
             chunk: int = 4000) -> dict[str, np.ndarray]:
    """sl/act/trail: scalars or per-anchor arrays (dynamic policies). Returns per-anchor outcome."""
    if side not in ("long", "short"):
        raise ValueError("side must be long or short")
    grid = grid if grid is not None else dense_minutes(m1)
    n = int(horizon_min)
    anchors = np.asarray(anchors, np.int64)
    entry_idx, status = entry_status(grid, anchors, n, conflicts)
    count = len(anchors)
    out = {
        "reason": np.full(count, INVALID_DATA, np.int8), "exit_bar": np.full(count, -1, np.int32),
        "exit_time_ms": np.full(count, -1, np.int64), "gross_pct": np.full(count, np.nan),
        "funding_pct": np.full(count, np.nan), "net_pct": np.full(count, np.nan),
        "armed": np.zeros(count, bool), "same_bar_trail": np.zeros(count, bool), "status": status,
    }
    rows = np.nonzero(status == VALID)[0]
    if len(rows) == 0:
        return out
    sign = 1.0 if side == "long" else -1.0
    if funding is not None and len(funding["funding_time"]):
        f_t = funding["funding_time"]
        f_cs = np.r_[0.0, np.cumsum(funding["funding_rate"] * 100.0)]
    else:
        f_t, f_cs = None, None
    offsets = np.arange(n)
    chunk = max(64, min(chunk, CELL_BUDGET // max(n, 1)))
    for begin in range(0, len(rows), chunk):
        sel = rows[begin:begin + chunk]
        k = len(sel)
        win = entry_idx[sel][:, None] + offsets[None, :]
        e = grid["open"][entry_idx[sel]][:, None]
        hi = (grid["high"][win] / e - 1.0) * 100.0
        lo = (grid["low"][win] / e - 1.0) * 100.0
        op = (grid["open"][win] / e - 1.0) * 100.0
        cl = (grid["close"][win[:, -1]] / e[:, 0] - 1.0) * 100.0
        fav, adv, opn, close = (hi, lo, op, cl) if sign > 0 else (-lo, -hi, -op, -cl)
        sl = _as_col(sl_pct, sel); act = _as_col(act_pct, sel); trail = _as_col(trail_pct, sel)
        if np.any(sl <= 0) or np.any(act <= 0) or np.any(trail <= 0):
            raise ValueError("sl, act and trail must be positive")
        floor = -sl

        never = n + 1
        sl_hit = adv <= floor
        s = np.where(sl_hit.any(axis=1), np.argmax(sl_hit, axis=1), never)
        act_hit = fav >= act
        a = np.where(act_hit.any(axis=1), np.argmax(act_hit, axis=1), never)

        col = offsets[None, :]
        a_col = a[:, None]
        armed_cols = col >= a_col
        peak = np.maximum.accumulate(np.where(armed_cols, fav, -np.inf), axis=1)
        peak_prev = np.concatenate([np.full((k, 1), -np.inf), peak[:, :-1]], axis=1)
        stop_incl = np.where(armed_cols, np.maximum(floor, peak - trail), -np.inf)
        stop_prev = np.where(col > a_col, np.maximum(floor, peak_prev - trail), -np.inf)
        trig = armed_cols & (adv <= stop_incl)
        t = np.where(trig.any(axis=1), np.argmax(trig, axis=1), never)

        reason = np.full(k, -1, np.int8)
        exit_bar = np.full(k, n - 1, np.int64)
        move = close.copy()
        same_bar = np.zeros(k, bool)
        ridx = np.arange(k)

        stop_first = (s < a) & (s < never)
        ambiguous = (s == a) & (s < never)
        for mask, why in ((stop_first, HARD_STOP), (ambiguous, AMBIGUOUS_INTRABAR)):
            j = s[mask]
            exit_bar[mask] = j
            move[mask] = np.minimum(opn[ridx[mask], j], floor[mask, 0])
            reason[mask] = why

        armed = (a < s) & (a < never) & (t < never)
        if armed.any():
            j = t[armed]
            rr = ridx[armed]
            prev_level = stop_prev[rr, j]
            through_prev = adv[rr, j] <= prev_level
            level = np.where(through_prev, np.minimum(opn[rr, j], prev_level), stop_incl[rr, j])
            same_bar[armed] = ~through_prev
            exit_bar[armed] = j
            move[armed] = level
            used = np.where(through_prev, prev_level, stop_incl[rr, j])
            reason[armed] = np.where(used > floor[armed, 0] + 1e-12, TRAILING_PROFIT, HARD_STOP)

        timed = reason < 0
        exit_ms = anchors[sel] + (exit_bar + 1) * MINUTE
        if f_t is not None:
            fund = f_cs[np.searchsorted(f_t, exit_ms, side="right")] - f_cs[np.searchsorted(f_t, anchors[sel], side="right")]
        else:
            fund = np.zeros(k)
        net = move - cost_pct - sign * fund
        reason[timed] = np.where(net[timed] > FLAT_PCT, TIME_EXIT_PROFIT, np.where(net[timed] < -FLAT_PCT, TIME_EXIT_LOSS, TIME_EXIT_FLAT))

        out["reason"][sel] = reason
        out["exit_bar"][sel] = exit_bar
        out["exit_time_ms"][sel] = exit_ms
        out["gross_pct"][sel] = move
        out["funding_pct"][sel] = fund
        out["net_pct"][sel] = net
        out["armed"][sel] = (a < s) & (a < never)
        out["same_bar_trail"][sel] = same_bar
    return out


def non_overlapping(anchors: np.ndarray, take: np.ndarray, exit_time_ms: np.ndarray) -> np.ndarray:
    """Indices of executed trades: one open position at a time (SPEC §60)."""
    chosen = []
    free_at = -1
    for i in np.nonzero(take)[0]:
        if anchors[i] >= free_at:
            chosen.append(i)
            free_at = exit_time_ms[i]
    return np.asarray(chosen, np.int64)


def trade_stats(net: np.ndarray, reasons: np.ndarray | None = None) -> dict:
    """§62 metrics for a list of executed trades (percent per trade, compounding ignored)."""
    net = np.asarray(net, float)
    n = len(net)
    if n == 0:
        return {"trades": 0}
    equity = np.cumsum(net)
    drawdown = np.maximum.accumulate(np.r_[0.0, equity])[1:] - equity
    losses = net < 0
    run = best = 0
    for lost in losses:
        run = run + 1 if lost else 0
        best = max(best, run)
    gp, gl = net[net > 0].sum(), -net[net < 0].sum()
    std = net.std(ddof=1) if n > 1 else np.nan
    out = {
        "trades": n, "total_net_pct": round(float(net.sum()), 4), "avg_net_pct": round(float(net.mean()), 5),
        "median_net_pct": round(float(np.median(net)), 5), "win_rate_pct": round(float((net > 0).mean() * 100), 2),
        "profit_factor": round(float(gp / gl), 3) if gl > 0 else None, "max_drawdown_pct": round(float(drawdown.max()), 4),
        "max_single_loss_pct": round(float(net.min()), 4), "max_consecutive_losses": int(best),
        "loss_p95_pct": round(float(np.percentile(net, 5)), 4),
        "t_stat": round(float(net.mean() / (std / np.sqrt(n))), 3) if n > 1 and std > 0 else None,
    }
    if reasons is not None:
        out["exit_reasons_pct"] = {REASONS[r]: round(float((reasons == r).mean() * 100), 2) for r in range(7) if (reasons == r).any()}
    return out
