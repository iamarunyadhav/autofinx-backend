from __future__ import annotations

import csv, hashlib, io, sqlite3, zipfile
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class ImportSummary:
    symbol: str
    timeframe: str
    source: str
    source_sha256: str
    rows_seen: int
    rows_imported: int
    duplicates: int
    gaps: int
    malformed: int
    funding_rows: int


def _sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _rows(path: Path):
    with zipfile.ZipFile(path) as z:
        names = [n for n in z.namelist() if not n.endswith("/")]
        if len(names) != 1:
            raise ValueError(f"expected one payload in {path.name}")
        with z.open(names[0]) as raw:
            yield from csv.reader(io.TextIOWrapper(raw))


def ensure_schema(db: sqlite3.Connection) -> None:
    db.executescript("""
    CREATE TABLE IF NOT EXISTS observations (
      symbol TEXT NOT NULL, timeframe TEXT NOT NULL, open_ms INTEGER NOT NULL,
      close_ms INTEGER NOT NULL, open REAL NOT NULL, high REAL NOT NULL,
      low REAL NOT NULL, close REAL NOT NULL, volume REAL,
      taker_buy_volume REAL, source TEXT NOT NULL, source_sha256 TEXT NOT NULL,
      PRIMARY KEY(symbol, timeframe, open_ms)
    );
    CREATE TABLE IF NOT EXISTS funding (
      symbol TEXT NOT NULL, funding_ms INTEGER NOT NULL, rate REAL NOT NULL,
      source TEXT NOT NULL, source_sha256 TEXT NOT NULL,
      PRIMARY KEY(symbol, funding_ms)
    );
    """)


def import_klines(db: sqlite3.Connection, path: Path, symbol: str, timeframe: str = "1h") -> ImportSummary:
    sha = _sha256(path); seen = imported = duplicates = gaps = malformed = 0; previous = None
    for row in _rows(path):
        if not row or not row[0].isdigit():
            continue
        seen += 1
        try:
            vals = [float(row[i]) for i in range(1, 6)]
            open_ms, close_ms = int(row[0]), int(row[6])
            if len(row) < 7 or close_ms <= open_ms or vals[1] < vals[2] or vals[0] <= 0 or vals[3] <= 0 or vals[4] < 0:
                raise ValueError("invalid OHLCV")
            if previous is not None:
                delta = open_ms - previous
                interval = 60 * 60 * 1000 if timeframe == "1h" else None
                if interval and delta != interval:
                    gaps += 1
            previous = open_ms
            cur = db.execute("INSERT OR IGNORE INTO observations VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                (symbol, timeframe, open_ms, close_ms, *vals, None, str(path), sha))
            if cur.rowcount: imported += 1
            else: duplicates += 1
        except (ValueError, IndexError):
            malformed += 1
    db.commit()
    return ImportSummary(symbol, timeframe, str(path), sha, seen, imported, duplicates, gaps, malformed, 0)


def import_funding(db: sqlite3.Connection, path: Path, symbol: str) -> ImportSummary:
    sha = _sha256(path); seen = imported = duplicates = malformed = 0
    for row in _rows(path):
        if not row or not row[0].isdigit(): continue
        seen += 1
        try:
            ts, rate = int(row[0]), float(row[2])
            cur = db.execute("INSERT OR IGNORE INTO funding VALUES (?,?,?,?,?)", (symbol, ts, rate, str(path), sha))
            if cur.rowcount: imported += 1
            else: duplicates += 1
        except (ValueError, IndexError): malformed += 1
    db.commit()
    return ImportSummary(symbol, "funding", str(path), sha, seen, imported, duplicates, 0, malformed, imported)
