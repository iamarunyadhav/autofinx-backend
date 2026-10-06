"""Layer A — policy-independent raw path truth (SPEC §2, §8-11, §46, §71, §76-77; D-006, L8).

Definitions (every percentage is relative to the entry price E; time is the END of the 1m bar in
which something first happens, because the order inside one 1m bar is unknown):

  T0            anchor = close time of a native 5m candle (features are known only at T0, §8).
  entry         the 1m bar whose open_time == T0; E = its OPEN (first price after the anchor closes).
                Entry slippage/fees belong to the cost model, not to E.
  path          the n = H/1m bars with open_time in [T0, T0 + H); exit time T0 + H.
  valid         all n path bars exist. A missing bar => invalid (reason GAP), never filled (§46, test G).
  cost_pct      round-trip all-in trading cost used only for the "first positive net" threshold and
                for the time-exit net (fees + slippage; default 0.12 = server settings, RESULTS R-002).
  funding_long  sum of funding rates (in %) with T0 < funding_time <= T0 + H (paid by a long, §48).

  LONG
    mfe_pct                   (max high / E - 1) * 100                         (>= 0)
    mae_pct                   (1 - min low / E) * 100                          (>= 0, adverse size)
    time_to_mfe_s / mae_s     end of the first bar reaching that extreme
    time_to_first_positive_net_s   end of the first bar whose high >= E * (1 + cost_pct/100)
    max_adverse_before_profit_pct  adverse size over the bars BEFORE that bar (0 if it is bar 0);
                              NaN if the path never reaches positive net
    profit_bar_ambiguous      the profit bar's own low is below every earlier low (order inside the
                              bar unknown, so the true adverse-before-profit may be larger)
    peak_retracement_pct      after the profit bar: max over later bars j of
                              (highest high of bars [profit bar, j) - low_j) / E * 100;
                              NaN if never profitable or no later bar (includes the final reversal)
    pullback_before_continuation_pct  same drop, but only for bars j that are FOLLOWED by a higher
                              peak later in the path ("normal" pullback, the trailing target);
                              0 if the path made new peaks without such a dip; NaN if never profitable
                              or no new peak after the profit bar
    final_time_exit_gross_pct (close of the last bar / E - 1) * 100
    final_time_exit_net_pct   gross - cost_pct - funding_long
  SHORT is the mirror (favourable = down; net = -gross - cost_pct + funding_long).

Nothing here chooses an SL, activation or trailing level: that is Layer B (policy simulation, P3).
Future bars only create targets; they are never features (§9, §44).
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from .download import load_funding

TARGET_SCHEMA = "qt-path-targets-v4"
MINUTE = 60_000
DEFAULT_COST_PCT = 0.12
CELL_BUDGET = 2_000_000
VALID, GAP, OUT_OF_RANGE, SOURCE_CONFLICT = 0, 1, 2, 3
SIDES = ("long", "short")
FIELDS = ("mfe_pct", "mae_pct", "time_to_mfe_s", "time_to_mae_s", "time_to_first_positive_net_s",
          "max_adverse_before_profit_pct", "profit_bar_ambiguous", "peak_retracement_pct",
          "pullback_before_continuation_pct",
          "final_time_exit_gross_pct", "final_time_exit_net_pct")


def dense_minutes(m1: dict[str, np.ndarray]) -> dict[str, np.ndarray]:
    """1m bars on a dense minute grid starting at the first bar; missing minutes are NaN."""
    t = m1["open_time"]
    start = int(t[0])
    size = int((t[-1] - start) // MINUTE) + 1
    idx = ((t - start) // MINUTE).astype(np.int64)
    out = {"start": np.array(start, np.int64)}
    for key in ("open", "high", "low", "close"):
        arr = np.full(size, np.nan)
        arr[idx] = m1[key]
        out[key] = arr
    return out


def entry_status(grid: dict[str, np.ndarray], anchors: np.ndarray, n: int,
                 conflicts: np.ndarray | None = None) -> tuple[np.ndarray, np.ndarray]:
    """Entry bar index + validity, shared by Layer A and Layer B so the rules can never differ."""
    anchors = np.asarray(anchors, np.int64)
    start = int(grid["start"])
    size = len(grid["open"])
    entry_idx = ((anchors - start) // MINUTE).astype(np.int64)
    in_range = ((anchors - start) % MINUTE == 0) & (entry_idx >= 0) & (entry_idx + n <= size)
    missing_cum = np.r_[0, np.cumsum(np.isnan(grid["high"]).astype(np.int64))]
    safe = np.where(in_range, entry_idx, 0)
    gaps = np.where(in_range, missing_cum[np.minimum(safe + n, size)] - missing_cum[safe], 1)
    status = np.where(~in_range, OUT_OF_RANGE, np.where(gaps > 0, GAP, VALID)).astype(np.int8)
    if conflicts is not None and len(conflicts):
        # The anchor's own 5m candle [T0-5m, T0) or any path minute [T0, T0+H) in a conflicting bucket.
        c = np.sort(np.asarray(conflicts, np.int64))
        touched = np.searchsorted(c, anchors + n * MINUTE, side="left") - np.searchsorted(c, anchors - 300_000, side="left")
        status = np.where((status == VALID) & (touched > 0), SOURCE_CONFLICT, status).astype(np.int8)
    return entry_idx, status


def anchors_from_5m(m5: dict[str, np.ndarray]) -> np.ndarray:
    return (m5["open_time"] + 300_000).astype(np.int64)


def funding_sums(anchors: np.ndarray, horizon_ms: int, funding: dict[str, np.ndarray]) -> np.ndarray:
    """Sum of rates (in %) with T0 < f <= T0 + H, each event counted once (tests H/I)."""
    f = funding["funding_time"]
    if len(f) == 0:
        return np.zeros(len(anchors))
    csum = np.r_[0.0, np.cumsum(funding["funding_rate"] * 100.0)]
    lo = np.searchsorted(f, anchors, side="right")
    hi = np.searchsorted(f, anchors + horizon_ms, side="right")
    return csum[hi] - csum[lo]


def build_targets(m1: dict[str, np.ndarray], anchors: np.ndarray, horizon_min: int,
                  funding: dict[str, np.ndarray] | None = None, cost_pct: float = DEFAULT_COST_PCT,
                  chunk: int = 4000, conflicts: np.ndarray | None = None) -> dict[str, np.ndarray]:
    """Vectorized in chunks of anchors: memory is chunk x horizon, never N x horizon (§76)."""
    grid = dense_minutes(m1)
    n = int(horizon_min)
    count = len(anchors)
    entry_idx, status = entry_status(grid, anchors, n, conflicts)

    out: dict[str, np.ndarray] = {
        "anchor_time_ms": anchors.astype(np.int64),
        "entry_time_ms": anchors.astype(np.int64),
        "status": status,
        "entry_price": np.full(count, np.nan),
        "funding_long_pct": funding_sums(anchors, n * MINUTE, funding) if funding else np.zeros(count),
    }
    for side in SIDES:
        for field in FIELDS:
            out[f"{side}_{field}"] = np.full(count, np.nan)

    rows = np.nonzero(status == VALID)[0]
    chunk = max(64, min(chunk, CELL_BUDGET // max(n, 1)))   # ~2M cells per array, any horizon
    offsets = np.arange(n)
    seconds = (offsets + 1) * 60.0
    for begin in range(0, len(rows), chunk):
        sel = rows[begin:begin + chunk]
        win = entry_idx[sel][:, None] + offsets[None, :]
        highs, lows = grid["high"][win], grid["low"][win]
        entry = grid["open"][entry_idx[sel]]
        last_close = grid["close"][entry_idx[sel] + n - 1]
        fund = out["funding_long_pct"][sel]
        out["entry_price"][sel] = entry
        e = entry[:, None]
        # Moves in % of entry: favourable / adverse per side, per bar.
        up = (highs / e - 1.0) * 100.0          # long favourable, short adverse
        down = (1.0 - lows / e) * 100.0          # long adverse, short favourable
        gross_long = (last_close / entry - 1.0) * 100.0
        for side, fav, adv, gross, fund_sign in (("long", up, down, gross_long, -1.0), ("short", down, up, -gross_long, 1.0)):
            p = f"{side}_"
            mfe_i = np.argmax(fav, axis=1)
            mae_i = np.argmax(adv, axis=1)
            out[p + "mfe_pct"][sel] = fav[np.arange(len(sel)), mfe_i]
            out[p + "mae_pct"][sel] = adv[np.arange(len(sel)), mae_i]
            out[p + "time_to_mfe_s"][sel] = seconds[mfe_i]
            out[p + "time_to_mae_s"][sel] = seconds[mae_i]
            out[p + "final_time_exit_gross_pct"][sel] = gross
            out[p + "final_time_exit_net_pct"][sel] = gross - cost_pct + fund_sign * fund

            hit = fav >= cost_pct
            ever = hit.any(axis=1)
            k = np.argmax(hit, axis=1)                                    # first profit bar
            cum_adv = np.maximum.accumulate(adv, axis=1)                  # running adverse
            prev_adv = np.where(k > 0, cum_adv[np.arange(len(sel)), np.maximum(k - 1, 0)], 0.0)
            prev_adv = np.maximum(prev_adv, 0.0)
            bar_adv = adv[np.arange(len(sel)), k]
            out[p + "time_to_first_positive_net_s"][sel] = np.where(ever, seconds[k], np.nan)
            out[p + "max_adverse_before_profit_pct"][sel] = np.where(ever, prev_adv, np.nan)
            out[p + "profit_bar_ambiguous"][sel] = np.where(ever, (bar_adv > prev_adv).astype(float), np.nan)

            # Retracement after the profit bar: running peak of earlier bars vs this bar's low.
            masked_fav = np.where(offsets[None, :] >= k[:, None], fav, -np.inf)
            peak_before = np.maximum.accumulate(masked_fav, axis=1)
            peak_before = np.concatenate([np.full((len(sel), 1), -np.inf), peak_before[:, :-1]], axis=1)
            retr = np.where(offsets[None, :] > k[:, None], peak_before + adv, -np.inf)   # peak% - low% (signed)
            best = retr.max(axis=1)
            out[p + "peak_retracement_pct"][sel] = np.where(ever & np.isfinite(best), best, np.nan)

            # Normal pullback for trailing (D-026): the largest drop from the running peak (since the profit
            # bar) to a bar's low that was FOLLOWED by a new, higher peak later in the path. A pullback
            # with no continuation afterwards is the final reversal, not a "normal" pullback.
            rev = np.maximum.accumulate(fav[:, ::-1], axis=1)[:, ::-1]            # max fav over [j, end]
            after = np.concatenate([rev[:, 1:], np.full((len(sel), 1), -np.inf)], axis=1)   # max over (j, end]
            qualifies = (offsets[None, :] > k[:, None]) & (after > peak_before)
            pull = np.where(qualifies, retr, -np.inf).max(axis=1)
            continued = after[np.arange(len(sel)), k] > fav[np.arange(len(sel)), k]       # a new peak after the profit bar
            out[p + "pullback_before_continuation_pct"][sel] = np.where(
                ever & np.isfinite(pull), pull, np.where(ever & continued, 0.0, np.nan))
    return out


def summarize(targets: dict[str, np.ndarray]) -> dict:
    """Counts only. Distributions are reported in P3 on train/validation rows (holdout sealed)."""
    status = targets["status"]
    valid = status == VALID
    return {
        "anchors": int(len(status)), "valid": int(valid.sum()),
        "invalid_gap": int((status == GAP).sum()), "out_of_range": int((status == OUT_OF_RANGE).sum()),
        "source_conflict": int((status == SOURCE_CONFLICT).sum()),
    }


def dt_utc(ms) -> str:
    import datetime as dt
    return dt.datetime.fromtimestamp(int(ms) / 1000, dt.timezone.utc).strftime("%Y-%m-%d %H:%M")


def build_symbol(work: Path, symbol: str, horizons: list[int], cost_pct: float = DEFAULT_COST_PCT) -> list[dict]:
    """Writes targets/<SYMBOL>/h<H>.parquet + .json, one horizon at a time (§71, §77)."""
    t0 = time.monotonic()
    m1 = bd.load_klines(work, symbol, "1m")
    m5 = bd.load_klines(work, symbol, "5m")
    funding = load_funding(work, symbol)
    anchors = anchors_from_5m(m5)
    from .verify import conflict_buckets
    conflicts = conflict_buckets(m1, m5)
    load_s = round(time.monotonic() - t0, 1)
    reports = []
    for h in horizons:
        started = time.monotonic()
        targets = build_targets(m1, anchors, h, funding, cost_pct, conflicts=conflicts)
        path = work / "targets" / symbol / f"h{h}.parquet"
        bd.write_parquet(path, targets)
        meta = {
            "target_schema": TARGET_SCHEMA, "symbol": symbol, "horizon_minutes": h, "cost_pct": cost_pct,
            "entry_rule": "open of the 1m bar starting at T0 (anchor 5m close)",
            "path": "1m bars with open_time in [T0, T0+H)", "time_unit": "end of the first bar (seconds after entry)",
            "first_anchor_ms": int(anchors[0]) if len(anchors) else None, "last_anchor_ms": int(anchors[-1]) if len(anchors) else None,
            "counts": summarize(targets), "seconds": round(time.monotonic() - started, 1), "load_seconds": load_s,
            "source_conflict_buckets_utc": [dt_utc(x) for x in conflicts],
            "ram_estimate_mb": round(min(4000 * h, CELL_BUDGET) * 8 * 14 / 1e6 + len(m1["open_time"]) * 5 * 8 / 1e6, 1),
            "note": "Holdout rows are included here as raw data only; no statistic over the holdout is reported before P6.",
        }
        path.with_suffix(".json").write_text(json.dumps(meta, indent=1), "utf-8")
        reports.append(meta)
    return reports
