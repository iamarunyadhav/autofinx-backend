"""Local Binance USDT-M futures data layer for V4 (SPEC §3-7, §45-48, D-002).

Sources (official Binance only):
  * data.binance.vision monthly zips for complete months, daily zips for the current month,
    each verified against its published .CHECKSUM (sha256);
  * Binance REST (fapi) for the not-yet-published tail of today, CLOSED candles only.

Layout under <work>/data/<SYMBOL>/:
  1m/  5m/          monthly/<YYYY-MM>.parquet, daily/<YYYY-MM-DD>.parquet, tail.parquet
  funding/          monthly/<YYYY-MM>.parquet, tail.parquet
  metrics/          daily/<YYYY-MM-DD>.parquet           (G7 coverage audit only, SPEC §102 L3)
  manifest.json     what was fetched, from where, sha256, row counts, "not listed" months

Every file is written atomically (tmp + os.replace), so Ctrl+C never leaves a half file that
looks complete. A finished month is never downloaded again (resume).
"""

from __future__ import annotations

import csv
import datetime as dt
import hashlib
import http.client
import io
import json
import os
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path
from typing import Any, Callable, Iterable

import numpy as np

BULK = "https://data.binance.vision/data/futures/um"
REST = "https://fapi.binance.com"
USER_AGENT = "autofinx-ml-v4/1 (research downloader)"

KLINE_COLUMNS = ["open_time", "open", "high", "low", "close", "volume", "close_time",
                 "quote_volume", "count", "taker_buy_volume", "taker_buy_quote_volume"]
INTERVAL_MS = {"1m": 60_000, "5m": 300_000}

# History per symbol (SPEC §3). Unlisted symbols default to 36 months.
HISTORY_MONTHS = {"BTCUSDT": 60, "ETHUSDT": 60, "BNBUSDT": 60, "SOLUSDT": 36, "XRPUSDT": 36, "SUIUSDT": 36}


class NotFound(Exception):
    """The file does not exist on Binance (e.g. before the listing date)."""


# --------------------------------------------------------------------------- HTTP

def http_get(url: str, timeout: float = 60.0, retries: int = 6, sleep: Callable[[float], None] = time.sleep) -> bytes:
    """GET with exponential backoff; respects 429/418 Retry-After; 404 raises NotFound at once."""
    delay = 2.0
    for attempt in range(1, retries + 1):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.read()
        except urllib.error.HTTPError as error:
            if error.code == 404:
                raise NotFound(url) from error
            if attempt == retries:
                raise
            wait = float(error.headers.get("Retry-After") or delay) if error.code in (418, 429) else delay
            sleep(min(wait, 120.0))
        except (urllib.error.URLError, TimeoutError, ConnectionError, OSError, http.client.HTTPException):
            if attempt == retries:
                raise
            sleep(delay)
        delay = min(delay * 2, 60.0)
    raise RuntimeError("unreachable")


def fetch_verified_zip(url: str, get: Callable[[str], bytes] = http_get) -> tuple[bytes, str]:
    """Download a bulk zip and verify it against <url>.CHECKSUM. Returns (zip bytes, sha256)."""
    payload = get(url)
    digest = hashlib.sha256(payload).hexdigest()
    try:
        expected = get(url + ".CHECKSUM").decode("ascii", "replace").split()[0].strip().lower()
    except NotFound:
        expected = None
    if expected is not None and expected != digest:
        raise ValueError(f"checksum mismatch for {url}: expected {expected}, got {digest}")
    return payload, digest


# --------------------------------------------------------------------------- parsing

def _to_ms(values: np.ndarray) -> np.ndarray:
    """Binance moved some bulk files to microseconds; normalise to milliseconds."""
    values = values.astype(np.int64)
    if values.size and values.max() > 10**14:
        values = values // 1000
    return values


def _csv_rows(text: str) -> list[list[str]]:
    rows = [row for row in csv.reader(io.StringIO(text)) if row]
    if rows and not rows[0][0].strip().lstrip("-").isdigit():
        rows = rows[1:]  # newer files carry a header row, older ones do not
    return rows


def parse_klines_csv(text: str) -> dict[str, np.ndarray]:
    rows = _csv_rows(text)
    if not rows:
        return empty_klines()
    cols = list(zip(*[row[:11] for row in rows]))
    out = {
        "open_time": _to_ms(np.array(cols[0], dtype=np.float64).astype(np.int64)),
        "close_time": _to_ms(np.array(cols[6], dtype=np.float64).astype(np.int64)),
        "count": np.array(cols[8], dtype=np.float64).astype(np.int64),
    }
    for index, name in ((1, "open"), (2, "high"), (3, "low"), (4, "close"), (5, "volume"),
                        (7, "quote_volume"), (9, "taker_buy_volume"), (10, "taker_buy_quote_volume")):
        out[name] = np.array(cols[index], dtype=np.float64)
    return out


def parse_klines_rest(payload: list[list[Any]]) -> dict[str, np.ndarray]:
    return parse_klines_csv("\n".join(",".join(str(v) for v in row[:11]) for row in payload))


def parse_funding_csv(text: str) -> dict[str, np.ndarray]:
    """Bulk funding: calc_time, funding_interval_hours, last_funding_rate."""
    rows = _csv_rows(text)
    if not rows:
        return {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0), "interval_hours": np.zeros(0, np.int64)}
    cols = list(zip(*rows))
    return {
        "funding_time": _to_ms(np.array(cols[0], dtype=np.float64).astype(np.int64)),
        "interval_hours": np.array(cols[1], dtype=np.float64).astype(np.int64),
        "funding_rate": np.array(cols[2], dtype=np.float64),
    }


def parse_metrics_csv(text: str) -> dict[str, np.ndarray]:
    """Daily metrics: create_time (UTC text), symbol, open interest, long/short ratios."""
    rows = _csv_rows_with_text_header(text)
    if not rows:
        return {"create_time": np.zeros(0, np.int64)}
    times = np.array([int(dt.datetime.strptime(r[0], "%Y-%m-%d %H:%M:%S").replace(tzinfo=dt.timezone.utc).timestamp() * 1000) for r in rows], np.int64)
    def col(i: int) -> np.ndarray:
        return np.array([float(r[i]) if len(r) > i and r[i] != "" else np.nan for r in rows])
    order = np.argsort(times, kind="stable")
    # Raw Binance column names are kept on purpose (their meaning is documented by Binance, not here).
    return {
        "create_time": times[order], "sum_open_interest": col(2)[order], "sum_open_interest_value": col(3)[order],
        "count_toptrader_long_short_ratio": col(4)[order], "sum_toptrader_long_short_ratio": col(5)[order],
        "count_long_short_ratio": col(6)[order], "sum_taker_long_short_vol_ratio": col(7)[order],
    }


def _csv_rows_with_text_header(text: str) -> list[list[str]]:
    rows = [row for row in csv.reader(io.StringIO(text)) if row]
    return rows[1:] if rows and rows[0][0].strip() == "create_time" else rows


def empty_klines() -> dict[str, np.ndarray]:
    out = {name: np.zeros(0) for name in KLINE_COLUMNS}
    for name in ("open_time", "close_time", "count"):
        out[name] = np.zeros(0, np.int64)
    return out


# --------------------------------------------------------------------------- storage

def write_parquet(path: Path, columns: dict[str, np.ndarray]) -> None:
    import pyarrow as pa
    import pyarrow.parquet as pq

    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    pq.write_table(pa.table({k: pa.array(v) for k, v in columns.items()}), tmp, compression="zstd")
    os.replace(tmp, path)


def read_parquet(path: Path) -> dict[str, np.ndarray]:
    import pyarrow.parquet as pq

    table = pq.read_table(path)
    return {name: table.column(name).to_numpy() for name in table.column_names}


def concat(parts: Iterable[dict[str, np.ndarray]]) -> dict[str, np.ndarray]:
    parts = [p for p in parts if p and len(next(iter(p.values()))) > 0]
    if not parts:
        return {}
    return {key: np.concatenate([p[key] for p in parts]) for key in parts[0]}


def load_klines(work: Path, symbol: str, timeframe: str) -> dict[str, np.ndarray]:
    """All stored candles of one symbol/timeframe: sorted, de-duplicated by open_time
    (monthly beats daily beats tail when the same candle exists twice)."""
    base = work / "data" / symbol / timeframe
    files = sorted((base / "monthly").glob("*.parquet")) + sorted((base / "daily").glob("*.parquet"))
    if (base / "tail.parquet").exists():
        files.append(base / "tail.parquet")
    data = concat(read_parquet(f) for f in files)
    if not data:
        return empty_klines()
    order = np.argsort(data["open_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(data["open_time"]), bool)
    keep[1:] = data["open_time"][1:] != data["open_time"][:-1]
    return {k: v[keep] for k, v in data.items()}


class Manifest:
    def __init__(self, path: Path):
        self.path = path
        self.data: dict[str, Any] = json.loads(path.read_text("utf-8")) if path.exists() else {}

    def get(self, key: str) -> Any:
        return self.data.get(key)

    def set(self, key: str, value: Any) -> None:
        self.data[key] = value
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.data, indent=1, sort_keys=True), "utf-8")
        os.replace(tmp, self.path)


# --------------------------------------------------------------------------- calendar

def month_list(months: int, today: dt.date) -> list[str]:
    """Complete months to fetch as monthly files: the `months` months before the current one."""
    first = dt.date(today.year, today.month, 1)
    out = []
    for back in range(months, 0, -1):
        year, month = first.year, first.month - back
        while month <= 0:
            month += 12
            year -= 1
        out.append(f"{year:04d}-{month:02d}")
    return out


def current_month_days(today: dt.date) -> list[str]:
    """Days of the current month before today (published daily files)."""
    return [dt.date(today.year, today.month, d).isoformat() for d in range(1, today.day)]


def days_between(start: dt.date, end_exclusive: dt.date) -> list[str]:
    out, day = [], start
    while day < end_exclusive:
        out.append(day.isoformat())
        day += dt.timedelta(days=1)
    return out
