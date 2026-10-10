"""Edge Search v2: bookDepth daily snapshots -> per 5-minute bucket mean bid / ask notional at +-1, 2, 5 %.

Input CSV columns: timestamp ("YYYY-MM-DD HH:MM:SS", UTC), percentage (-5..-1, 1..5), depth (base qty), notional (quote).
Percentage < 0 = bid side, > 0 = ask side. A snapshot is the group of rows that share one timestamp.
"""

from __future__ import annotations

import io

import numpy as np

M5 = 300_000
LEVELS = (1, 2, 5)


def parse_depth_csv(raw: bytes) -> dict:
    import pyarrow as pa
    import pyarrow.csv as pacsv

    table = pacsv.read_csv(io.BytesIO(raw), convert_options=pacsv.ConvertOptions(
        column_types={"timestamp": pa.string(), "percentage": pa.int64(), "depth": pa.float64(), "notional": pa.float64()}))
    ts = np.array(table.column("timestamp").to_pylist(), dtype="datetime64[s]").astype("int64") * 1000
    return {"ts": ts, "pct": table.column("percentage").to_numpy(), "notional": table.column("notional").to_numpy()}


def aggregate_day(rows: dict, day_start_ms: int) -> dict:
    """288 buckets of the UTC day starting at day_start_ms. Snapshots outside the day are counted and ignored."""
    nb = 288
    ts, pct, notional = rows["ts"], rows["pct"], rows["notional"]
    inr = (ts >= day_start_ms) & (ts < day_start_ms + nb * M5)
    out_of_range = int((~inr).sum())
    ts, pct, notional = ts[inr], pct[inr], notional[inr]
    b = ((ts - day_start_ms) // M5).astype(np.int64)
    snaps = len(np.unique(ts))
    cols = {"bucket_open_ms": day_start_ms + np.arange(nb, dtype=np.int64) * M5}
    # one snapshot = one timestamp; count snapshots per bucket
    u_ts, first = np.unique(ts, return_index=True)
    cnt = np.bincount(b[first], minlength=nb).astype(float)
    cols["snap_n"] = cnt
    for lv in LEVELS:
        for side, sign in (("bid", -lv), ("ask", lv)):
            m = pct == sign
            s = np.bincount(b[m], weights=notional[m], minlength=nb)
            n = np.bincount(b[m], minlength=nb).astype(float)
            cols[f"{side}{lv}"] = np.where(n > 0, s / np.maximum(n, 1), np.nan)
    info = {"rows": int(len(ts)), "snapshots": int(snaps), "out_of_range": out_of_range}
    return {"cols": cols, "info": info}


def imbalance(bid: np.ndarray, ask: np.ndarray) -> np.ndarray:
    tot = bid + ask
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.where(tot > 0, (bid - ask) / tot, np.nan)
