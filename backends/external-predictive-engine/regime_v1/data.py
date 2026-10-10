"""Restricted data loader for Regime + Tradeability v1 (docs/ml-engine-v1/REGIME_TRADEABILITY_V1.md section 1).

Reads ONLY monthly files 2022-04 .. 2025-12 (nothing at or after CUT = 2026-01-01 is ever opened) and asserts it. The global seal is
2026-01-03; the holdout is never touched and no HoldoutGate is used.
"""

from __future__ import annotations

import datetime as dt
import re
from pathlib import Path

import numpy as np

from edge_search.core import Coin
from v4 import binance_data as bd

DAY = 86_400_000
M5 = 300_000


def _ms(y: int, m: int, d: int) -> int:
    return int(dt.datetime(y, m, d, tzinfo=dt.timezone.utc).timestamp() * 1000)


T0 = _ms(2022, 4, 1)               # first usable bar (warm-up start)
WINDOW_LO = _ms(2023, 1, 1)        # development window start
CUT = _ms(2026, 1, 1)              # development window end (exclusive); older than the 2026-01-03 global seal
FIRST_MONTH, LAST_MONTH = "2022-04", "2025-12"
SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")
PRIMARY = ("BTCUSDT", "ETHUSDT")
TFS = ("5m", "15m", "1h", "4h", "1d")
_MONTH = re.compile(r"^(\d{4})-(\d{2})\.parquet$")


def check_cut(cut: int) -> int:
    if cut > CUT:
        raise ValueError("refusing to read at or after 2026-01-01: the development window ends there (global seal 2026-01-03)")
    return cut


def month_files(base: Path, first: str = FIRST_MONTH, last: str = LAST_MONTH) -> list[Path]:
    """Monthly parquet files whose name is within [first, last]; never daily / tail files, never anything newer."""
    out = []
    mdir = Path(base) / "monthly"
    if not mdir.exists():
        return out
    for p in sorted(mdir.iterdir()):
        m = _MONTH.match(p.name)
        if m and first <= f"{m.group(1)}-{m.group(2)}" <= last:
            out.append(p)
    return out


def load_k5(work: Path, symbol: str, cut: int = CUT) -> dict:
    check_cut(cut)
    data = bd.concat(bd.read_parquet(f) for f in month_files(Path(work) / "data" / symbol / "5m"))
    if not data:
        raise FileNotFoundError(f"no 5m monthly files for {symbol}")
    order = np.argsort(data["open_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(order), bool)
    keep[1:] = data["open_time"][1:] != data["open_time"][:-1]
    data = {k: v[keep] for k, v in data.items()}
    keep = (data["open_time"] >= T0) & (data["open_time"] + M5 <= cut)
    data = {k: v[keep] for k, v in data.items()}
    assert int(data["open_time"].max()) + M5 <= cut, "bar after the development window"
    return data


def load_funding(work: Path, symbol: str, cut: int = CUT) -> dict:
    check_cut(cut)
    data = bd.concat(bd.read_parquet(f) for f in month_files(Path(work) / "data" / symbol / "funding"))
    if not data:
        return {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    order = np.argsort(data["funding_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.ones(len(order), bool)
    keep[1:] = np.diff(data["funding_time"]) > 60_000
    data = {k: v[keep] for k, v in data.items()}
    keep = data["funding_time"] <= cut
    return {k: v[keep] for k, v in data.items()}


def build_coin(work: Path, symbol: str, tfs=TFS, cut: int = CUT) -> Coin:
    check_cut(cut)
    n5 = int((cut - T0) // M5 // 288) * 288
    return Coin(symbol, T0, n5, load_k5(work, symbol, cut), load_funding(work, symbol, cut), Path(work), tfs)


class World:
    """Minimal shim with the attributes the v1 rule functions use (coins, work)."""

    def __init__(self, coins: dict, work=None):
        self.coins, self.work = coins, work
