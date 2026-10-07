"""P2 event setups: causal (future bars cannot change past events) and the trend-pullback trigger fires."""

import numpy as np

from v4 import p2_setups as p2


def _bars(c, start=1_600_000_000_000 // 3_600_000 * 3_600_000):
    c = np.asarray(c, float)
    o = np.r_[c[0], c[:-1]]
    return {"open_time": start + np.arange(len(c), dtype=np.int64) * 300_000, "open": o,
            "high": np.maximum(o, c) * 1.0004, "low": np.minimum(o, c) * 0.9996, "close": c,
            "volume": np.ones(len(c))}


def test_events_are_causal():
    rng = np.random.default_rng(4)
    b = _bars(100 * np.exp(np.cumsum(rng.normal(0, 0.002, 6000))))
    e1 = p2.events(b)
    late = {k: v.copy() for k, v in b.items()}
    k = 5000
    for key in ("open", "high", "low", "close"):
        late[key][k:] *= 1.1
    e2 = p2.events(late)
    for name in ("S1_long", "S1_short", "S2_long", "S2_short", "S1_ctx_long", "S2_ctx_long"):
        assert np.array_equal(e1[name][:k], e2[name][:k]), name


def test_trend_pullback_long_fires_in_an_uptrend():
    n = 4000
    trend = 100 * np.exp(np.linspace(0, 0.6, n))                     # strong 1h uptrend
    wave = 1 + 0.004 * np.sin(np.arange(n) / 6.0)                     # 5m pullbacks and resumptions
    e = p2.events(_bars(trend * wave))
    assert e["S1_long"][2000:].sum() > 10
    assert e["S1_short"][2000:].sum() == 0                            # no short continuation in an uptrend
