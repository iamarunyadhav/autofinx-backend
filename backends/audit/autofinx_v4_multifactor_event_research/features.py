from __future__ import annotations

import math
from typing import Mapping, Sequence


def causal_features(candles: Sequence[Mapping[str, float]]) -> dict[str, float | None]:
    """Small, independently testable causal factor set; no future rows are read."""
    if len(candles) < 21:
        return {"return_4": None, "relative_volume_20": None, "atr_pct_20": None, "rsi_14": None,
                "body_pct": None, "range_pct": None, "trend_slope_20": None}
    closes = [float(x["close"]) for x in candles]
    volumes = [float(x["volume"]) for x in candles]
    last = closes[-1]
    gains, losses = [], []
    for a, b in zip(closes[-15:-1], closes[-14:]):
        delta = b - a
        gains.append(max(delta, 0.0)); losses.append(max(-delta, 0.0))
    avg_gain, avg_loss = sum(gains) / 14, sum(losses) / 14
    rsi = 100.0 if avg_loss == 0 else 100 - 100 / (1 + avg_gain / avg_loss)
    trs = []
    for i in range(len(candles) - 20, len(candles)):
        row, prev = candles[i], closes[i - 1]
        trs.append(max(float(row["high"]) - float(row["low"]), abs(float(row["high"]) - prev), abs(float(row["low"]) - prev)))
    recent = candles[-1]
    body_pct = (float(recent["close"]) - float(recent["open"])) / last
    range_pct = (float(recent["high"]) - float(recent["low"])) / last
    # OLS slope of close against equally spaced causal observations, normalized by price.
    ys = closes[-20:]
    xbar = 9.5
    ybar = sum(ys) / 20
    slope = sum((i - xbar) * (y - ybar) for i, y in enumerate(ys)) / 665.0
    return {"return_4": last / closes[-5] - 1, "relative_volume_20": volumes[-1] / (sum(volumes[-21:-1]) / 20),
            "atr_pct_20": sum(trs) / 20 / last, "rsi_14": rsi / 100,
            "body_pct": body_pct, "range_pct": range_pct, "trend_slope_20": slope / last}
