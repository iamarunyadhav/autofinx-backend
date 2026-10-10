"""Premium-index (basis) klines from the Binance public archive, verified by checksum (V5-lite input, D-041).

  data.binance.vision/data/futures/um/monthly/premiumIndexKlines/<SYM>/5m/<SYM>-5m-YYYY-MM.zip
  .../daily/premiumIndexKlines/<SYM>/5m/<SYM>-5m-YYYY-MM-DD.zip   (current month)
Stored like klines under data/<SYM>/premium5m/{monthly,daily}/. A 5m premium bar is usable at T0 only if
its close time <= T0 (the bar ending exactly at T0 is complete).
"""

from __future__ import annotations

import concurrent.futures as cf
import datetime as dt
from pathlib import Path

import numpy as np

from . import binance_data as bd
from .download import _unzip_text


def download(work: Path, symbol: str, months: int, today: dt.date, workers: int = 4) -> dict:
    base = work / "data" / symbol / "premium5m"
    jobs = [("monthly", m, f"{bd.BULK}/monthly/premiumIndexKlines/{symbol}/5m/{symbol}-5m-{m}.zip")
            for m in bd.month_list(months, today)]
    jobs += [("daily", d, f"{bd.BULK}/daily/premiumIndexKlines/{symbol}/5m/{symbol}-5m-{d}.zip")
             for d in bd.current_month_days(today)]
    todo = [j for j in jobs if not (base / j[0] / f"{j[1]}.parquet").exists() and not (base / j[0] / f"{j[1]}.missing").exists()]

    def fetch(job):
        kind, name, url = job
        (base / kind).mkdir(parents=True, exist_ok=True)
        try:
            payload, _ = bd.fetch_verified_zip(url)
        except bd.NotFound:
            (base / kind / f"{name}.missing").write_text("404", "utf-8")
            return kind, name, 0
        data = bd.parse_klines_csv(_unzip_text(payload))
        bd.write_parquet(base / kind / f"{name}.parquet", data)
        return kind, name, len(data["open_time"])

    got = {"files": 0, "rows": 0, "missing": 0}
    with cf.ThreadPoolExecutor(max_workers=workers) as pool:
        for kind, name, rows in pool.map(fetch, todo):
            got["files"] += 1
            got["rows"] += rows
            got["missing"] += int(rows == 0)
    return got


def load(work: Path, symbol: str) -> dict[str, np.ndarray]:
    base = work / "data" / symbol / "premium5m"
    files = sorted((base / "monthly").glob("*.parquet")) + sorted((base / "daily").glob("*.parquet"))
    if not files:
        return bd.empty_klines()
    data = bd.concat(bd.read_parquet(f) for f in files)
    order = np.argsort(data["open_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.r_[True, np.diff(data["open_time"]) > 0]
    return {k: v[keep] for k, v in data.items()}


def features(anchors: np.ndarray, prem: dict) -> dict[str, np.ndarray]:
    """Premium (basis, in %) of the last COMPLETE 5m bar at T0, its 1h mean and 24h z-score."""
    n = len(anchors)
    out = {"ctx_premium_last": np.full(n, np.nan), "ctx_premium_1h": np.full(n, np.nan), "ctx_premium_z24h": np.full(n, np.nan)}
    if len(prem["open_time"]) == 0:
        return out
    end = prem["open_time"] + 300_000
    close = prem["close"] * 100.0
    idx = np.searchsorted(end, anchors, side="right") - 1          # last bar with close time <= T0
    ok = idx >= 0
    i = np.maximum(idx, 0)
    fresh = ok & ((anchors - end[i]) <= 15 * 60_000)              # no stale carry-forward over gaps
    cs, cs2 = np.r_[0.0, np.cumsum(close)], np.r_[0.0, np.cumsum(close ** 2)]

    def window_mean(k):
        lo = np.maximum(i - k + 1, 0)
        return (cs[i + 1] - cs[lo]) / (i - lo + 1), (cs2[i + 1] - cs2[lo]) / (i - lo + 1), i - lo + 1
    m12, _, _ = window_mean(12)
    m288, s288, c288 = window_mean(288)
    with np.errstate(invalid="ignore", divide="ignore"):
        z = (close[i] - m288) / np.sqrt(s288 - m288 ** 2)
    out["ctx_premium_last"] = np.where(fresh, close[i], np.nan)
    out["ctx_premium_1h"] = np.where(fresh, m12, np.nan)
    out["ctx_premium_z24h"] = np.where(fresh & (c288 >= 200), z, np.nan)
    return out
