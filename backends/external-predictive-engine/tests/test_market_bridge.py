import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))

from external_predictive_engine.market_bridge import parse_snapshot


def test_valid_fresh_canonical_snapshot_is_parsed():
    import json
    import time

    now = int(time.time() * 1000)
    snapshot = parse_snapshot(json.dumps({
        "symbol": "BTCUSDT", "price": 84000, "event_timestamp_ms": now - 50,
        "received_at_ms": now, "source": "binance_websocket", "market_type": "futures",
    }), "BTCUSDT")
    assert snapshot is not None
    assert snapshot.price == 84000


def test_stale_or_wrong_symbol_snapshot_is_rejected():
    import json
    import time

    now = int(time.time() * 1000)
    raw = json.dumps({"symbol": "ETHUSDT", "price": 2000, "event_timestamp_ms": now, "received_at_ms": now})
    assert parse_snapshot(raw, "BTCUSDT") is None
