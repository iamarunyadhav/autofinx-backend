"""Append-only hourly-rotated gzip JSONL (UTC). Each (re)open appends a new gzip member, so a restart in
the same hour is safe; periodic sync-flush bounds crash loss to a few seconds of the open hour."""
from __future__ import annotations

import gzip
import json
import time
from pathlib import Path

HOUR_NS = 3_600_000_000_000


class HourlyGzipWriter:
    def __init__(self, root: Path, kind: str, level=5):
        self.root, self.kind, self.level = Path(root), kind, level
        self._hour = None
        self._f = None
        self._gz = None
        self.records = 0
        self.path = None

    def path_for(self, hour_idx):
        t = time.gmtime(hour_idx * 3600)
        day, hh = time.strftime("%Y%m%d", t), time.strftime("%Y%m%d%H", t)
        return self.root / self.kind / day / f"{self.kind}_{hh}.jsonl.gz"

    def write(self, line: bytes, recv_ns: int):
        hour = recv_ns // HOUR_NS
        if hour != self._hour:
            self._open(hour)
        self._gz.write(line)
        self.records += 1

    def _open(self, hour):
        self.close()
        p = self.path_for(hour)
        p.parent.mkdir(parents=True, exist_ok=True)
        self._f = open(p, "ab")
        self._gz = gzip.GzipFile(fileobj=self._f, mode="ab", compresslevel=self.level)
        self._hour, self.path = hour, p

    def flush(self):
        if self._gz is not None:
            self._gz.flush()
            self._f.flush()

    def rotate_if_stale(self, now_ns):
        if self._hour is not None and now_ns // HOUR_NS != self._hour:
            self.close()

    def close(self):
        if self._gz is not None:
            try:
                self._gz.close()
            finally:
                self._f.close()
        self._gz = self._f = self._hour = None


class WriterSet:
    def __init__(self, root, level=5):
        self.root, self.level = Path(root), level
        self.writers = {}
        self.paused = False
        self.dropped = 0

    def write(self, kind, rec: dict, recv_ns: int) -> bool:
        if self.paused:
            self.dropped += 1
            return False
        w = self.writers.get(kind)
        if w is None:
            w = self.writers[kind] = HourlyGzipWriter(self.root, kind, self.level)
        w.write((json.dumps(rec, separators=(",", ":")) + "\n").encode("utf-8"), recv_ns)
        return True

    def flush(self):
        for w in self.writers.values():
            w.flush()

    def rotate(self, now_ns):
        for w in self.writers.values():
            w.rotate_if_stale(now_ns)

    def close(self):
        for w in self.writers.values():
            w.close()
