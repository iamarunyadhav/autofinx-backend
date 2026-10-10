"""AutoFinX v10.1 Pine candidate adapter.

This adapter deliberately reuses the audited Pine-semantics indicators and
execution simulator from strategy_v13.  v10.1-specific gates are applied in
the wrapper; the adapter is research-only and never emits orders.
"""
from __future__ import annotations

from pathlib import Path
import numpy as np

from . import strategy_v13 as base

TRANSLATION_STATUS = "v10.1 adapter; conservative research translation"

def load_sealed(work: Path, symbol: str) -> dict:
    return base.load_sealed(work, symbol)

def _v10_gates(ind: dict, bars: dict, symbol: str) -> dict:
    out = {k: np.asarray(v).copy() if isinstance(v, np.ndarray) else v for k, v in ind.items()}
    c = np.asarray(bars["close"], float)
    o = np.asarray(bars["open"], float)
    h = np.asarray(bars["high"], float)
    v = np.asarray(bars["volume"], float)
    lookback = 3
    recent_high = base.highest(h, lookback)
    prior_high = base.shift(recent_high, lookback)
    lower_high = (h < base.shift(recent_high)) & (base.shift(recent_high) < prior_high)
    bear = np.where(c < o, v, 0.0)
    bull = np.where(c > o, v, 0.0)
    bear_pressure = base.sma(bear, 5) >= base.sma(bull, 5) * 0.8
    short = out["setup_short"] & lower_high & bear_pressure & (out["htf3"] <= 0)
    out["setup_short"] = short
    out["setup_long"] = np.zeros_like(short, dtype=bool)
    return out

def s0_trades(data: dict, symbol: str, tf: str):
    bars = base.chart_bars(data["base"], tf)
    ind = base.compute(bars, data["base"], symbol)
    ind = _v10_gates(ind, bars, symbol)
    trades = base.simulate(bars, ind, data["grid"], data["funding"], {"5m": 300_000, "15m": 900_000}[tf], symbol)
    assert all(x["exit_time"] <= data["holdout_start"] for x in trades)
    return bars, ind, trades
