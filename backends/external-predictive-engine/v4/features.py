"""Exact V3 features for V4 (SPEC §7, §72-73): the PHP ShadowFeatureBuilder stays the ONLY
implementation. This module only moves data in and out of an isolated local sqlite database:

  1. import_candles: native Binance 5m candles (Parquet) -> sqlite ml_candles
  2. (runner) php artisan ml:features:build --symbol=X --full   (same code as the server)
  3. export: sqlite ml_feature_rows (schema v3) -> features/<SYMBOL>/v3.parquet
             sqlite ml_labels (V3 endpoint labels)  -> features/<SYMBOL>/v3_labels.parquet (P3 audit)

The sqlite file is created by `php artisan migrate` against <work>/features/features.sqlite with
process-level env overrides; the developer .env / MySQL is never touched.
"""

from __future__ import annotations

import datetime as dt
import json
import sqlite3
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd

FEATURE_SCHEMA = "qt-shadow-features-v3"
PROVIDER = "binance_futures"


def db_path(work: Path) -> Path:
    return work / "features" / "features.sqlite"


def import_candles(work: Path, symbol: str) -> int:
    m5 = bd.load_klines(work, symbol, "5m")
    now = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    rows = list(zip(
        [PROVIDER] * len(m5["open_time"]), [symbol] * len(m5["open_time"]), ["5m"] * len(m5["open_time"]),
        m5["open_time"].tolist(), m5["close_time"].tolist(),
        m5["open"].tolist(), m5["high"].tolist(), m5["low"].tolist(), m5["close"].tolist(), m5["volume"].tolist(),
        m5["quote_volume"].tolist(), m5["count"].tolist(), m5["taker_buy_volume"].tolist(),
        ["binance_bulk_v4"] * len(m5["open_time"]), [now] * len(m5["open_time"]), [now] * len(m5["open_time"]), [now] * len(m5["open_time"]),
    ))
    with sqlite3.connect(db_path(work)) as con:
        before = con.execute("select count(*) from ml_candles where symbol=?", (symbol,)).fetchone()[0]
        con.executemany(
            "insert or ignore into ml_candles (provider, symbol, timeframe, open_time_ms, close_time_ms, open, high, low, close, "
            "volume, quote_volume, trade_count, taker_buy_volume, source, fetched_at, created_at, updated_at) "
            "values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", rows)
        after = con.execute("select count(*) from ml_candles where symbol=?", (symbol,)).fetchone()[0]
    return int(after - before)


def export(work: Path, symbol: str) -> dict:
    started = time.monotonic()
    out_dir = work / "features" / symbol
    with sqlite3.connect(db_path(work)) as con:
        cur = con.execute("select anchor_time_ms, features from ml_feature_rows where symbol=? and timeframe='5m' "
                          "and feature_schema_version=? order by anchor_time_ms", (symbol, FEATURE_SCHEMA))
        anchors, vectors, names = [], [], None
        for anchor, payload in cur:
            values = json.loads(payload)
            if names is None:
                names = list(values.keys())
            anchors.append(int(anchor))
            vectors.append([float(values[n]) if values.get(n) is not None else np.nan for n in names])
        labels = con.execute("select anchor_time_ms, horizon_minutes, future_return, status from ml_labels "
                             "where symbol=? and timeframe='5m' order by horizon_minutes, anchor_time_ms", (symbol,)).fetchall()
    if not anchors:
        raise RuntimeError(f"{symbol}: no {FEATURE_SCHEMA} feature rows in {db_path(work)} (did ml:features:build run?)")
    matrix = np.array(vectors, dtype=np.float64)
    columns = {"anchor_time_ms": np.array(anchors, np.int64)}
    for i, name in enumerate(names):
        columns[name] = matrix[:, i]
    bd.write_parquet(out_dir / "v3.parquet", columns)
    lab = np.array(labels, dtype=object) if labels else np.zeros((0, 4), dtype=object)
    bd.write_parquet(out_dir / "v3_labels.parquet", {
        "anchor_time_ms": lab[:, 0].astype(np.int64) if len(lab) else np.zeros(0, np.int64),
        "horizon_minutes": lab[:, 1].astype(np.int64) if len(lab) else np.zeros(0, np.int64),
        "future_return": np.array([np.nan if v is None else float(v) for v in lab[:, 2]]) if len(lab) else np.zeros(0),
        "mature": np.array([s == "MATURE" for s in lab[:, 3]]) if len(lab) else np.zeros(0, bool),
    })
    meta = {"symbol": symbol, "feature_schema": FEATURE_SCHEMA, "features": names, "rows": len(anchors),
            "first_anchor_ms": anchors[0], "last_anchor_ms": anchors[-1], "label_rows": len(labels),
            "nan_cells": int(np.isnan(matrix).sum()), "source": "PHP ShadowFeatureBuilder via ml:features:build (sqlite)",
            "seconds": round(time.monotonic() - started, 1)}
    (out_dir / "v3.json").write_text(json.dumps(meta, indent=1), "utf-8")
    return meta
