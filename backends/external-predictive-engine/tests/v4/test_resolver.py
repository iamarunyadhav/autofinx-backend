"""Sampled aggTrade resolver (D-019/D-020): exact trade order replaces the conservative 1m guess."""

import hashlib
import io
import random
import sys
import zipfile
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import binance_data as bd  # noqa: E402
from v4 import policy as pol  # noqa: E402
from v4 import resolver as rs  # noqa: E402

MIN = 60_000
T0 = 1_633_046_400_000


def window(prices):
    p = np.array(prices, dtype=float)
    return {"open": p[:, 0], "high": p[:, 1], "low": p[:, 2], "close": p[:, 3]}


def test_without_ticks_resimulate_equals_the_conservative_simulator():
    rng = random.Random(5)
    price, prices = 100.0, []
    for _ in range(400):
        o = price * (1 + rng.gauss(0, 0.0004)); c = o * (1 + rng.gauss(0, 0.002))
        prices.append((o, max(o, c) * (1 + abs(rng.gauss(0, 0.0012))), min(o, c) * (1 - abs(rng.gauss(0, 0.0012))), c)); price = c
    p = np.array(prices)
    m1 = {"open_time": T0 + MIN * np.arange(400), "open": p[:, 0], "high": p[:, 1], "low": p[:, 2], "close": p[:, 3]}
    anchors = T0 + 5 * MIN * np.arange(70)
    for side in ("long", "short"):
        sim = pol.simulate(m1, anchors, 15, side, 0.3, 0.2, 0.1, 0.12)
        for i in range(70):
            w = window(prices[i * 5:i * 5 + 15])
            why, move, bar = rs.resimulate(w, {}, side, 0.3, 0.2, 0.1, 0.12)
            assert why == pol.REASONS[sim["reason"][i]] and move == pytest.approx(sim["gross_pct"][i], abs=1e-9)


def test_ticks_decide_the_order_inside_an_ambiguous_bar():
    bars = [(100, 100.6, 99.4, 100.2), (100.2, 100.3, 100.1, 100.2)]          # touches -0.5 stop and +0.4 activation
    conservative, _, _ = rs.resimulate(window(bars), {}, "long", 0.5, 0.4, 0.2, 0.12)
    assert conservative == "AMBIGUOUS_INTRABAR"
    stop_first, move, _ = rs.resimulate(window(bars), {0: np.array([100, 99.4, 100.6, 100.2])}, "long", 0.5, 0.4, 0.2, 0.12)
    assert stop_first == "HARD_STOP" and move == pytest.approx(-0.6)
    profit_first, move, _ = rs.resimulate(window(bars), {0: np.array([100, 100.6, 100.35, 100.2])}, "long", 0.5, 0.4, 0.2, 0.12)
    assert profit_first == "TRAILING_PROFIT" and move == pytest.approx(0.35)    # first trade at/below the 0.4 stop


def test_day_minutes_trades_keeps_only_requested_minutes_in_trade_order():
    rows = ["agg_trade_id,price,quantity,first_trade_id,last_trade_id,transact_time,is_buyer_maker",
            f"3,101.0,1,3,3,{T0 + 30_000},true", f"1,100.0,1,1,1,{T0 + 10_000},false",
            f"2,100.5,1,2,2,{T0 + 20_000},true", f"4,99.0,1,4,4,{T0 + MIN + 5},false"]
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("x.csv", "\n".join(rows))
    payload = buf.getvalue()
    url = f"{bd.BULK}/daily/aggTrades/BTCUSDT/BTCUSDT-aggTrades-2021-10-01.zip"
    files = {url: payload, url + ".CHECKSUM": f"{hashlib.sha256(payload).hexdigest()} *x".encode()}
    got = rs.day_minutes_trades("BTCUSDT", "2021-10-01", {T0}, get=lambda u: files[u])
    assert list(got) == [T0] and got[T0].tolist() == [100.0, 100.5, 101.0]


def test_stratified_sample_covers_every_year_and_regime():
    strata = {f"{y}-0{m}-01": f"{y}|{r}" for y in (2022, 2023, 2024) for m, r in ((1, "low_vol"), (2, "mid_vol"), (3, "high_vol"))}
    strata.update({f"2022-0{m}-15": "2022|low_vol" for m in range(4, 10)})   # one crowded stratum
    picked = rs.stratified_sample(strata, 9, seed=1)
    assert len(picked) == 9 and {strata[d] for d in picked} == set(strata.values())
