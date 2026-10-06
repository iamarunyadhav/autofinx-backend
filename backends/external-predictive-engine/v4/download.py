"""Resumable downloads with real progress / ETA (SPEC §47, D-007). Local PC only."""

from __future__ import annotations

import concurrent.futures as cf
import datetime as dt
import io
import json
import sys
import time
import zipfile
from pathlib import Path
from typing import Callable

import numpy as np

from . import binance_data as bd


WORKERS = 4


class Stopped(Exception):
    """The STOP marker file exists: finish cleanly, keep everything already written."""


class Progress:
    def __init__(self, label: str, total: int, out=sys.stdout):
        self.label, self.total, self.done, self.bytes = label, max(total, 1), 0, 0
        self.started = time.monotonic()
        self.last = 0.0
        self.out = out

    def step(self, nbytes: int = 0, note: str = "") -> None:
        self.done += 1
        self.bytes += nbytes
        now = time.monotonic()
        if now - self.last >= 2.0 or self.done == self.total:
            self.last = now
            elapsed = now - self.started
            rate = self.done / elapsed if elapsed > 0 else 0.0
            eta = (self.total - self.done) / rate if rate > 0 else 0.0
            print(f"  {self.label}: {self.done}/{self.total} files, {self.bytes / 1e6:.1f} MB, "
                  f"elapsed {elapsed:.0f}s, ETA {eta:.0f}s {note}", file=self.out, flush=True)


def _check_stop(work: Path) -> None:
    if (work / "STOP").exists():
        raise Stopped(f"STOP file found in {work}")


def _unzip_text(payload: bytes) -> str:
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        name = archive.namelist()[0]
        return archive.read(name).decode("utf-8", "replace")


def download_klines(work: Path, symbol: str, timeframe: str, months: int, today: dt.date,
                    get: Callable[[str], bytes] = bd.http_get, now_ms: int | None = None) -> dict:
    """Monthly zips for complete months, daily zips for this month, REST for the unpublished tail."""
    base = work / "data" / symbol / timeframe
    manifest = bd.Manifest(work / "data" / symbol / "manifest.json")
    month_names = bd.month_list(months, today)
    days = bd.current_month_days(today)
    progress = Progress(f"{symbol} {timeframe}", len(month_names) + len(days))
    summary = {"months_fetched": 0, "months_cached": 0, "months_not_listed": [], "days_fetched": 0, "days_missing": []}

    todo = []
    for month in month_names:
        state = manifest.get(f"{timeframe}:monthly:{month}")
        if state == "not_listed":
            summary["months_not_listed"].append(month)
            progress.step(note="(not listed)")
        elif (base / "monthly" / f"{month}.parquet").exists() and isinstance(state, dict):
            summary["months_cached"] += 1
            progress.step(note="(cached)")
        else:
            todo.append(month)

    def fetch_month(month: str):
        """Worker: download + verify + parse + write the parquet. Manifest is updated by the caller."""
        url = f"{bd.BULK}/monthly/klines/{symbol}/{timeframe}/{symbol}-{timeframe}-{month}.zip"
        try:
            payload, digest = bd.fetch_verified_zip(url, get)
        except bd.NotFound:
            return month, None, url, 0, 0
        data = bd.parse_klines_csv(_unzip_text(payload))
        bd.write_parquet(base / "monthly" / f"{month}.parquet", data)
        return month, digest, url, int(len(data["open_time"])), len(payload)

    # Binance's CDN throttles each connection, so a few parallel downloads are much faster (R-007).
    with cf.ThreadPoolExecutor(max_workers=WORKERS) as pool:
        pending = set()
        queue = list(todo)
        while queue or pending:
            while queue and len(pending) < WORKERS:
                _check_stop(work)
                pending.add(pool.submit(fetch_month, queue.pop(0)))
            finished, pending = cf.wait(pending, return_when=cf.FIRST_COMPLETED)
            for future in finished:
                month, digest, url, rows, nbytes = future.result()
                key = f"{timeframe}:monthly:{month}"
                if digest is None:
                    filled = _fill_month_from_days(work, symbol, timeframe, month, get, manifest)
                    if filled == 0 and not _recent(month, today):
                        manifest.set(key, "not_listed")     # before the listing date: never fetched again
                        summary["months_not_listed"].append(month)
                    progress.step(note=f"(no monthly file; {filled} daily files)")
                    continue
                manifest.set(key, {"sha256": digest, "rows": rows, "source": url})
                for stale in (base / "daily").glob(f"{month}-*.parquet"):   # monthly replaces earlier dailies
                    stale.unlink()
                summary["months_fetched"] += 1
                progress.step(nbytes)

    for day in days:
        _check_stop(work)
        target = base / "daily" / f"{day}.parquet"
        if target.exists():
            progress.step(note="(cached)")
            continue
        url = f"{bd.BULK}/daily/klines/{symbol}/{timeframe}/{symbol}-{timeframe}-{day}.zip"
        try:
            payload, digest = bd.fetch_verified_zip(url, get)
        except bd.NotFound:
            summary["days_missing"].append(day)          # not published yet: REST tail covers it
            progress.step(note="(not published yet)")
            continue
        data = bd.parse_klines_csv(_unzip_text(payload))
        bd.write_parquet(target, data)
        manifest.set(f"{timeframe}:daily:{day}", {"sha256": digest, "rows": int(len(data["open_time"])), "source": url})
        summary["days_fetched"] += 1
        progress.step(len(payload))

    summary["tail_rows"] = download_tail(work, symbol, timeframe, get, now_ms)
    return summary


def _recent(month: str, today: dt.date) -> bool:
    """The last two months may simply not be published yet: never mark them 'not listed'."""
    return month in bd.month_list(2, today)


def _fill_month_from_days(work: Path, symbol: str, timeframe: str, month: str, get, manifest: bd.Manifest) -> int:
    year, mon = (int(x) for x in month.split("-"))
    start = dt.date(year, mon, 1)
    end = dt.date(year + (mon == 12), mon % 12 + 1, 1)
    written = 0
    for day in bd.days_between(start, end):
        url = f"{bd.BULK}/daily/klines/{symbol}/{timeframe}/{symbol}-{timeframe}-{day}.zip"
        try:
            payload, digest = bd.fetch_verified_zip(url, get)
        except bd.NotFound:
            continue
        data = bd.parse_klines_csv(_unzip_text(payload))
        bd.write_parquet(work / "data" / symbol / timeframe / "daily" / f"{day}.parquet", data)
        manifest.set(f"{timeframe}:daily:{day}", {"sha256": digest, "rows": int(len(data["open_time"])), "source": url})
        written += 1
    return written


def download_tail(work: Path, symbol: str, timeframe: str, get=bd.http_get, now_ms: int | None = None) -> int:
    """REST klines after the newest stored candle up to now; CLOSED candles only."""
    interval = bd.INTERVAL_MS[timeframe]
    now_ms = now_ms if now_ms is not None else int(time.time() * 1000)
    base = work / "data" / symbol / timeframe
    stored = bd.concat(bd.read_parquet(f) for f in sorted((base / "monthly").glob("*.parquet")) + sorted((base / "daily").glob("*.parquet")))
    if not stored:
        return 0
    cursor = int(stored["open_time"].max()) + interval
    parts = []
    while cursor + interval <= now_ms:
        url = f"{bd.REST}/fapi/v1/klines?symbol={symbol}&interval={timeframe}&startTime={cursor}&limit=1500"
        rows = json.loads(get(url))
        if not rows:
            break
        data = bd.parse_klines_rest(rows)
        closed = data["close_time"] < now_ms
        data = {k: v[closed] for k, v in data.items()}
        if len(data["open_time"]) == 0:
            break
        parts.append(data)
        cursor = int(data["open_time"].max()) + interval
        time.sleep(0.25)
    tail = bd.concat(parts)
    if tail:
        bd.write_parquet(base / "tail.parquet", tail)
    elif (base / "tail.parquet").exists():
        (base / "tail.parquet").unlink()
    return int(len(tail["open_time"])) if tail else 0


def download_funding(work: Path, symbol: str, months: int, today: dt.date, get=bd.http_get) -> dict:
    base = work / "data" / symbol / "funding"
    manifest = bd.Manifest(work / "data" / symbol / "manifest.json")
    month_names = bd.month_list(months, today)
    progress = Progress(f"{symbol} funding", len(month_names))
    not_listed = []
    for month in month_names:
        _check_stop(work)
        target = base / "monthly" / f"{month}.parquet"
        key = f"funding:monthly:{month}"
        if target.exists() or manifest.get(key) == "not_listed":
            progress.step(note="(cached)")
            continue
        url = f"{bd.BULK}/monthly/fundingRate/{symbol}/{symbol}-fundingRate-{month}.zip"
        try:
            payload, digest = bd.fetch_verified_zip(url, get)
        except bd.NotFound:
            if not _recent(month, today):
                manifest.set(key, "not_listed")
            not_listed.append(month)
            progress.step(note="(not listed)")
            continue
        data = bd.parse_funding_csv(_unzip_text(payload))
        bd.write_parquet(target, data)
        manifest.set(key, {"sha256": digest, "rows": int(len(data["funding_time"])), "source": url})
        progress.step(len(payload))
    # Current month from REST (bulk monthly file is published after the month ends).
    start = int(dt.datetime(today.year, today.month, 1, tzinfo=dt.timezone.utc).timestamp() * 1000)
    rows = json.loads(get(f"{bd.REST}/fapi/v1/fundingRate?symbol={symbol}&startTime={start}&limit=1000"))
    if rows:
        bd.write_parquet(base / "tail.parquet", {
            "funding_time": np.array([int(r["fundingTime"]) for r in rows], np.int64),
            "interval_hours": np.full(len(rows), 0, np.int64),   # REST does not report the interval
            "funding_rate": np.array([float(r["fundingRate"]) for r in rows]),
        })
    return {"months_not_listed": not_listed, "tail_rows": len(rows)}


def load_funding(work: Path, symbol: str) -> dict[str, np.ndarray]:
    base = work / "data" / symbol / "funding"
    files = sorted((base / "monthly").glob("*.parquet")) + ([base / "tail.parquet"] if (base / "tail.parquet").exists() else [])
    data = bd.concat(bd.read_parquet(f) for f in files)
    if not data:
        return {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0), "interval_hours": np.zeros(0, np.int64)}
    order = np.argsort(data["funding_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(order), bool)
    keep[1:] = np.diff(data["funding_time"]) > 60_000       # same event in bulk (+5 ms) and REST
    return {k: v[keep] for k, v in data.items()}


def download_metrics(work: Path, symbol: str, months: int, today: dt.date, get=bd.http_get, workers: int = 6) -> dict:
    """G7 coverage audit input: daily metrics files (open interest, long/short ratios). Download only."""
    first = dt.date.fromisoformat(bd.month_list(months, today)[0] + "-01")
    days = bd.days_between(first, today)
    base = work / "data" / symbol / "metrics" / "daily"
    todo = [d for d in days if not (base / f"{d}.parquet").exists() and not (base / f"{d}.missing").exists()]
    progress = Progress(f"{symbol} metrics", len(days))
    progress.done = len(days) - len(todo)

    def fetch(day: str) -> tuple[str, int]:
        if (work / "STOP").exists():          # queued work is skipped at once on STOP
            return day, -1
        url = f"{bd.BULK}/daily/metrics/{symbol}/{symbol}-metrics-{day}.zip"
        try:
            payload, _ = bd.fetch_verified_zip(url, get)
        except bd.NotFound:
            base.mkdir(parents=True, exist_ok=True)
            (base / f"{day}.missing").write_text("404", "utf-8")
            return day, 0
        bd.write_parquet(base / f"{day}.parquet", bd.parse_metrics_csv(_unzip_text(payload)))
        return day, len(payload)

    with cf.ThreadPoolExecutor(max_workers=workers) as pool:
        futures = []
        for day in todo:
            _check_stop(work)
            futures.append(pool.submit(fetch, day))
        for future in cf.as_completed(futures):
            _, nbytes = future.result()
            if nbytes >= 0:
                progress.step(nbytes)
    _check_stop(work)
    return {"days_requested": len(days)}
