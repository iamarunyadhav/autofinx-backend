"""D-035 maker entry: trade-through fills only, entry at the limit, lower cost, and C0 unchanged."""

import numpy as np

from v4 import execution as ex
from v4 import policy as pol
from v4.targets import MINUTE


def _grid(rows):
    o, h, l, c = (np.array(x, float) for x in zip(*rows))
    return {"start": np.array(0, np.int64), "open": o, "high": h, "low": l, "close": c}


def test_fill_needs_trade_through():
    g = _grid([(100, 100.5, 100.0, 100.2), (100.2, 100.3, 100.1, 100.2), (100.2, 100.3, 99.9, 100.0)] + [(100, 100.1, 99.9, 100)] * 20)
    filled, ms, limit = ex.maker_fills(g, np.array([0]), "long", wait_minutes=2)
    assert not filled[0]                       # touched 100.0 = limit, never traded through within 2 min
    filled, ms, _ = ex.maker_fills(g, np.array([0]), "long", wait_minutes=3)
    assert filled[0] and ms[0] == 2 * MINUTE and limit[0] == 100.0
    filled, ms, _ = ex.maker_fills(g, np.array([0]), "short", wait_minutes=2)
    assert filled[0] and ms[0] == 0            # high 100.5 > 100 in the first minute


def test_entry_price_override_and_default_identical():
    g = _grid([(100, 100.2, 99.8, 100.1)] * 30)
    a = pol.simulate(None, np.array([0]), 10, "long", 1.0, 0.5, 0.2, 0.12, grid=g)
    b = pol.simulate(None, np.array([0]), 10, "long", 1.0, 0.5, 0.2, 0.12, grid=g, entry_price=np.array([100.0]))
    assert np.allclose(a["net_pct"], b["net_pct"])
    c = pol.simulate(None, np.array([0]), 10, "long", 1.0, 0.5, 0.2, 0.12, grid=g, entry_price=np.array([99.9]))
    assert c["gross_pct"][0] > a["gross_pct"][0]   # cheaper entry, better gross


def test_c1_cost_and_missed():
    g = _grid([(100, 100.1, 99.95, 100.0)] + [(100.0, 100.2, 99.9, 100.1)] * 30)
    out = ex.simulate_c1(g, np.array([0]), 10, "long", 1.0, 0.5, 0.2, None)
    assert out["filled"][0]
    ref = pol.simulate(None, np.array([0]), 10, "long", 1.0, 0.5, 0.2, ex.COST_C1, grid=g, entry_price=np.array([100.0]))
    assert np.isclose(out["net_pct"][0], ref["net_pct"][0])
    flat = _grid([(100, 100.2, 100.0, 100.1)] + [(100.1, 100.3, 100.05, 100.2)] * 30)
    assert np.isnan(ex.simulate_c1(flat, np.array([0]), 10, "long", 1.0, 0.5, 0.2, None)["net_pct"][0])


def test_meta_verdict_rule():
    from v4.meta_label import verdict
    def fold(off, on, profit_ret, trade_ret):
        return {"executed_off": {"avg_net_pct": off}, "executed_on": {"avg_net_pct": on},
                "profit_retention_pct": profit_ret, "trade_retention_pct": trade_ret}
    assert verdict([fold(-0.12, -0.10, 35, 30)] * 3) == "USEFUL"
    assert verdict([fold(-0.12, 0.02, 35, 30)] * 3) == "PROFITABLE"
    assert verdict([fold(-0.12, -0.10, 25, 30)] * 3) == "NO_VALUE"      # winners cut more than trades
    assert verdict([fold(-0.12, -0.115, 40, 30)] * 3) == "NO_VALUE"     # improvement below 0.01
