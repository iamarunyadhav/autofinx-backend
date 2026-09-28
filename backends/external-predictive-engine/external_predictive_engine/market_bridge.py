"""Read-only bridge for AutoFinX's existing canonical Redis market feed.

This module never opens a Binance websocket. Laravel's existing feed workers
remain the sole exchange consumers; the external service only reads their
canonical snapshot when Redis integration is enabled in a later deployment.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass
from typing import Any, Protocol


@dataclass(frozen=True)
class MarketSnapshot:
    symbol: str
    price: float
    event_timestamp_ms: int
    received_at_ms: int
    source: str
    market_type: str | None = None

    @property
    def age_ms(self) -> int:
        return max(0, int(time.time() * 1000) - self.received_at_ms)


class SnapshotReader(Protocol):
    def read(self, symbol: str) -> MarketSnapshot | None: ...


def parse_snapshot(raw: str | bytes | None, expected_symbol: str, max_age_ms: int = 15_000) -> MarketSnapshot | None:
    if not raw:
        return None
    try:
        data: dict[str, Any] = json.loads(raw)
        symbol = str(data["symbol"]).upper()
        price = float(data["price"])
        event_ms = int(data["event_timestamp_ms"])
        received_ms = int(data["received_at_ms"])
    except (KeyError, TypeError, ValueError, json.JSONDecodeError):
        return None
    if symbol != expected_symbol.upper() or price <= 0 or event_ms <= 0 or received_ms <= 0:
        return None
    snapshot = MarketSnapshot(symbol, price, event_ms, received_ms, str(data.get("source", "")), data.get("market_type"))
    if snapshot.age_ms > max_age_ms:
        return None
    if event_ms > received_ms + max_age_ms:
        return None
    return snapshot


class RedisCanonicalFeed:
    """Read-only access to the existing ``market:live:{SYMBOL}`` key."""

    def __init__(self, redis_client: Any, max_age_ms: int = 15_000) -> None:
        self.redis = redis_client
        self.max_age_ms = max_age_ms

    def read(self, symbol: str) -> MarketSnapshot | None:
        raw = self.redis.get(f"market:live:{symbol.upper()}")
        return parse_snapshot(raw, symbol, self.max_age_ms)
