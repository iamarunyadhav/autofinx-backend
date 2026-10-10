"""V5-lite (D-041): causal regimes / events / premium, the decision rule and the pre-registered PASS rule."""

import numpy as np

from v4 import premium
from v4 import v5_lite as v5


def _bars(c, start=1_600_000_000_000 // 14_400_000 * 14_400_000):
    c = np.asarray(c, float)
    o = np.r_[c[0], c[:-1]]
    return {"open_time": start + np.arange(len(c), dtype=np.int64) * 300_000, "open": o,
            "high": np.maximum(o, c) * 1.0006, "low": np.minimum(o, c) * 0.9994, "close": c, "volume": np.ones(len(c))}


def test_regimes_and_events_are_causal():
    rng = np.random.default_rng(9)
    b = _bars(100 * np.exp(np.cumsum(rng.normal(0, 0.0025, 12000))))
    sup = rng.normal(0, 0.01, 12000)
    e1 = v5.events(b, sup)
    late = {k: v.copy() for k, v in b.items()}
    k = 10000
    for key in ("open", "high", "low", "close"):
        late[key][k:] *= 1.15
    e2 = v5.events(late, sup)
    assert np.array_equal(e1["regime"][:k], e2["regime"][:k])
    assert np.array_equal(e1["S1"][:k], e2["S1"][:k]) and np.array_equal(e1["S2"][:k], e2["S2"][:k])


def test_uptrend_produces_long_pullbacks_only():
    n = 12000
    c = 100 * np.exp(np.linspace(0, 0.9, n)) * (1 + 0.006 * np.sin(np.arange(n) / 7.0))
    e = v5.events(_bars(c), np.full(n, 0.01))
    assert (e["regime"][6000:] == "UP").mean() > 0.5
    assert (e["S1"][6000:] == 1).sum() > 0 and (e["S1"][6000:] == -1).sum() == 0


def test_decide_and_utility():
    u = v5.utility(np.array([0.30, 0.05]), np.array([-0.10, -0.50]))
    assert np.allclose(u, [0.30 - 0.25 * 0.40 - 0.03, 0.05 - 0.25 * 0.55 - 0.03])
    side = v5.decide(np.array([0.10, 0.10, -0.1, 0.05]), np.array([0.0, 0.09, 0.10, 0.04]))
    assert list(side) == [1, 0, -1, 0]                    # margin 0.02 required


def _summary(**kw):
    base = {"fold_avg_net": [0.05, 0.04, 0.02, 0.03, -0.01], "pf": 1.3, "trades": 400, "boot90": [0.0, 0.06],
            "avg_net_cost_0.15": 0.01, "avg_net_delay_1m": 0.02, "per_symbol_avg_net": {"BTCUSDT": 0.03, "ETHUSDT": 0.02},
            "max_fold_profit_share": 0.35, "avg_net": 0.03, "rule_avg_net": -0.01}
    base.update(kw)
    return base


def test_pass_rule():
    assert v5.pass_rule(_summary())[0]
    assert not v5.pass_rule(_summary(fold_avg_net=[0.05, -0.01, 0.02, -0.03, 0.01]))[0]
    assert not v5.pass_rule(_summary(pf=1.1))[0]
    assert not v5.pass_rule(_summary(avg_net_delay_1m=-0.001))[0]
    assert not v5.pass_rule(_summary(per_symbol_avg_net={"BTCUSDT": 0.05, "ETHUSDT": -0.01}))[0]
    assert not v5.pass_rule(_summary(rule_avg_net=0.02))[0]          # must beat the rule-only baseline by 0.02


def test_premium_uses_complete_bars_only():
    t = np.arange(100, dtype=np.int64) * 300_000
    p = {"open_time": t, "close": np.linspace(0.0001, 0.0002, 100)}
    f = premium.features(np.array([t[10]]), p)                        # T0 = open of bar 10 = close of bar 9
    assert np.isclose(f["ctx_premium_last"][0], p["close"][9] * 100)
    f = premium.features(np.array([t[99] + 300_000 + 30 * 60_000]), p)
    assert np.isnan(f["ctx_premium_last"][0])                          # 30 min after the data: no carry-forward
