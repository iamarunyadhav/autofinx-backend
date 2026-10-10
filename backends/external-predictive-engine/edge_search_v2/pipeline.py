"""Edge Search v2: resumable download + stream-aggregate pipeline (data.binance.vision, checksum-verified).

One process, one download at a time, low priority, memory / disk guards, STOP file, per-unit markers (a finished month /
day is never repeated).  Raw zips are deleted right after aggregation.  Only public archive files are fetched; the
holdout seal (2026-01-03) is enforced here: a unit whose last day is after the seal, or after the run's max_day, is refused
BEFORE any network request.
"""

from __future__ import annotations

import calendar
import datetime as dt
import hashlib
import json
import os
import shutil
import sys
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

import numpy as np

from . import depth as depthmod
from .flow import FlowAggregator

BASE = "https://data.binance.vision/data/futures/um"
UA = "autofinx-ml-v4/edge-search-v2 (research downloader)"
SEAL_END = dt.date(2026, 1, 3)           # global seal of Edge Search v1 (holdout starts 2026-01-04)
PILOT_END = dt.date(2025, 12, 31)
RAW_CAP_GB = 15.0
MIN_FREE_GB = 20.0
MIN_COMMIT_GB = 2.0
AGG_COLS = ["agg_trade_id", "price", "quantity", "first_trade_id", "last_trade_id", "transact_time", "is_buyer_maker"]


class HoldoutViolation(Exception):
    """A requested unit touches data after the global seal / the run's max day."""


class GuardStop(Exception):
    """STOP file present or a resource guard cannot be satisfied."""


def log(work: Path, msg: str) -> None:
    line = f"{dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')} {msg}"
    print(line, flush=True)
    try:
        p = work / "logs" / "edge_search_v2.log"
        p.parent.mkdir(parents=True, exist_ok=True)
        with open(p, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


# ------------------------------------------------------------------------------------------ seal
def month_bounds(month: str) -> tuple[dt.date, dt.date]:
    y, m = int(month[:4]), int(month[5:7])
    return dt.date(y, m, 1), dt.date(y, m, calendar.monthrange(y, m)[1])


def assert_allowed(last_day: dt.date, max_day: dt.date = PILOT_END) -> None:
    if last_day > SEAL_END:
        raise HoldoutViolation(f"{last_day} is after the global seal {SEAL_END}")
    if last_day > max_day:
        raise HoldoutViolation(f"{last_day} is after this run's max day {max_day}")


def months_between(start: str, end: str) -> list[str]:
    y, m = int(start[:4]), int(start[5:7])
    out = []
    while f"{y:04d}-{m:02d}" <= end:
        out.append(f"{y:04d}-{m:02d}")
        y, m = (y + 1, 1) if m == 12 else (y, m + 1)
    return out


def days_between(start: dt.date, end: dt.date) -> list[dt.date]:
    return [start + dt.timedelta(days=i) for i in range((end - start).days + 1)]


# ------------------------------------------------------------------------------------------ guards
def low_priority() -> None:
    try:
        import ctypes
        ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(), 0x4000)   # BELOW_NORMAL
    except Exception:
        pass


def avail_commit_gb() -> float:
    try:
        import ctypes

        class MS(ctypes.Structure):
            _fields_ = [("dwLength", ctypes.c_ulong), ("dwMemoryLoad", ctypes.c_ulong), ("ullTotalPhys", ctypes.c_ulonglong),
                        ("ullAvailPhys", ctypes.c_ulonglong), ("ullTotalPageFile", ctypes.c_ulonglong),
                        ("ullAvailPageFile", ctypes.c_ulonglong), ("ullTotalVirtual", ctypes.c_ulonglong),
                        ("ullAvailVirtual", ctypes.c_ulonglong), ("sullAvailExtendedVirtual", ctypes.c_ulonglong)]
        ms = MS()
        ms.dwLength = ctypes.sizeof(ms)
        ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(ms))
        return ms.ullAvailPageFile / 2 ** 30
    except Exception:
        return 99.0


def dir_size(path: Path) -> int:
    return sum(f.stat().st_size for f in path.rglob("*") if f.is_file()) if path.exists() else 0


def check_guards(work: Path, raw: Path, next_bytes: int = 0, wait_s: float = 3600.0) -> None:
    if (work / "edge_search_v2" / "STOP").exists():
        raise GuardStop("STOP file present")
    t = time.time()
    while avail_commit_gb() < MIN_COMMIT_GB:
        if time.time() - t > wait_s:
            raise GuardStop(f"available commit memory stayed below {MIN_COMMIT_GB} GB")
        time.sleep(30)
    if (dir_size(raw) + next_bytes) / 2 ** 30 > RAW_CAP_GB:
        raise GuardStop(f"raw dir would exceed {RAW_CAP_GB} GB")
    if shutil.disk_usage(work).free / 2 ** 30 < MIN_FREE_GB + next_bytes / 2 ** 30:
        raise GuardStop(f"free disk below {MIN_FREE_GB} GB")


# ------------------------------------------------------------------------------------------ network
def _open(url: str, headers: dict | None = None, timeout: float = 60.0):
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    return urllib.request.urlopen(req, timeout=timeout)


def head_length(url: str) -> int:
    req = urllib.request.Request(url, method="HEAD", headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        return int(r.headers["Content-Length"])


def fetch_checksum(url: str) -> str:
    with _open(url + ".CHECKSUM") as r:
        return r.read().decode().split()[0].strip().lower()


def download_file(url: str, dest: Path, expected_len: int, work: Path, retries: int = 8) -> str:
    """Stream to dest.part (Range resume on retry), return sha256 of the complete file."""
    part = dest.with_suffix(dest.suffix + ".part")
    part.parent.mkdir(parents=True, exist_ok=True)
    delay, t0, last_log = 3.0, time.time(), 0.0
    for attempt in range(1, retries + 1):
        try:
            have = part.stat().st_size if part.exists() else 0
            h = hashlib.sha256()
            if have:
                with open(part, "rb") as f:
                    while chunk := f.read(8 << 20):
                        h.update(chunk)
            if have >= expected_len:
                have = 0
                part.unlink()
                h = hashlib.sha256()
            with _open(url, {"Range": f"bytes={have}-"} if have else None) as r, open(part, "ab" if have else "wb") as out:
                if have and getattr(r, "status", 200) != 206:
                    out.close()
                    part.unlink()
                    raise OSError("server ignored Range")
                while chunk := r.read(4 << 20):
                    out.write(chunk)
                    h.update(chunk)
                    now = time.time()
                    if now - last_log > 20:
                        last_log = now
                        done = part.stat().st_size
                        log(work, f"  download {dest.name}: {done / 2**20:.0f}/{expected_len / 2**20:.0f} MB "
                                  f"({done / max(now - t0, 1) / 2**20:.1f} MB/s)")
            if part.stat().st_size != expected_len:
                raise OSError(f"size {part.stat().st_size} != {expected_len}")
            os.replace(part, dest)
            return h.hexdigest()
        except (urllib.error.URLError, OSError, TimeoutError) as e:
            if isinstance(e, urllib.error.HTTPError) and e.code == 404:
                raise
            if attempt == retries:
                raise
            log(work, f"  download retry {attempt} after {type(e).__name__}: {e}")
            time.sleep(delay)
            delay = min(delay * 2, 120)
    raise RuntimeError("unreachable")


# ------------------------------------------------------------------------------------------ io helpers
def _atomic_json(path: Path, obj) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(obj, indent=1), "utf-8")
    os.replace(tmp, path)


def write_npz(path: Path, arrays: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.stem + ".tmp.npz")
    np.savez_compressed(tmp, **arrays)
    os.replace(tmp, path)


def ms_of(d: dt.date) -> int:
    return int(dt.datetime(d.year, d.month, d.day, tzinfo=dt.timezone.utc).timestamp() * 1000)


# ------------------------------------------------------------------------------------------ aggTrades month
def aggregate_zip(zip_path: Path, t0_ms: int, t1_ms: int, work: Path, label: str, batch_mb: int = 24) -> dict:
    import pyarrow as pa
    import pyarrow.csv as pacsv

    agg = FlowAggregator(t0_ms, t1_ms)
    with zipfile.ZipFile(zip_path) as z:
        names = [n for n in z.namelist() if n.lower().endswith(".csv")]
        if len(names) != 1:
            raise ValueError(f"expected exactly one csv in {zip_path.name}, got {names}")
        with z.open(names[0]) as probe:
            has_header = probe.readline().decode("ascii", "ignore").lstrip().lower().startswith("agg_trade_id")
        size = z.getinfo(names[0]).file_size
        types = {"agg_trade_id": pa.int64(), "price": pa.float64(), "quantity": pa.float64(), "first_trade_id": pa.int64(),
                 "last_trade_id": pa.int64(), "transact_time": pa.int64(), "is_buyer_maker": pa.bool_()}
        ro = pacsv.ReadOptions(block_size=batch_mb << 20, **({} if has_header else {"column_names": AGG_COLS}))
        co = pacsv.ConvertOptions(column_types=types, include_columns=["agg_trade_id", "price", "quantity", "transact_time",
                                                                        "is_buyer_maker"], true_values=["true", "True", "TRUE"],
                                  false_values=["false", "False", "FALSE"])
        t_start, last_log, read = time.time(), 0.0, 0
        with z.open(names[0]) as f:
            reader = pacsv.open_csv(f, read_options=ro, convert_options=co)
            for batch in reader:
                n = batch.num_rows
                if n == 0:
                    continue
                agg.add(batch.column("agg_trade_id").to_numpy(zero_copy_only=False),
                        batch.column("transact_time").to_numpy(zero_copy_only=False),
                        batch.column("price").to_numpy(zero_copy_only=False),
                        batch.column("quantity").to_numpy(zero_copy_only=False),
                        batch.column("is_buyer_maker").to_numpy(zero_copy_only=False))
                read += n
                now = time.time()
                if now - last_log > 20:
                    last_log = now
                    log(work, f"  aggregate {label}: {read / 1e6:.1f} M rows, {read / max(now - t_start, 1) / 1e3:.0f} k rows/s")
    res = agg.finish()
    res["info"]["csv_bytes"] = size
    res["info"]["aggregate_s"] = round(time.time() - t_start, 1)
    return res


def process_flow_month(work: Path, symbol: str, month: str, max_day: dt.date = PILOT_END, get_bytes=None) -> dict:
    first, last = month_bounds(month)
    assert_allowed(last, max_day)
    edge = work / "edge_search_v2"
    marker = edge / "markers" / f"flow-{symbol}-{month}.json"
    if marker.exists():
        return json.loads(marker.read_text("utf-8")) | {"skipped": True}
    raw = edge / "raw"
    url = f"{BASE}/monthly/aggTrades/{symbol}/{symbol}-aggTrades-{month}.zip"
    dest = raw / f"{symbol}-aggTrades-{month}.zip"
    length = head_length(url)
    check_guards(work, raw, length)
    expected = fetch_checksum(url)
    log(work, f"flow {symbol} {month}: {length / 2**20:.0f} MB zip")
    t0 = time.time()
    sha = download_file(url, dest, length, work)
    dl_s = time.time() - t0
    if sha != expected:
        dest.unlink(missing_ok=True)
        raise ValueError(f"checksum mismatch for {dest.name}: {sha} != {expected}")
    nxt = last + dt.timedelta(days=1)
    res = aggregate_zip(dest, ms_of(first), ms_of(nxt), work, f"{symbol} {month}")
    out = edge / "features" / symbol / "flow"
    from v4 import binance_data as bd
    bd.write_parquet(out / f"{month}.parquet", res["scalars"])
    write_npz(out / f"{month}.npz", res["hist"])
    dest.unlink()
    info = res["info"] | {"sha256": sha, "zip_bytes": length, "download_s": round(dl_s, 1), "symbol": symbol, "month": month,
                          "done_utc": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}
    _atomic_json(marker, info)
    log(work, f"flow {symbol} {month} done: {info['rows']:,} rows, id gaps {info['id_missing_total']:.0f}, "
              f"dl {dl_s:.0f}s, agg {info['aggregate_s']}s")
    return info


# ------------------------------------------------------------------------------------------ bookDepth day
def process_depth_day(work: Path, symbol: str, day: dt.date, max_day: dt.date = PILOT_END) -> dict:
    assert_allowed(day, max_day)
    edge = work / "edge_search_v2"
    marker = edge / "markers" / f"depth-{symbol}-{day.isoformat()}.json"
    if marker.exists():
        return json.loads(marker.read_text("utf-8")) | {"skipped": True}
    check_guards(work, edge / "raw", 20 << 20)
    url = f"{BASE}/daily/bookDepth/{symbol}/{symbol}-bookDepth-{day.isoformat()}.zip"
    try:
        expected = fetch_checksum(url)
        with _open(url, timeout=120) as r:
            payload = r.read()
    except urllib.error.HTTPError as e:
        if e.code == 404:
            info = {"symbol": symbol, "day": day.isoformat(), "missing": True}
            _atomic_json(marker, info)
            return info
        raise
    sha = hashlib.sha256(payload).hexdigest()
    if sha != expected:
        raise ValueError(f"checksum mismatch for bookDepth {symbol} {day}")
    import io
    with zipfile.ZipFile(io.BytesIO(payload)) as z:
        raw = z.read(z.namelist()[0])
    res = depthmod.aggregate_day(depthmod.parse_depth_csv(raw), ms_of(day))
    from v4 import binance_data as bd
    bd.write_parquet(edge / "features" / symbol / "depth" / f"{day.isoformat()}.parquet", res["cols"])
    info = res["info"] | {"symbol": symbol, "day": day.isoformat(), "sha256": sha, "zip_bytes": len(payload)}
    _atomic_json(marker, info)
    return info


# ------------------------------------------------------------------------------------------ orchestration
def run_pipeline(work: Path, symbols, flow_start: str, flow_end: str, depth_start: dt.date, depth_end: dt.date,
                 max_day: dt.date = PILOT_END) -> dict:
    """Months of aggTrades then days of bookDepth, per symbol. Returns summary; raises GuardStop on STOP."""
    low_priority()
    t0 = time.time()
    summary = {"flow": [], "depth_days": 0, "depth_skipped": 0}
    for sym in symbols:
        for month in months_between(flow_start, flow_end):
            summary["flow"].append(process_flow_month(work, sym, month, max_day))
    for sym in symbols:
        for d in days_between(depth_start, depth_end):
            r = process_depth_day(work, sym, d, max_day)
            summary["depth_days"] += 1
            summary["depth_skipped"] += int(bool(r.get("skipped")))
        log(work, f"depth {sym}: {summary['depth_days']} days handled")
    summary["elapsed_s"] = round(time.time() - t0, 1)
    return summary
