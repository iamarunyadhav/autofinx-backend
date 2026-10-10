"""Stream counters, liveness, aggTrade sequence checks, gap log, daily stats."""
from __future__ import annotations

import json
import os
import time
from pathlib import Path


def utc_iso(ns=None):
    ns = time.time_ns() if ns is None else ns
    s = ns // 1_000_000_000
    return time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(s)) + f".{(ns % 1_000_000_000) // 1_000_000:03d}Z"


def day_of(ns):
    return time.strftime("%Y-%m-%d", time.gmtime(ns // 1_000_000_000))


class StreamStats:
    def __init__(self, keys=()):
        self.count = {k: 0 for k in keys}
        self.last_ns = {}
        self.last_event_ms = {}

    def hit(self, key, recv_ns, event_ms=None):
        self.count[key] = self.count.get(key, 0) + 1
        self.last_ns[key] = recv_ns
        if event_ms is not None:
            self.last_event_ms[key] = event_ms

    def age_s(self, key, now_ns):
        t = self.last_ns.get(key)
        return None if t is None else (now_ns - t) / 1e9


class LivenessMonitor:
    """Detects streams silent beyond their threshold (the exact silent-drop failure).
    keys: {key: (kind, conn_name)}; thresholds by kind in seconds."""

    def __init__(self, keys, thresholds, stats, start_ns):
        self.keys, self.th, self.stats = keys, thresholds, stats
        self.start_ns = start_ns
        self._alarm_ns = {}
        self._grace = {}

    def reset_grace(self, now_ns, conn=None):
        """After a reconnect the silence clock restarts: a fresh connection gets a fresh chance."""
        for k, (_, c) in self.keys.items():
            if conn is None or c == conn:
                self._alarm_ns.pop(k, None)
                self._grace[k] = now_ns

    def check(self, now_ns):
        """Streams silent beyond threshold; re-alarms at most once per threshold period."""
        out = []
        for k, (kind, conn) in self.keys.items():
            th = self.th.get(kind)
            if th is None:
                continue
            ref = max(x for x in (self.stats.last_ns.get(k), self._grace.get(k), self.start_ns) if x is not None)
            silent = (now_ns - ref) / 1e9
            if silent > th:
                last_alarm = self._alarm_ns.get(k)
                if last_alarm is None or (now_ns - last_alarm) / 1e9 > th:
                    self._alarm_ns[k] = now_ns
                    out.append({"key": k, "conn": conn, "silent_s": round(silent, 1), "threshold_s": th,
                                "never_seen": self.stats.last_ns.get(k) is None})
            else:
                self._alarm_ns.pop(k, None)
        return out


class SeqChecker:
    """aggTrade aggregate-id continuity per symbol (Binance aggregate ids are consecutive)."""

    def __init__(self):
        self.last = {}

    def feed(self, sym, agg_id):
        prev = self.last.get(sym)
        if prev is None or agg_id == prev + 1:
            self.last[sym] = agg_id
            return None
        if agg_id > prev + 1:
            self.last[sym] = agg_id
            return {"kind": "missing_agg_ids", "sym": sym, "from_id": prev + 1, "to_id": agg_id - 1, "count": agg_id - prev - 1}
        return {"kind": "dup_or_out_of_order", "sym": sym, "id": agg_id, "last_id": prev, "count": 1}


class GapLog:
    """Separate append-only JSONL log of anything that can create a hole in the data."""

    def __init__(self, path: Path, daily=None):
        self.path, self.daily = Path(path), daily
        self.last = None

    def log(self, kind, **fields):
        ns = time.time_ns()
        rec = {"ts_utc": utc_iso(ns), "ts_ns": ns, "kind": kind, **fields}
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with open(self.path, "a", encoding="utf-8", newline="\n") as f:
            f.write(json.dumps(rec, separators=(",", ":")) + "\n")
        self.last = rec
        if self.daily is not None:
            self.daily.bump("gaps", kind, ns)
        return rec

    def tail(self):
        if self.last:
            return self.last
        try:
            lines = Path(self.path).read_bytes().splitlines()
            return json.loads(lines[-1]) if lines else None
        except (OSError, ValueError):
            return None


class DailyStats:
    """Per-UTC-day counters, persisted so restarts continue the same day."""

    def __init__(self, stats_dir: Path):
        self.dir = Path(stats_dir)
        self.days = {}
        self._cache = (None, None)

    def _day(self, ns):
        idx = ns // 86_400_000_000_000
        if self._cache[0] != idx:
            self._cache = (idx, day_of(ns))
        return self._cache[1]

    def _get(self, day):
        d = self.days.get(day)
        if d is None:
            try:
                d = json.loads((self.dir / f"{day}.json").read_text(encoding="utf-8"))
            except (OSError, ValueError):
                d = {"day": day, "counts": {}, "missing_ids": {}, "dup_or_ooo": {}, "gaps": {}, "down_s": {},
                     "reconnects": {}, "clock": {"n": 0, "sum": 0.0, "min": None, "max": None, "rtt_max": None},
                     "dropped_disk": 0, "starts": 0}
            self.days[day] = d
        return d

    def count(self, key, ns, n=1):
        c = self._get(self._day(ns))["counts"]
        c[key] = c.get(key, 0) + n

    def bump(self, section, key, ns, n=1):
        c = self._get(self._day(ns))[section]
        c[key] = c.get(key, 0) + n

    def add_scalar(self, key, ns, n=1):
        d = self._get(self._day(ns))
        d[key] = d.get(key, 0) + n

    def clock(self, ns, offset_ms, rtt_ms):
        c = self._get(self._day(ns))["clock"]
        c["n"] += 1
        c["sum"] += offset_ms
        c["min"] = offset_ms if c["min"] is None else min(c["min"], offset_ms)
        c["max"] = offset_ms if c["max"] is None else max(c["max"], offset_ms)
        c["rtt_max"] = rtt_ms if c["rtt_max"] is None else max(c["rtt_max"], rtt_ms)

    def save(self, keep=3):
        self.dir.mkdir(parents=True, exist_ok=True)
        for day in sorted(self.days)[-keep:]:
            p = self.dir / f"{day}.json"
            tmp = p.with_suffix(".tmp")
            tmp.write_text(json.dumps(self.days[day], separators=(",", ":")), encoding="utf-8")
            os.replace(tmp, p)
        for day in sorted(self.days)[:-keep]:
            self.days.pop(day, None)
