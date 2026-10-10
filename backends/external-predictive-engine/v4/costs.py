"""Single source of truth for execution costs in the V4 research (owner request 2026-10-07: "fee structure
sariyaa nu paathu fix pannu").

Rates are fractions per leg. Defaults = Binance USD-M VIP0 public rates (maker 0.02 %, taker 0.05 %) plus a
slippage buffer of 0.01 % per leg, i.e. C0 taker/taker round trip = 2 x (0.05 + 0.01) = 0.12 % — the value every
experiment so far used, so nothing changes until real numbers are supplied.

Real account rates: put them in <work>/fees.json, e.g.
    {"maker": 0.00018, "taker": 0.00045, "slippage_per_leg": 0.0001, "source": "commissionRate 2026-10-08"}
(from the platform's AccountFeeRateService / GET /fapi/v1/commissionRate). Experiments read that file only
when AUTOFINX_V4_FEES=account, so earlier results stay reproducible and every report records which applied.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

DEFAULT = {"maker": 0.0002, "taker": 0.0005, "slippage_per_leg": 0.0001, "source": "Binance USD-M VIP0 public rates (assumed)"}


def rates(work: Path | None = None) -> dict:
    work = work if work is not None else os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4")
    if os.environ.get("AUTOFINX_V4_FEES", "default") == "account" and work is not None:
        p = Path(work) / "fees.json"
        if p.exists():
            r = {**DEFAULT, **json.loads(p.read_text("utf-8"))}
            r["source"] = r.get("source", "fees.json")
            return r
    return dict(DEFAULT)


def round_trip_pct(model: str = "taker", work: Path | None = None) -> float:
    """C0 'taker': taker entry + taker exit; C1 'maker_entry': maker entry + taker exit. Slippage on both legs
    for C0; for C1 only the exit leg (a filled post-only limit has no entry slippage, its risk is the fill)."""
    r = rates(work)
    if model == "taker":
        return round((2 * r["taker"] + 2 * r["slippage_per_leg"]) * 100, 6)
    if model == "maker_entry":
        return round((r["maker"] + r["taker"] + r["slippage_per_leg"]) * 100, 6)
    raise ValueError(model)
