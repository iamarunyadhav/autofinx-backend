"""bookTicker -> 1 second snapshots. Bucket = UTC second of local receive time."""
from __future__ import annotations


def _r(v):
    return None if v is None else round(v, 10)


class Book1sAggregator:
    def __init__(self):
        self._b = {}

    def add(self, sym, data, recv_ns):
        """Add one bookTicker message; returns a finished record of the previous second if one just closed."""
        sec = recv_ns // 1_000_000_000
        b = self._b.get(sym)
        out = None
        if b is not None and b["sec"] != sec:
            out = self._finish(sym, b)
            b = None
        bid, ask = data["b"], data["a"]
        try:
            spr = float(ask) - float(bid)
        except (TypeError, ValueError):
            spr = None
        if b is None:
            b = {"sec": sec, "n": 0, "smin": None, "smax": None}
            self._b[sym] = b
        b.update(bid=bid, bsz=data["B"], ask=ask, asz=data["A"], rt=recv_ns, et=data.get("E"), tt=data.get("T"), u=data.get("u"))
        b["n"] += 1
        if spr is not None:
            b["smin"] = spr if b["smin"] is None else min(b["smin"], spr)
            b["smax"] = spr if b["smax"] is None else max(b["smax"], spr)
        return out

    def flush(self, now_ns):
        """Emit every bucket whose second is already over."""
        sec = now_ns // 1_000_000_000
        return [self._finish(s, self._b.pop(s)) for s in [s for s, b in self._b.items() if b["sec"] < sec]]

    def flush_all(self):
        out = [self._finish(s, b) for s, b in self._b.items()]
        self._b.clear()
        return out

    @staticmethod
    def _finish(sym, b):
        return {
            "sym": sym, "rt": b["rt"], "et": b["et"],
            "d": {"sec": b["sec"], "bid": b["bid"], "bsz": b["bsz"], "ask": b["ask"], "asz": b["asz"],
                  "n": b["n"], "spr_min": _r(b["smin"]), "spr_max": _r(b["smax"]), "u": b["u"], "T": b["tt"]},
        }
