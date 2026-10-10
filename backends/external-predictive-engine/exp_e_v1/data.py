"""Restricted data loader for Experiment E v1 (docs/ml-engine-v1/EXPERIMENT_E_V1.md section 1).

Opens ONLY monthly kline / funding files named YYYY-MM <= 2025-12 (never daily / tail files) and refuses any cut after CUT = 2026-01-01
(the global seal is 2026-01-03; the holdout is never touched). The V3 feature and target parquet files are single files that physically contain rows
after the seal: they are read with a predicate anchor_time_ms < CUT and the result is asserted (rows at or after the cut are never exposed).
"""

from __future__ import annotations

import datetime as dt
import re
from pathlib import Path

import numpy as np

from v4 import binance_data as bd

MIN = 60_000
M5 = 300_000
DAY = 86_400_000


def _ms(y, m, d=1):
    return int(dt.datetime(y, m, d, tzinfo=dt.timezone.utc).timestamp() * 1000)


DATA_T0 = _ms(2021, 10, 1)             # first usable 5m bar (data starts 2021-10)
TEST_LO = _ms(2023, 1, 1)              # first test year
CUT = _ms(2026, 1, 1)                  # exclusive end of everything this experiment may read
FIRST_MONTH, LAST_MONTH = "2021-10", "2025-12"
LIVE_SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT")
VERDICT_SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT")
_MONTH = re.compile(r"^(\d{4})-(\d{2})\.parquet$")


def check_cut(cut: int) -> int:
    if cut > CUT:
        raise ValueError("refusing to read at or after 2026-01-01: the evaluation window ends there (global seal 2026-01-03, holdout sealed)")
    return int(cut)


def month_files(base: Path, first: str = FIRST_MONTH, last: str = LAST_MONTH) -> list[Path]:
    """Monthly parquet files named within [first, last]; `last` is clamped to 2025-12. Daily / tail files and every 2026 file are never returned."""
    last = min(last, LAST_MONTH)
    out = []
    mdir = Path(base) / "monthly"
    if not mdir.exists():
        return out
    for p in sorted(mdir.iterdir()):
        m = _MONTH.match(p.name)
        if m and first <= f"{m.group(1)}-{m.group(2)}" <= last:
            out.append(p)
    return out


def refuse_path(p: Path) -> None:
    """Hard guard used by every file read in this package: a file whose name carries a 2026+ month or date is refused."""
    name = Path(p).name
    m = re.search(r"(20\d\d)-(\d\d)", name)
    if m and (int(m.group(1)), int(m.group(2))) >= (2026, 1):
        raise ValueError(f"refusing to open {name}: at or after the 2026-01-01 cut")


def _dedupe(data: dict, key: str) -> dict:
    order = np.argsort(data[key], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(order), bool)
    keep[1:] = data[key][1:] != data[key][:-1]
    return {k: v[keep] for k, v in data.items()}


def _read_cols(path: Path, columns) -> dict:
    import pyarrow.parquet as pq
    t = pq.read_table(path, columns=list(columns))
    return {n: t.column(n).to_numpy() for n in t.column_names}


def load_klines(work: Path, symbol: str, tf: str, cut: int = CUT, columns=None) -> dict:
    """Monthly kline files only. `columns` limits what is read (memory); open_time is always included."""
    check_cut(cut)
    files = month_files(Path(work) / "data" / symbol / tf)
    for f in files:
        refuse_path(f)
    if columns is None:
        data = bd.concat(bd.read_parquet(f) for f in files)
    else:
        cols = sorted(set(columns) | {"open_time"})
        data = bd.concat(_read_cols(f, cols) for f in files)
    if not data:
        raise FileNotFoundError(f"no {tf} monthly files for {symbol}")
    data = _dedupe(data, "open_time")
    step = bd.INTERVAL_MS[tf]
    keep = (data["open_time"] >= DATA_T0) & (data["open_time"] + step <= cut)
    data = {k: v[keep] for k, v in data.items()}
    assert int(data["open_time"].max()) + step <= cut, "bar after the evaluation window"
    return data


def load_funding(work: Path, symbol: str, cut: int = CUT) -> dict:
    check_cut(cut)
    files = month_files(Path(work) / "data" / symbol / "funding")
    for f in files:
        refuse_path(f)
    data = bd.concat(bd.read_parquet(f) for f in files)
    if not data:
        return {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    order = np.argsort(data["funding_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(order), bool)
    keep[1:] = np.diff(data["funding_time"]) > 60_000
    data = {k: v[keep] for k, v in data.items()}
    keep = data["funding_time"] <= cut
    return {k: v[keep] for k, v in data.items()}


def read_anchor_table(path: Path, columns: list | None = None, cut: int = CUT) -> dict:
    """Parquet with an anchor_time_ms column, rows with anchor < cut only (predicate), asserted."""
    import pyarrow.parquet as pq
    check_cut(cut)
    refuse_path(path)
    cols = None if columns is None else sorted(set(columns) | {"anchor_time_ms"})
    table = pq.read_table(path, columns=cols, filters=[("anchor_time_ms", "<", int(cut))])
    out = {n: table.column(n).to_numpy(zero_copy_only=False) for n in table.column_names}
    if len(out["anchor_time_ms"]):
        assert int(out["anchor_time_ms"].max()) < cut, "feature / target row at or after the cut"
    return out


def build_coin(work: Path, symbol: str, tfs=("5m", "15m")):
    from edge_search.core import Coin
    n5 = int((CUT - DATA_T0) // M5 // 288) * 288
    return Coin(symbol, DATA_T0, n5, load_klines(work, symbol, "5m"), load_funding(work, symbol), Path(work), tfs)
