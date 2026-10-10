"""Edge Search v1: engine correctness, causality of every rule family, planted-edge power, lookahead detection, holdout gate."""

import numpy as np
import pytest

from edge_search import core, rules, stages
from edge_search.core import (COST_BASE, DAY, HOUR, M5, Coin, bh_adjust, bh_reject, describe, outcomes_atr, outcomes_time,
                              random_null, select_trades)

T0 = 1_600_000_000_000 // DAY * DAY


def make_k5(n_days=60, seed=1, drift=0.0, vol=0.0009, hole=None):
    n = n_days * 288
    rng = np.random.default_rng(seed)
    c = 100.0 * np.exp(np.cumsum(rng.normal(drift, vol, n)))
    o = np.r_[c[0], c[:-1]]
    h = np.maximum(o, c) * (1 + rng.uniform(0, 0.0005, n))
    l = np.minimum(o, c) * (1 - rng.uniform(0, 0.0005, n))
    v = rng.uniform(10, 20, n)
    k5 = {"open_time": T0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": v,
          "taker_buy_volume": v * rng.uniform(0.4, 0.6, n)}
    if hole is not None:
        keep = np.ones(n, bool)
        keep[hole[0]:hole[1]] = False
        k5 = {k: x[keep] for k, x in k5.items()}
    return k5, n


def make_coin(n_days=60, seed=1, funding=None, **kw):
    k5, n = make_k5(n_days, seed, **kw)
    f = funding or {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    return Coin("TESTUSDT", T0, n, k5, f, None)


class FakeWorld:
    def __init__(self, coins):
        self.coins = coins
        self.work = None


# ------------------------------------------------------------------------------------------ bars
def test_closed_candle_rule_drops_incomplete_buckets():
    coin = make_coin(10, hole=(100, 103))                   # three 5m bars missing at 08:20-08:30 of day 0
    b15, b1h = coin.bars["15m"], coin.bars["1h"]
    assert np.isnan(b15.c[33]) and np.isnan(b15.o[34])      # 15m buckets 33 (5m 99-101) and 34 (102-104) are incomplete
    assert np.isfinite(b15.c[32]) and np.isfinite(b15.c[35])
    assert np.isnan(b1h.c[8])                               # the hour bucket containing the hole is dropped entirely
    full = make_coin(10)
    j = 20
    assert full.bars["1h"].o[j] == full.bars["5m"].o[j * 12] and full.bars["1h"].c[j] == full.bars["5m"].c[j * 12 + 11]
    assert np.isclose(full.bars["1h"].h[j], full.bars["5m"].h[j * 12:(j + 1) * 12].max())
    assert np.isclose(full.bars["1d"].v[2], full.bars["5m"].v[2 * 288:3 * 288].sum())


# ------------------------------------------------------------------------------------------ outcomes
def test_time_outcome_manual():
    b = make_coin(5).bars["1h"]
    o = outcomes_time(b, 3)
    t = 10
    assert np.isclose(o.g_long[t], (b.c[t + 3] / b.o[t + 1] - 1) * 100)
    assert np.isclose(o.g_short[t], -o.g_long[t]) and o.ex_long[t] == t + 3 and o.valid[t]
    assert not o.valid[b.n - 3]


def _brute_atr(b, t, H, side, k):
    E = b.o[t + 1]
    d = k * b.atr[t] / b.c[t]
    S = E * (1 - d) if side > 0 else E * (1 + d)
    for j in range(t + 1, t + H + 1):
        if side > 0 and b.l[j] <= S:
            px = b.o[j] if b.o[j] <= S else S
            return (px / E - 1) * 100, j
        if side < 0 and b.h[j] >= S:
            px = b.o[j] if b.o[j] >= S else S
            return -(px / E - 1) * 100, j
    g = (b.c[t + H] / E - 1) * 100
    return (g if side > 0 else -g), t + H


@pytest.mark.parametrize("H", [1, 3, 12])
def test_atr_stop_matches_brute_force(H):
    b = make_coin(30, seed=3, vol=0.004).bars["1h"]            # volatile so that stops actually trigger
    o = outcomes_atr(b, H, 1.5)
    rng = np.random.default_rng(0)
    ts = rng.choice(np.nonzero(o.valid)[0], 200, replace=False)
    stopped = 0
    for t in ts:
        gl, jl = _brute_atr(b, t, H, 1, 1.5)
        gs, js = _brute_atr(b, t, H, -1, 1.5)
        assert np.isclose(o.g_long[t], gl) and o.ex_long[t] == jl
        assert np.isclose(o.g_short[t], gs) and o.ex_short[t] == js
        stopped += int(jl < t + H) + int(js < t + H)
    assert H == 1 or stopped > 0


def test_atr_gap_fills_at_the_open():
    coin = make_coin(10)
    b = coin.bars["1h"]
    t = 50
    atr = b.atr[t]
    E = b.o[t + 1]
    S = E * (1 - 1.5 * atr / b.c[t])
    b.o[t + 2] = S * 0.99                                    # opens through the stop
    b.l[t + 2] = min(b.l[t + 2], b.o[t + 2])
    o = outcomes_atr(b, 3, 1.5)
    assert o.ex_long[t] == t + 2 and np.isclose(o.g_long[t], (b.o[t + 2] / E - 1) * 100)


# ------------------------------------------------------------------------------------------ trades / funding
def test_greedy_non_overlap_and_windows():
    b = make_coin(10).bars["1h"]
    o = outcomes_time(b, 3)
    sig = np.ones(b.n, np.int8)
    tr = select_trades(sig, o, b.close_time, b.close_time[10], b.close_time[60])
    assert len(tr) > 10 and (np.diff(tr.idx) >= 3).all() and tr.idx[0] == 10
    assert (tr.entry_t >= b.close_time[10]).all() and (tr.exit_t <= b.close_time[60]).all()
    assert tr.idx[-1] + 3 <= 60


def test_funding_sign_and_window():
    f = {"funding_time": np.array([2, 10, 18], np.int64) * HOUR + T0, "funding_rate": np.array([0.0001, 0.0002, -0.0003])}
    c = make_coin(5, funding=f)
    assert np.isclose(c.funding(T0 + 1 * HOUR, T0 + 10 * HOUR), 0.01 + 0.02)          # events 2h and 10h
    assert np.isclose(c.funding(T0 + 2 * HOUR, T0 + 10 * HOUR), 0.02)                  # entry exactly at an event: not paid
    b = c.bars["1h"]
    o = outcomes_time(b, 12)
    sig = np.zeros(b.n, np.int8)
    sig[0] = 1
    tr = select_trades(sig, o, b.close_time, 0, b.close_time[-1])
    long_net = tr.net(c, 0.0)[0]
    assert np.isclose(long_net, tr.gross[0] - 0.01 - 0.02)                              # entry 1h .. exit 13h: events 2h, 10h
    sig[0] = -1
    tr2 = select_trades(sig, o, b.close_time, 0, b.close_time[-1])
    assert np.isclose(tr2.net(c, 0.0)[0], tr2.gross[0] + 0.01 + 0.02)                  # a short receives


# ------------------------------------------------------------------------------------------ statistics
def test_bh_matches_textbook_and_adjusted_p():
    p = np.array([0.001, 0.008, 0.039, 0.041, 0.042, 0.06, 0.074, 0.205, 0.212, 0.216])
    assert list(np.nonzero(bh_reject(p, 0.05))[0]) == [0, 1]
    adj = bh_adjust(p)
    assert np.isclose(adj[0], 0.01) and np.isclose(adj[1], 0.04) and (adj >= p).all() and adj.max() <= 1


def test_block_ci_covers_the_mean_and_is_none_when_tiny():
    rng = np.random.default_rng(2)
    x = rng.normal(0.1, 1.0, 3000)
    t = (np.arange(3000) * 600_000).astype(np.int64)
    lo, hi = core.block_ci(x, t)
    assert lo < x.mean() < hi and hi - lo < 0.2
    assert core.block_ci(x[:10], t[:10]) is None


def test_random_null_is_centred_on_the_pool_mean():
    rng = np.random.default_rng(3)
    nl, ns = rng.normal(0.02, 0.5, 5000), rng.normal(-0.02, 0.5, 5000)
    null = random_null(nl, ns, np.ones(5000, bool), 0, 4999, 300, 0.5, 3)
    assert len(null) == 1000 and abs(null.mean()) < 0.03 and null.std() > 0


# ------------------------------------------------------------------------------------------ rules: causality
def _world(seed=1, days=70):
    coins = {s: make_coin(days, seed + i) for i, s in enumerate(core.SYMBOLS)}
    for s, c in coins.items():
        a = (c.bars["5m"].open_time + M5)
        rng = np.random.default_rng(seed + 50)
        c._ctx = {"anchor_time_ms": a}
        for name in ("ctx_funding_z90", "ctx_funding_last", "ctx_oi_chg_4h", "ctx_global_ls", "ctx_top_ls_sum"):
            c._ctx[name] = rng.normal(0, 1, len(a))
        c._prem = {tf: {"ctx_premium_z24h": rng.normal(0, 1, c.bars[tf].n)} for tf in core.TFS}
    return FakeWorld(coins)


def _truncate_future(world, cut_frac=0.7):
    """Same world but every bar after the cut is replaced by garbage (and ctx after the cut randomised)."""
    rng = np.random.default_rng(99)
    w2 = FakeWorld({})
    for s, c in world.coins.items():
        k5 = {"open_time": c.bars["5m"].open_time.copy()}
        n = c.n5
        cut = int(n * cut_frac)
        for key, arr in (("open", c.bars["5m"].o), ("high", c.bars["5m"].h), ("low", c.bars["5m"].l), ("close", c.bars["5m"].c),
                         ("volume", c.bars["5m"].v), ("taker_buy_volume", c.bars["5m"].tbv)):
            x = arr.copy()
            x[cut:] = x[cut:] * rng.uniform(0.5, 2.0, n - cut)
            k5[key] = x
        c2 = Coin(s, T0, n, k5, {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}, None)
        c2._ctx = {k: (np.where(np.arange(len(v)) < cut, v, rng.normal(0, 1, len(v))) if k != "anchor_time_ms" else v)
                   for k, v in c._ctx.items()}
        c2._prem = {tf: {"ctx_premium_z24h": np.where(np.arange(c.bars[tf].n) < int(c.bars[tf].n * cut_frac), p["ctx_premium_z24h"],
                                                       rng.normal(0, 1, c.bars[tf].n))} for tf, p in c._prem.items()}
        w2.coins[s] = c2
    return w2


def test_every_rule_is_causal():
    w1 = _world()
    w2 = _truncate_future(w1, 0.7)
    checked = 0
    for spec in rules.build_specs():
        if spec.family == "F4" and spec.label not in ("h05L", "d2L", "preL"):
            continue
        for tf in spec.tfs:
            for coin in spec.coins[:1]:
                a = rules.signal(spec, w1, coin, tf)
                b = rules.signal(spec, w2, coin, tf)
                assert (a is None) == (b is None), spec.sid
                if a is None:
                    continue
                cut = int(len(a) * 0.7) - 2 * 60                        # stay clear of the boundary bar's own window
                assert np.array_equal(a[:cut], b[:cut]), f"{spec.sid} {coin} {tf} uses future data"
                checked += 1
    assert checked > 80


def test_rule_semantics_spot_checks():
    w = _world()
    b = w.coins["BTCUSDT"].bars["1h"]
    s = rules.signal(next(x for x in rules.build_specs() if x.sid == "F4.hour_of_day[h05L]"), w, "BTCUSDT", "1h")
    assert ((s != 0) == (((b.close_time % DAY) // HOUR) == 5)).all() and set(np.unique(s)) <= {0, 1}
    d = rules.signal(next(x for x in rules.build_specs() if x.sid == "F4.day_of_week[d0L]"), w, "BTCUSDT", "1d")
    bd_ = w.coins["BTCUSDT"].bars["1d"]
    wd = np.array([np.datetime64(int(t), "ms").astype("datetime64[D]").astype(object).weekday() for t in bd_.close_time])
    assert ((d != 0) == (wd == 0)).all()                                # entry day (the bar's close) is a Monday
    ec = rules.signal(next(x for x in rules.build_specs() if x.sid == "F1.ema_cross[8/21]"), w, "BTCUSDT", "1h")
    assert set(np.unique(ec)) <= {-1, 0, 1} and (ec != 0).mean() > 0.9


def test_neighbours_are_one_grid_step_apart():
    specs = rules.build_specs()
    s = next(x for x in specs if x.sid == "F1.donchian[n55]")
    assert sorted(x.sid for x in rules.neighbours(s, specs)) == ["F1.donchian[n100]", "F1.donchian[n20]"]
    h = next(x for x in specs if x.sid == "F4.hour_of_day[h05L]")
    assert sorted(x.sid for x in rules.neighbours(h, specs)) == ["F4.hour_of_day[h04L]", "F4.hour_of_day[h06L]"]


# ------------------------------------------------------------------------------------------ planted edge and lookahead
def _stage1_for(sigfn, coin, tf="1h", H=3, name="t"):
    b = coin.bars[tf]
    o = outcomes_time(b, H)
    sig = sigfn(b)
    n = b.n
    lo, hi = b.close_time[0], b.close_time[n // 2]
    lo2, hi2 = b.close_time[n // 2 + 3 * H], b.close_time[-1]
    rec = {"item_id": name, "dev": stages._seg_stats(select_trades(sig, o, b.close_time, lo, hi), coin, b, 60),
           "val": stages._seg_stats(select_trades(sig, o, b.close_time, lo2, hi2), coin, b, 60)}
    return rec


def test_planted_edge_is_found_and_noise_is_not():
    # an edge: a "tell" bar (up/down shock) is followed by a 0.4 % move in its direction over the next 3 bars
    rng = np.random.default_rng(8)
    k5, n = make_k5(300, 5, vol=0.0006)
    c = k5["close"].copy()
    tells = rng.random(n // 12) < 0.04
    drift = np.zeros(n)
    sign = rng.choice([-1, 1], n // 12)
    for i in np.nonzero(tells)[0]:
        a = (i + 1) * 12
        if a + 36 < n:
            drift[a:a + 36] += sign[i] * 0.004 / 36
    cum = np.cumsum(np.r_[0, np.diff(np.log(c))] + drift)
    c = 100 * np.exp(cum)
    o = np.r_[c[0], c[:-1]]
    k5.update({"close": c, "open": o, "high": np.maximum(o, c) * 1.0003, "low": np.minimum(o, c) * 0.9997})
    coin = Coin("T", T0, n, k5, {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}, None)
    tell_sign = np.zeros(coin.bars["1h"].n, np.int8)
    for i in np.nonzero(tells)[0]:
        if i < len(tell_sign):
            tell_sign[i] = sign[i]
    recs = [_stage1_for(lambda b: tell_sign, coin, name="edge")]
    rng2 = np.random.default_rng(9)
    for j in range(40):
        rs = rng2.choice([-1, 0, 1], coin.bars["1h"].n, p=[0.05, 0.9, 0.05]).astype(np.int8)
        recs.append(_stage1_for(lambda b, rs=rs: rs, coin, name=f"noise{j}"))
    info = stages.stage1_decide(recs)
    assert recs[0]["s1_status"] == "SURVIVOR", recs[0]
    assert sum(1 for r in recs[1:] if r["s1_status"] == "SURVIVOR") == 0
    assert info["n_survivors"] == 1


def test_lookahead_is_detected_by_the_shift_logic():
    coin = make_coin(120, seed=4, vol=0.003)
    b = coin.bars["1h"]
    o = outcomes_time(b, 1)
    peek = np.zeros(b.n, np.int8)
    ret_next = np.r_[b.c[1:] / b.o[1:] - 1, 0]
    peek[:] = np.sign(ret_next)                       # uses bar t+1: pure lookahead
    lo, hi = b.close_time[0], b.close_time[-1]
    g_peek = select_trades(peek, o, b.close_time, lo, hi).gross.mean()
    g_delayed = select_trades(stages.delayed(peek, 1), o, b.close_time, lo, hi).gross.mean()
    assert g_peek > 0.5 and abs(g_delayed) < 0.1       # a causal edge never behaves like this: delaying kills the lookahead
    causal = np.sign(np.r_[0, b.c[1:] / b.o[1:] - 1][: b.n]).astype(np.int8)       # momentum of the bar that just closed
    g_c = select_trades(causal, o, b.close_time, lo, hi).gross.mean()
    assert abs(g_c) < 0.1


# ------------------------------------------------------------------------------------------ judge / gate
def _seg(n, mean, t, **kw):
    d = {"n": n, "mean_net": mean, "t": t, "mde_net": 0.05, "sd": 1.0, "ci95": [0.01, 0.2], "folds_valid": 5, "fold_pos_share": 0.8,
         "years_valid": 3, "years_pos": 3, "null_p": 0.001}
    d.update(kw)
    return d


def test_judge_pass_fail_inconclusive():
    chk, v, r = stages.judge(_seg(300, 0.2, 4.0), _seg(150, 0.15, 2.5), 100)
    assert v == "PASS" and not r
    chk, v, r = stages.judge(_seg(300, -0.05, -1.0, mde_net=0.05, ci95=[-0.1, 0.0]), _seg(150, 0.1, 2.5), 100)
    assert v == "FAIL" and "dev_net_pos" in r
    chk, v, r = stages.judge(_seg(30, 0.2, 2.0, mde_net=0.4), _seg(150, 0.15, 2.5), 100)
    assert v == "INCONCLUSIVE" and all(x.startswith("UNDERPOWERED") for x in r)
    chk, v, r = stages.judge(_seg(300, 0.2, 4.0), _seg(150, 0.05, 2.5), 100)
    assert v == "FAIL" and r == ["val_half"]


def test_holdout_gate_allows_one_read_and_logs_before_reading(tmp_path):
    gate = stages.HoldoutGate(tmp_path, "abc")
    seen = []

    def read():
        seen.append((tmp_path / "holdout_reads.json").exists())     # the access is already logged when the read happens
        return {"pass": False}
    assert gate.read("cand1", read) == {"pass": False} and seen == [True]
    with pytest.raises(RuntimeError):
        gate.read("cand1", read)
    gate.read("cand2", read)
    assert len(seen) == 2


def test_segments_never_overlap_and_embargo():
    seg = {"global_cut": 1000 * DAY, "global_dev_end": 999 * DAY,
           "symbols": {"X": {"first_open": 0, "last_close": 1100 * DAY, "hold_start": 1000 * DAY, "dev_end": 999 * DAY,
                             "train_end": 600 * DAY, "val_start": 601 * DAY, "val_end": 999 * DAY}}}
    w = core.windows(seg, "X")
    assert w["dev"][1] < w["val"][0] and w["val"][1] < w["hold"][0]


def test_carry_trade_arithmetic():
    from edge_search import special
    ft = T0 + np.array([0, 8, 16, 24, 32, 40], np.int64) * HOUR
    fr = np.array([0.05, 0.03, 0.02, 0.0, 0.0, 0.0]) / 100.0
    coin = make_coin(3, funding={"funding_time": ft, "funding_rate": fr})
    P = coin.bars["1h"]
    spot = P.o * 1.0
    spot[:] = P.o + 0.1                                            # a constant basis: spot - perp stays 0.1 at all times
    t = special.carry_trades(coin, spot, 0.04, 0.025, T0, T0 + 3 * DAY)
    assert len(t["net"]) == 1 and t["entry_t"][0] == ft[0] + HOUR
    assert t["exit_t"][0] == ft[2] + HOUR                          # rate 0.02 < 0.025 exits after the third event
    assert np.isclose(t["recv"][0], 0.03 + 0.02)
    ie, ix = int((t["entry_t"][0] - P.t0) // HOUR), int((t["exit_t"][0] - P.t0) // HOUR)
    expected_basis = ((spot[ix] / spot[ie] - 1) - (P.o[ix] / P.o[ie] - 1)) * 100
    assert np.isclose(t["basis"][0], expected_basis)
    assert np.isclose(t["net"][0], 0.05 + expected_basis - special.carry_cost())
    assert np.isclose(special.carry_cost(), 0.34)
