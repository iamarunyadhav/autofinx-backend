"""P2 (D-036 roadmap, D-037 pre-registration) — two event-conditioned setup families, rule-only (no ML yet).

Hypothesis: the BTC h60 direction signal is ~0.01 %/anchor (R-022), a tenth of the cost. Trading only at
events where a large move is more likely might lift the per-trade move above cost. Every parameter below is
fixed in advance (no search). Development data only: entries restricted to dev_train; the holdout is cut off
before anything is computed (strategy_v13.load_sealed); dev_val is not touched.

Context uses only COMPLETE higher-timeframe buckets (bucket end <= T0). Entry: open of the 1m bar at T0 (5m
close), as everywhere in V4. Execution: policy.simulate + apply_worst_case (stop first, gaps at open, funding),
cost 0.12 % (stress 0.15 / 0.18). One position at a time per setup x side x exit (non-overlapping).

S1 TREND_PULLBACK (long; short mirrored)
  context   1h: close > EMA20 > EMA50 (last complete 1h bucket)
  pullback  within the last 6 bars the low touched the 5m EMA20, and the 24-bar high minus the pullback low
            >= 1.0 x ATR14(5m)
  trigger   5m close > previous bar high and close > EMA20(5m)
S2 RANGE_REVERSION (long; short mirrored)
  context   1h ADX14 < 20 (last complete 1h bucket)
  trigger   the bar's low pierced the previous 48-bar low (4 h range) and the bar closed back above it
  (invalidation = the stop below the pierce, via exit E1/E2 stop)
Exits (fixed, ATR in % of price at T0):
  E1  stop 1.5 x ATR, no trailing, time exit at H
  E2  stop 1.5 x ATR, activation 1.0 x ATR, trail 1.0 x ATR
Baselines on the same window: CONTEXT_ONLY (every bar where the context holds, same side and exits) and
ALWAYS (every bar), so the trigger's added value is visible.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import policy as pol
from . import strategy_v13 as v13
from .p1_signal import block_bootstrap_ci
from .verify import conflict_buckets
from . import binance_data as bd

COSTS = (0.12, 0.15, 0.18)
H1 = 3_600_000
EXITS = {"E1": (1.5, None, None), "E2": (1.5, 1.0, 1.0)}
NEVER = 1e6


def _htf(base: dict, anchors: np.ndarray, tf_ms: int):
    hb = v13.resample(base, tf_ms)
    ends = hb["open_time"] + tf_ms
    idx = np.searchsorted(ends, anchors, side="right") - 1          # last bucket with end <= T0
    return hb, idx


def events(base: dict) -> dict[str, np.ndarray]:
    """Boolean event arrays per 5m bar (index aligned with base), causal by construction."""
    o, h, l, c = (np.asarray(base[k], float) for k in ("open", "high", "low", "close"))
    anchors = np.asarray(base["open_time"], np.int64) + 300_000
    with np.errstate(invalid="ignore", divide="ignore"):
        atr = v13.rma(v13.true_range(h, l, c), 14)
        e20 = v13.ema(c, 20)
        hb, idx = _htf(base, anchors, H1)
        he20, he50 = v13.ema(hb["close"], 20), v13.ema(hb["close"], 50)
        _, _, hadx = v13.dmi(hb["high"], hb["low"], hb["close"], 14, 14)
        ok = idx >= 0
        safe = np.maximum(idx, 0)
        hc = np.where(ok, hb["close"][safe], np.nan)
        up_ctx = ok & (hc > he20[safe]) & (he20[safe] > he50[safe])
        dn_ctx = ok & (hc < he20[safe]) & (he20[safe] < he50[safe])
        range_ctx = ok & (hadx[safe] < 20)

        low6 = v13.lowest(l, 6)
        high6 = v13.highest(h, 6)
        touched_dn = low6 <= e20                     # pullback down to the EMA (for longs)
        touched_up = high6 >= e20
        depth_long = (v13.highest(h, 24) - low6) >= 1.0 * atr
        depth_short = (high6 - v13.lowest(l, 24)) >= 1.0 * atr
        ph, pl = v13.shift(h), v13.shift(l)
        s1_long = up_ctx & touched_dn & depth_long & (c > ph) & (c > e20)
        s1_short = dn_ctx & touched_up & depth_short & (c < pl) & (c < e20)

        prev_low48 = v13.shift(v13.lowest(l, 48))
        prev_high48 = v13.shift(v13.highest(h, 48))
        s2_long = range_ctx & (l < prev_low48) & (c > prev_low48)
        s2_short = range_ctx & (h > prev_high48) & (c < prev_high48)
        atr_pct = atr / c * 100.0
    return {"anchors": anchors, "atr_pct": atr_pct,
            "S1_long": s1_long, "S1_short": s1_short, "S2_long": s2_long, "S2_short": s2_short,
            "S1_ctx_long": up_ctx, "S1_ctx_short": dn_ctx, "S2_ctx_long": range_ctx, "S2_ctx_short": range_ctx,
            "ALWAYS_long": np.ones(len(c), bool), "ALWAYS_short": np.ones(len(c), bool)}


def _stats(net: np.ndarray, gross: np.ndarray, times: np.ndarray, lo: int, hi: int) -> dict:
    if len(net) == 0:
        return {"trades": 0}
    gp, gl = net[net > 0].sum(), -net[net < 0].sum()
    std = net.std(ddof=1) if len(net) > 1 else 0.0
    edges = np.linspace(lo, hi + 1, 5)
    slices = [round(float(net[(times >= a) & (times < b)].mean()), 4) if ((times >= a) & (times < b)).any() else None
              for a, b in zip(edges[:-1], edges[1:])]
    return {"trades": int(len(net)), "avg_gross_pct": round(float(gross.mean()), 5), "avg_net_pct": round(float(net.mean()), 5),
            "pf": round(float(gp / gl), 3) if gl > 0 else None, "win_pct": round(float((net > 0).mean() * 100), 1),
            "t": round(float(net.mean() / (std / np.sqrt(len(net)))), 2) if std > 0 else None,
            "boot90": block_bootstrap_ci(net, times), "slices_avg_net": slices}


def run(work: Path, symbol: str, horizon: int) -> dict:
    started = time.monotonic()
    data = v13.load_sealed(work, symbol)
    base, grid, funding = data["base"], data["grid"], data["funding"]
    m1 = bd.load_klines(work, symbol, "1m"); m5 = bd.load_klines(work, symbol, "5m")
    conflicts = conflict_buckets(m1, m5)
    ev = events(base)
    a = ev["anchors"]
    lo, hi = data["train_start"], data["train_end"]
    window = (a >= lo) & (a <= hi) & np.isfinite(ev["atr_pct"])
    report = {"symbol": symbol, "horizon_minutes": horizon, "window": {"start_ms": lo, "end_ms": hi, "data": "dev_train only"},
              "rules": "D-037 (fixed, no search)", "setups": {}}
    for name in ("S1", "S2", "S1_ctx", "S2_ctx", "ALWAYS"):
        for side in ("long", "short"):
            mask = ev[f"{name}_{side}"] & window
            rows = np.nonzero(mask)[0]
            entry = {"events": int(len(rows))}
            for ex_name, (k_sl, k_act, k_trail) in EXITS.items():
                if len(rows) == 0:
                    entry[ex_name] = {"trades": 0}
                    continue
                atr = ev["atr_pct"][rows]
                sl = k_sl * atr
                act = (k_act * atr) if k_act else np.full(len(rows), NEVER)
                trail = (k_trail * atr) if k_trail else np.full(len(rows), NEVER)
                sim = pol.simulate(None, a[rows], horizon, side, sl, act, trail, COSTS[0], funding, conflicts, grid=grid)
                sim = pol.apply_worst_case(sim, grid, a[rows], horizon, side, sl, act, trail, COSTS[0], funding)
                good = np.isfinite(sim["net_pct"])
                idx = pol.non_overlapping(a[rows][good], np.ones(int(good.sum()), bool), sim["exit_time_ms"][good])
                net = sim["net_pct"][good][idx]
                gross = sim["gross_pct"][good][idx]
                t_ex = a[rows][good][idx]
                entry[ex_name] = {f"cost_{c}": _stats(net - (c - COSTS[0]), gross, t_ex, lo, hi) for c in COSTS}
                entry[ex_name]["avg_atr_pct"] = round(float(np.mean(atr)), 4)
            report["setups"][f"{name}_{side}"] = entry
    from .runinfo import stamp
    report["seconds"] = round(time.monotonic() - started, 1)
    report["identity"] = stamp()
    out = work / "reports" / "p2"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}_h{horizon}.json").write_text(json.dumps(report, indent=1, default=str), "utf-8")
    return report
