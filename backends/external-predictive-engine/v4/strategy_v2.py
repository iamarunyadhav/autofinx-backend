"""UT Bot Pro v6 research adapter.

The Pine webhook/dashboard code is intentionally excluded.  This adapter keeps
the causal UT trailing-stop signal, optional default-off filters, and the
audited 1m stop-first simulator used by Strategy Lab.
"""
from __future__ import annotations
from pathlib import Path
import numpy as np
from . import strategy_v13 as base

TRANSLATION_STATUS = "UT Bot Pro v6 conservative research translation"

def load_sealed(work: Path, symbol: str) -> dict:
    return base.load_sealed(work, symbol)

def _ut(src: np.ndarray, atr: np.ndarray, key: float) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    stop = np.full(len(src), np.nan); pos = np.zeros(len(src), dtype=int)
    buy = np.zeros(len(src), dtype=bool); sell = np.zeros(len(src), dtype=bool)
    prev = src[0] if len(src) else np.nan
    for i, x in enumerate(src):
        loss = key * atr[i] if np.isfinite(atr[i]) else np.nan
        old = prev if np.isfinite(prev) else x
        if i and np.isfinite(loss):
            if x > old and src[i-1] > old: cur = max(old, x-loss)
            elif x < old and src[i-1] < old: cur = min(old, x+loss)
            elif x > old: cur = x-loss
            else: cur = x+loss
        else: cur = x
        stop[i] = cur
        if i:
            buy[i] = src[i] > cur and src[i-1] <= stop[i-1]
            sell[i] = src[i] < cur and src[i-1] >= stop[i-1]
            pos[i] = 1 if src[i] > cur else -1 if src[i] < cur else pos[i-1]
        prev = cur
    return stop, buy, sell

def compute(bars: dict, base5: dict, symbol: str) -> dict:
    o,h,l,c,v = (np.asarray(bars[k], float) for k in ("open","high","low","close","volume"))
    tr = base.true_range(h,l,c); atr = base.rma(tr, 10)
    stop, buy, sell = _ut(c, atr, 1.0)
    ef, es, et = base.ema(c,9), base.ema(c,21), base.ema(c,50)
    long_ok = (ef > es) & (c > et); short_ok = (ef < es) & (c < et)
    # Pine defaults: UT Only, Both directions, score filter disabled.
    return {"setup_long": buy, "setup_short": sell, "atr": atr,
            "long_score": buy.astype(float)*100, "short_score": sell.astype(float)*100,
            "threshold": np.full(len(c),55.), "hard_long": long_ok,
            "hard_short": short_ok, "ut_stop": stop}

def s0_trades(data: dict, symbol: str, tf: str):
    bars = base.chart_bars(data["base"], tf); ind = compute(bars, data["base"], symbol)
    old = dict(base.P); base.P.update(cooldown=0, tp1_rr=2.0, trail_mult=1.0, flip_threshold=101.0)
    try: trades = base.simulate(bars, ind, data["grid"], data["funding"], base.CHART_MS[tf], symbol)
    finally: base.P.clear(); base.P.update(old)
    return bars, ind, trades

