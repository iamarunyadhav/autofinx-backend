"""Pure parsing of combined-stream frames into (kind, symbol, event_ms, data)."""
from __future__ import annotations

import json


def kind_from_stream(stream: str):
    """btcusdt@aggTrade -> (aggTrade, btcusdt); !forceOrder@arr -> (forceOrder, None)."""
    parts = stream.split("@")
    if parts[0] == "!forceOrder":
        return "forceOrder", None
    sym, name = parts[0], parts[1]
    if name.startswith("depth"):
        return "depth", sym
    return name, sym


def parse_frame(raw):
    """Return (kind, symbol, event_ms, data) or None for non-data frames (subscription acks)."""
    msg = json.loads(raw)
    if not isinstance(msg, dict) or "stream" not in msg or "data" not in msg:
        return None
    kind, sym = kind_from_stream(msg["stream"])
    data = msg["data"]
    if kind == "forceOrder":
        o = data.get("o", {})
        sym = (o.get("s") or "").lower() or None
        ev = data.get("E") or o.get("T")
    else:
        ev = data.get("E")
        if ev is None:
            ev = data.get("T")
    return kind, sym, ev, data


def stream_key(kind, sym):
    return kind if kind == "forceOrder" or sym is None else f"{kind}@{sym}"
