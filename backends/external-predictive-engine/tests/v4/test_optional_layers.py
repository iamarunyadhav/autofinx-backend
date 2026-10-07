"""Optional layers: G7-T features are causal; WAIT metrics are exact on a hand case; verdict rule."""

import numpy as np

from v4 import optional_layers as ol
from v4.targets import MINUTE


def _bars(n, seed=5):
    rng = np.random.default_rng(seed)
    c = 100.0 * np.exp(np.cumsum(rng.normal(0, 0.002, n)))
    o = np.r_[c[0], c[:-1]]
    return {"open_time": np.arange(n, dtype=np.int64) * 300_000, "open": o,
            "high": np.maximum(o, c) * 1.0005, "low": np.minimum(o, c) * 0.9995, "close": c,
            "volume": rng.uniform(10, 20, n)}


def test_g7t_is_causal():
    b = _bars(2000)
    a = ol.tradeability_features(b)
    late = {k: v.copy() for k, v in b.items()}
    k = 1500
    for key in ("open", "high", "low", "close"):
        late[key][k:] *= 1.07
    late["volume"][k:] *= 5
    z = ol.tradeability_features(late)
    for name in ol.G7T:
        assert np.allclose(a[name][:k], z[name][:k], equal_nan=True), name


def test_g7t_efficiency_extremes():
    n = 200
    line = np.linspace(100, 120, n)
    b = {"open_time": np.arange(n, dtype=np.int64) * 300_000, "open": line, "high": line + 0.01, "low": line - 0.01, "close": line}
    f = ol.tradeability_features(b)
    assert np.allclose(f["g7t_eff_12"][60:], 1.0) and np.allclose(f["g7t_flip_24"][60:], 0.0)
    zig = 100 + np.where(np.arange(n) % 2 == 0, 0.0, 1.0)
    b = {"open_time": b["open_time"], "open": zig, "high": zig + 0.01, "low": zig - 0.01, "close": zig}
    f = ol.tradeability_features(b)
    assert np.all(f["g7t_eff_12"][60:] < 0.1) and np.allclose(f["g7t_flip_24"][60:], 1.0)


def test_wait_metrics_hand_case():
    net = np.array([1.0, -1.0, 2.0, -2.0])
    keep = np.array([True, False, False, True])
    anchors = np.arange(4, dtype=np.int64) * MINUTE * 10
    exit_ms = anchors + MINUTE
    m = ol.wait_metrics(net, keep, anchors, exit_ms)
    assert m["loss_avoidance_pct"] == 33.33 and m["profit_retention_pct"] == 33.33
    assert m["false_wait_pct"] == 50.0 and m["trade_retention_pct"] == 50.0
    assert m["executed_off"]["trades"] == 4 and m["executed_on"]["avg_net_pct"] == -0.5


def test_wait_verdict_rules():
    def fold(off, on, ret):
        return {"executed_off": {"avg_net_pct": off}, "executed_on": {"avg_net_pct": on}, "profit_retention_pct": ret}
    assert ol._wait_verdict([fold(-0.1, -0.05, 60)] * 3) == "USEFUL_AS_WAIT_LAYER"
    assert ol._wait_verdict([fold(-0.1, 0.02, 60)] * 3) == "PROFITABLE_WITH_LAYER"
    assert ol._wait_verdict([fold(-0.1, 0.02, 20)] * 3) == "OVER_FILTERED"
    assert ol._wait_verdict([fold(-0.1, -0.1, 80)] * 3) == "NO_VALUE"


def test_g7t_persistence_and_range_position():
    n = 200
    line = np.linspace(100, 120, n)
    b = {"open_time": np.arange(n, dtype=np.int64) * 300_000, "open": line, "high": line + 0.01, "low": line - 0.01,
         "close": line, "volume": np.r_[np.full(n, 10.0)]}
    f = ol.tradeability_features(b)
    assert np.allclose(f["g7t_persistence_12"][60:], 1.0)          # every bar moves with the 12-bar trend
    assert np.all(f["g7t_range_pos_48"][60:] > 0.99)               # close at the top of the 48-bar range
    assert np.allclose(f["g7t_flip_48"][60:], 0.0)


def test_p4b_audit_overlap_detects_duplicates():
    from v4 import p4b_audit as au
    rng = np.random.default_rng(1)
    a = rng.normal(size=2000)
    x = np.column_stack([a, a * 2 + 1, rng.normal(size=2000)])
    o = au.group_overlap(["a", "a2", "z"], {"g1": ["a"], "g2": ["a2"], "g3": ["z"]}, x)
    assert o["g1"] > 0.99 and o["g2"] > 0.99 and o["g3"] < 0.1
