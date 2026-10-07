"""Strategy Lab candidate autofinx-v13: Pine-semantics indicators, causality, execution simulation."""

import numpy as np

from v4 import strategy_v13 as v13
from v4.targets import MINUTE


def _bars(n, seed=1, step_ms=300_000, start=1_600_000_000_000 // 86_400_000 * 86_400_000):
    rng = np.random.default_rng(seed)
    c = 100.0 * np.exp(np.cumsum(rng.normal(0, 0.002, n)))
    o = np.r_[c[0], c[:-1]]
    h = np.maximum(o, c) * (1 + rng.uniform(0, 0.001, n))
    l = np.minimum(o, c) * (1 - rng.uniform(0, 0.001, n))
    return {"open_time": start + np.arange(n, dtype=np.int64) * step_ms, "open": o, "high": h, "low": l,
            "close": c, "volume": rng.uniform(10, 20, n)}


def test_ema_and_rma_seed_with_sma_like_pine():
    x = np.array([1.0, 2, 3, 4, 5, 6])
    e = v13.ema(x, 3)
    assert np.isnan(e[:2]).all() and e[2] == 2.0
    assert np.isclose(e[3], 0.5 * 4 + 0.5 * 2.0)
    r = v13.rma(x, 3)
    assert r[2] == 2.0 and np.isclose(r[3], (1 / 3) * 4 + (2 / 3) * 2.0)


def test_rsi_extremes():
    up = np.arange(1.0, 40.0)
    assert np.allclose(v13.rsi(up, 14)[20:], 100.0)
    assert np.allclose(v13.rsi(up[::-1], 14)[20:], 0.0)


def test_supertrend_direction_follows_trend():
    n = 200
    rising = np.linspace(100, 200, n)
    _, d = v13.supertrend(rising + 0.5, rising - 0.5, rising, 3.0, 7)
    assert (d[50:] == -1).all()                       # -1 = bull in Pine
    falling = rising[::-1]
    _, d = v13.supertrend(falling + 0.5, falling - 0.5, falling, 3.0, 7)
    assert (d[50:] == 1).all()


def test_donchian_latch_holds_last_break():
    c = np.r_[np.full(25, 100.0), 105.0, np.full(10, 101.0), 90.0, np.full(5, 95.0)]
    out = v13.donchian_latch(c + 0.1, c - 0.1, c, 20)
    assert out[25] == 1 and (out[26:36] == 1).all() and out[36] == -1 and (out[37:] == -1).all()


def test_htf_reads_only_complete_past_bars():
    b = _bars(3000)
    t = b["open_time"]
    base = v13.htf_trend(t, b, 3_600_000)
    late = {k: v.copy() for k, v in b.items()}
    cut = 2000
    late["close"][cut:] *= 3.0                        # change the future only
    late["high"][cut:] *= 3.0
    after = v13.htf_trend(t, late, 3_600_000)
    assert np.array_equal(base[:cut], after[:cut])    # bars before the change cannot see it


def test_compute_is_causal():
    b = _bars(4000, seed=3)
    a = v13.compute(b, b, "BTCUSDT")
    late = {k: v.copy() for k, v in b.items()}
    k = 3000
    for key in ("open", "high", "low", "close"):
        late[key][k:] *= 1.05
    late["volume"][k:] *= 4
    z = v13.compute(late, late, "BTCUSDT")
    for key in ("long_score", "short_score", "threshold", "chop", "htf1", "htf2", "vwap_gap"):
        assert np.allclose(a[key][:k], z[key][:k], equal_nan=True), key
    assert np.array_equal(a["setup_long"][:k], z["setup_long"][:k])


def _grid(prices_ohlc, start):
    o, h, l, c = (np.array(x, float) for x in zip(*prices_ohlc))
    return {"start": np.array(start, np.int64), "open": o, "high": h, "low": l, "close": c}


def _setup(n_bars, long_at=None, short_at=None, atr=1.0, flip_at=None):
    t = np.arange(n_bars, dtype=np.int64) * 300_000
    z = np.zeros(n_bars)
    ind = {"setup_long": np.zeros(n_bars, bool), "setup_short": np.zeros(n_bars, bool), "atr": np.full(n_bars, atr),
           "long_score": z.copy(), "short_score": z.copy()}
    if long_at is not None:
        ind["setup_long"][long_at] = True
    if short_at is not None:
        ind["setup_short"][short_at] = True
    if flip_at is not None:
        ind["short_score"][flip_at] = 90.0
    return {"open_time": t, "close": np.full(n_bars, 100.0)}, ind


NO_FUNDING = {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}


def test_long_stop_hits_both_halves():
    bars, ind = _setup(4, long_at=0)                  # BTC: risk = 1.8 x ATR(1.0) = 1.8
    minutes = [(100, 100.2, 99.9, 100)] * 5 + [(100, 100.1, 98.0, 98.5)] + [(98.5, 99, 98, 98.5)] * 14
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert len(tr) == 1 and tr[0]["reason_a"] == "STOP" and tr[0]["reason_b"] == "STOP"
    assert np.isclose(tr[0]["gross_pct"], -1.8) and np.isclose(tr[0]["net_pct"], -1.8 - 0.12)


def test_same_minute_stop_and_target_is_stop():
    bars, ind = _setup(4, long_at=0)
    minutes = [(100, 100.2, 99.9, 100)] * 5 + [(100, 103.0, 98.0, 100)] + [(100, 100.1, 99.9, 100)] * 14
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert tr[0]["reason_a"] == "STOP"


def test_tp1_then_trail():
    bars, ind = _setup(6, long_at=0)                  # tp = 100 + 1.8 x 1.5 = 102.7, trail 1.0
    minutes = ([(100, 100.2, 99.9, 100)] * 5 + [(100, 103.0, 99.95, 102.9)] + [(102.9, 104.0, 102.8, 103.9)]
               + [(103.9, 103.95, 102.5, 102.6)] + [(102.6, 102.7, 102.5, 102.6)] * 22)
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert tr[0]["reason_a"] == "TP1" and tr[0]["reason_b"] == "TRAIL"
    assert np.isclose(tr[0]["gross_pct"], 0.5 * 2.7 + 0.5 * 3.0)   # trail stop = peak 104 - 1.0 = 103


def test_short_mirror_and_flip_exit():
    bars, ind = _setup(4, short_at=0)
    minutes = [(100, 100.1, 99.9, 100)] * 20
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert tr == []                                   # still open at the data end -> dropped
    bars, ind = _setup(4, long_at=0, flip_at=2)
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert tr[0]["reason_a"] == "FLIP" and tr[0]["exit_time"] == 3 * 300_000


def test_funding_charged_to_longs():
    bars, ind = _setup(4, long_at=0)
    minutes = [(100, 100.2, 99.9, 100)] * 5 + [(100, 100.1, 99.9, 100)] * 4 + [(100, 100.1, 97.0, 97.5)] + [(97.5, 98, 97, 97.5)] * 10
    funding = {"funding_time": np.array([7 * MINUTE], np.int64), "funding_rate": np.array([0.0001])}
    tr = v13.simulate(bars, ind, _grid(minutes, 0), funding, 300_000, "BTCUSDT")
    assert np.isclose(tr[0]["funding_pct"], 0.01) and np.isclose(tr[0]["net_pct"], tr[0]["gross_pct"] - 0.12 - 0.01)


def test_cooldown_blocks_reentry():
    n = 12
    bars, ind = _setup(n)
    ind["setup_long"][[0, 3]] = True
    minutes = [(100, 100.2, 99.9, 100)] * 5 + [(100, 100.1, 97.0, 97.5)] + [(97.5, 97.6, 97.4, 97.5)] * (n * 5 - 6)
    tr = v13.simulate(bars, ind, _grid(minutes, 0), NO_FUNDING, 300_000, "BTCUSDT")
    assert len(tr) == 1                               # bar 3 is inside the 8-bar cooldown
