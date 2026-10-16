from __future__ import annotations

from collections import defaultdict
from typing import Mapping, Sequence


def aggregate_closed_ohlcv(rows: Sequence[Mapping[str, int | float]], interval_ms: int, as_of_ms: int) -> list[dict]:
    """Aggregate aligned closed 1m/5m candles without using a forming or future bar.

    Input rows require ``open_ms, open, high, low, close, volume, close_ms``. A group is emitted
    only when all source intervals are present, timestamps are aligned, and its close is strictly
    before ``as_of_ms``.
    """
    if interval_ms <= 0:
        raise ValueError("interval_ms must be positive")
    groups: dict[int, list[Mapping[str, int | float]]] = defaultdict(list)
    for row in rows:
        open_ms = int(row["open_ms"])
        close_ms = int(row["close_ms"])
        if open_ms < 0 or close_ms < open_ms or close_ms >= as_of_ms:
            continue
        bucket = open_ms - (open_ms % interval_ms)
        groups[bucket].append(row)
    expected = None
    if rows:
        source_interval = int(rows[0]["close_ms"]) - int(rows[0]["open_ms"]) + 1
        if source_interval > 0:
            expected = interval_ms // source_interval
    output = []
    for bucket in sorted(groups):
        group = sorted(groups[bucket], key=lambda x: int(x["open_ms"]))
        if expected is None or len(group) != expected:
            continue
        source_ms = int(group[0]["close_ms"]) - int(group[0]["open_ms"]) + 1
        if any(int(row["open_ms"]) != bucket + i * source_ms for i, row in enumerate(group)):
            continue
        close_ms = bucket + interval_ms - 1
        if close_ms >= as_of_ms:
            continue
        output.append({"open_ms": bucket, "close_ms": close_ms, "open": float(group[0]["open"]),
                       "high": max(float(row["high"]) for row in group),
                       "low": min(float(row["low"]) for row in group),
                       "close": float(group[-1]["close"]),
                       "volume": sum(float(row["volume"]) for row in group),
                       "source_count": len(group)})
    return output
