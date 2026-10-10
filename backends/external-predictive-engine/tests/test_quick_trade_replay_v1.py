"""Quick Trade replay v1: fill timing, SL / TP / trailing mechanics, causality, random-null integrity and planted-effect power, no holdout read.
Plan: docs/ml-engine-v1/QUICK_TRADE_REPLAY_V1.md."""

import numpy as np
import pytest

from edge_search.core import Coin, outcomes_time, select_trades
from regime_v1 import data as RD
from regime_v1 import features as F
from quick_trade_replay_v1 import arms as A
from quick_trade_replay_v1 import job as J
from quick_trade_replay_v1 import paths as P
from quick_trade_replay_v1 import stats as ST

M5, DAY = 300_000, 86_400_000


def make_bars(n, seed=1, ar=0.0, vol=0.0008, drift=0.0, sub=30, trend=0.0):
    """5m OHLC aggregated from a consistent fine price path (sub steps per bar), so bar extremes are real path extremes and a causal stopping rule
    cannot beat a martingale (discretisation overshoot is the only artefact, it shrinks with sub)."""
    rng = np.random.default_rng(seed)
    m = n * sub
    e = rng.normal(drift, vol / np.sqrt(sub), m)
    if trend:                                   # planted persistent trend state (5m level AR(1), rho 0.97): momentum has a real edge
        mu = np.zeros(n)
        z = rng.normal(0, trend * np.sqrt(1 - 0.97 ** 2), n)
        for i in range(1, n):
            mu[i] = 0.97 * mu[i - 1] + z[i]
        e = e + np.repeat(mu / sub, sub)
    r = e.copy()
    if ar:
        for i in range(1, m):
            r[i] = e[i] + ar * r[i - 1]
    c1 = 100.0 * np.exp(np.cumsum(r))
    c1b = c1.reshape(n, sub)
    c = c1b[:, -1]
    o = np.r_[100.0, c[:-1]]
    h = np.maximum(c1b.max(axis=1), o)
    l = np.minimum(c1b.min(axis=1), o)
    return o, h, l, c


def make_coin(n_days=400, pre_days=60, seed=3, ar=0.0, funding=True, vol=0.0008, trend=0.0):
    """Synthetic coin whose 5m grid starts pre_days before the replay window (WINDOW_LO)."""
    t0 = RD.WINDOW_LO - pre_days * DAY
    n = (n_days + pre_days) * 288
    o, h, l, c = make_bars(n, seed, ar, vol, trend=trend)
    v = np.full(n, 10.0)
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": v, "taker_buy_volume": v / 2}
    ft = t0 + np.arange(1, (n_days + pre_days) * 3, dtype=np.int64) * 8 * 3_600_000
    f = {"funding_time": ft, "funding_rate": np.full(len(ft), 0.0001)} if funding else {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    return Coin("TESTUSDT", t0, n, k5, f, None, ("5m", "15m"))


def windows_from(rows):
    """rows: list of (o, h, l, c) per bar for ONE trade -> W tuple of (1, H5)."""
    a = np.array(rows, float)
    return tuple(a[:, i][None, :] for i in range(4))


# ------------------------------------------------------------------------------------------ fill timing / X1 = engine
def test_x1_equals_engine_outcomes_time_and_chain():
    coin = make_coin(40, 20, funding=False)
    b5 = coin.bars["5m"]
    for tf, h, H5 in (("5m", 12, 12), ("5m", 6, 6), ("15m", 4, 12)):
        b = coin.bars[tf]
        out = outcomes_time(b, h)
        sig = np.ones(b.n, np.int8)
        tr = select_trades(sig, out, b.close_time, RD.WINDOW_LO, RD.CUT)
        assert len(tr) > 50
        e0 = P.entry_index(b5, tr.entry_t)
        W = P.windows(b5, e0, H5)
        # entry = OPEN of the first 5m bar after the signal close; expiry exit = close of the last 5m bar of the horizon
        assert np.array_equal(W[0][:, 0], b5.o[e0])
        assert np.array_equal(b5.open_time[e0], tr.entry_t)
        r = P.simulate(W, 1)
        assert np.allclose(r["gross"], tr.gross, rtol=1e-12, atol=1e-12)
        assert np.array_equal(P.exit_times(tr.entry_t, r["exit_k"]), tr.exit_t)
        assert (r["reason"] == P.TIME).all()
        rs = P.simulate(W, -1)
        assert np.allclose(rs["gross"], -tr.gross, rtol=1e-12, atol=1e-12)


def test_signal_bar_is_closed_and_entry_is_next_open():
    coin = make_coin(10, 5, funding=False)
    b = coin.bars["15m"]
    b5 = coin.bars["5m"]
    tr = select_trades(np.ones(b.n, np.int8), outcomes_time(b, 4), b.close_time, RD.WINDOW_LO, RD.CUT)
    i = int(tr.idx[3])
    assert tr.entry_t[3] == b.close_time[i]                       # decided at the close of bar i ...
    e0 = int(P.entry_index(b5, tr.entry_t[3:4])[0])
    assert b5.open_time[e0] == b.close_time[i]                    # ... filled at the open of the next 5m bar
    assert b5.o[e0] == b.o[i + 1]


# ------------------------------------------------------------------------------------------ SL / TP mechanics
def test_stop_gap_fills_at_the_worse_open():
    # long, stop 1 % ; bar 1 opens 3 % lower
    W = windows_from([(100, 100.2, 99.9, 100.1), (97, 97.5, 96.5, 97.2), (97, 98, 96, 97)])
    r = P.simulate(W, 1, stop=0.01)
    assert r["reason"][0] == P.GAP and r["exit_k"][0] == 1
    assert r["gross"][0] == pytest.approx(-3.0)                   # filled at the 97 open, NOT at the 99 stop
    # short mirror: opens 3 % higher
    W = windows_from([(100, 100.1, 99.8, 99.9), (103, 103.5, 102.5, 103.2), (103, 103, 102, 102)])
    r = P.simulate(W, -1, stop=0.01)
    assert r["reason"][0] == P.GAP and r["gross"][0] == pytest.approx(-3.0)


def test_stop_fill_level_and_tp_fill_level_no_favourable_gap():
    W = windows_from([(100, 100.5, 98.5, 99.0), (99, 99.5, 98, 98.5)])
    r = P.simulate(W, 1, stop=0.01)
    assert r["reason"][0] == P.SL and r["gross"][0] == pytest.approx(-1.0)            # stop level, bar 0 low 98.5 <= 99
    # take-profit: bar 1 gaps UP through the TP: filled at the TP level, not at the better open
    W = windows_from([(100, 100.3, 99.9, 100.2), (103, 104, 102.5, 103.5)])
    r = P.simulate(W, 1, stop=0.01, tp=0.02)
    assert r["reason"][0] == P.TP and r["gross"][0] == pytest.approx(2.0)


def test_same_bar_sl_and_tp_is_ambiguous_and_counted_as_the_stop():
    W = windows_from([(100, 102.5, 98.0, 100.5), (100, 101, 99.5, 100)])
    r = P.simulate(W, 1, stop=0.01, tp=0.02)
    assert r["reason"][0] == P.AMBIG
    assert r["gross"][0] == pytest.approx(-1.0)                                         # the worse of the two
    r2 = P.simulate(W, -1, stop=0.01, tp=0.02)                                          # short: adverse = high 102.5 (>= +1 %), favourable = low 98
    assert r2["reason"][0] == P.AMBIG and r2["gross"][0] == pytest.approx(-1.0)


def test_time_exit_when_nothing_is_hit():
    W = windows_from([(100, 100.4, 99.7, 100.1), (100.1, 100.5, 99.8, 100.3), (100.3, 100.6, 100.0, 100.45)])
    r = P.simulate(W, 1, stop=0.01, tp=0.02)
    assert r["reason"][0] == P.TIME and r["exit_k"][0] == 2 and r["gross"][0] == pytest.approx(0.45)


def test_trailing_and_break_even_only_tighten():
    coin = make_coin(40, 20, funding=False)
    b5 = coin.bars["5m"]
    out = outcomes_time(b5, 12)
    tr = select_trades(np.ones(b5.n, np.int8), out, b5.close_time, RD.WINDOW_LO, RD.CUT)
    W = P.windows(b5, P.entry_index(b5, tr.entry_t), 12)
    R = np.full(len(tr), 0.002)
    for side in (1, -1):
        for kw in ({"stop": R, "arm": 0.5 * R, "trail": 0.5 * R}, {"stop": R, "arm": 0.5 * R, "be": True}):
            r = P.simulate(W, side, trace=True, **kw)
            sp = r["stop_path"]
            d = np.diff(np.where(np.isfinite(sp), sp, np.nan), axis=1)
            assert (d[np.isfinite(d)] >= -1e-15).all()                                 # the stop level never decreases (PnL space)
            assert (sp[:, 0] == -R).all()                                               # initial stop = distance from the entry
    # mechanics: long, arm at +0.5 R, trail 0.5 R. bar0 peak 1.0 %, next bar low reaches peak - trail
    Wm = windows_from([(100, 101.0, 100.6, 100.9), (100.9, 101.1, 100.4, 100.5), (100.5, 100.6, 100.0, 100.3)])
    r = P.simulate(Wm, 1, stop=0.01, arm=0.005, trail=0.005, trace=True)
    assert r["stop_path"][0, 1] == pytest.approx(0.01 - 0.005)                          # new stop = peak 1 % - 0.5 %
    assert r["reason"][0] == P.STOP_TB and r["gross"][0] == pytest.approx(0.5)          # bar 1 low 0.4 % <= 0.5 %: stopped at +0.5 %
    # break-even: after arming, a fall back to the entry exits at 0 (not at the original stop)
    Wb = windows_from([(100, 100.7, 100.1, 100.6), (100.6, 100.7, 99.9, 100.1)])
    r = P.simulate(Wb, 1, stop=0.01, arm=0.005, be=True)
    assert r["reason"][0] == P.STOP_TB and r["gross"][0] == pytest.approx(0.0)


def test_same_bar_arming_and_hit_is_counted_ambiguous():
    # bar 0 arms (high +0.8 %) and its low (-0.1 %) already reaches the break-even level 0: AMBIGUOUS_INTRABAR, stopped at the new level
    W = windows_from([(100, 100.8, 99.9, 100.5), (100.5, 101, 100, 100.8)])
    r = P.simulate(W, 1, stop=0.01, arm=0.005, be=True)
    assert r["reason"][0] == P.AMBIG and r["gross"][0] == pytest.approx(0.0) and r["exit_k"][0] == 0


def test_no_oracle_exit_only_uses_prior_information():
    """Changing the bars AFTER an exit must not change that trade's result (an exit never depends on later bars)."""
    coin = make_coin(30, 10, funding=False)
    b5 = coin.bars["5m"]
    out = outcomes_time(b5, 12)
    tr = select_trades(np.ones(b5.n, np.int8), out, b5.close_time, RD.WINDOW_LO, RD.CUT)
    W = P.windows(b5, P.entry_index(b5, tr.entry_t), 12)
    R = np.full(len(tr), 0.0015)
    r = P.simulate(W, 1, stop=R, tp=2 * R)
    Wc = tuple(x.copy() for x in W)
    for j in range(len(tr)):
        k = int(r["exit_k"][j])
        if k < 11:
            for x in Wc:
                x[j, k + 1:] = x[j, k + 1:] * 1.5 + 7.0
    r2 = P.simulate(Wc, 1, stop=R, tp=2 * R)
    assert np.array_equal(r["gross"], r2["gross"]) and np.array_equal(r["exit_k"], r2["exit_k"])


# ------------------------------------------------------------------------------------------ causality
def test_gates_signals_range_unit_are_prefix_invariant():
    o, h, l, c = make_bars(6000, 5, vol=0.002)
    full = F.compute(o, h, l, c)
    Rfull = A.range_unit(full["atrp"], 12)
    for m in (3000, 4501, 5999):
        part = F.compute(o[:m], h[:m], l[:m], c[:m])
        Rp = A.range_unit(part["atrp"], 12)
        a, b = Rfull[:m], Rp
        assert np.array_equal(np.isnan(a), np.isnan(b)) and np.allclose(a[~np.isnan(a)], b[~np.isnan(b)], rtol=1e-12, atol=1e-12)
        idx = np.arange(200, m)
        mf, mp = A.gate_masks(Rfull, full, idx, "trend"), A.gate_masks(Rp, part, idx, "trend")
        for g in mf:
            assert np.array_equal(mf[g][0], mp[g][0]) and np.array_equal(mf[g][1], mp[g][1]), g


def test_signals_are_prefix_invariant():
    from regime_v1.data import World
    coin = make_coin(30, 10, funding=False)
    w = World({"TESTUSDT": coin})
    full = A.signals(w, "TESTUSDT", "5m", 12)
    cut = (coin.n5 // 2)
    c2 = Coin("TESTUSDT", coin.t0, cut, {k: v[:cut] for k, v in {"open_time": coin.bars["5m"].open_time, "open": coin.bars["5m"].o, "high": coin.bars["5m"].h,
                                                                  "low": coin.bars["5m"].l, "close": coin.bars["5m"].c, "volume": coin.bars["5m"].v,
                                                                  "taker_buy_volume": coin.bars["5m"].tbv}.items()},
              {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}, None, ("5m", "15m"))
    part = A.signals(World({"TESTUSDT": c2}), "TESTUSDT", "5m", 12)
    for k in full:
        assert np.array_equal(full[k][:cut], part[k]), k


def test_x4_table_uses_only_windows_completed_before_the_day_start():
    coin = make_coin(80, 10, funding=False)
    b5 = coin.bars["5m"]
    tab = P.mae_mfe_table(b5, 12, lookback_days=30, min_n=500)
    d = 50
    start = b5.t0 + d * DAY
    assert np.isfinite(tab[d]).all()
    # destroy every bar at or after the day start (and the bars the first incomplete windows touch): the row of that day must not change
    import copy
    b2 = copy.copy(b5)
    cut = int((start - b5.t0) // M5)
    for nm in ("o", "h", "l", "c"):
        x = getattr(b5, nm).copy()
        x[cut:] = x[cut:] * 3.0 + 11.0
        setattr(b2, nm, x)
    tab2 = P.mae_mfe_table(b2, 12, lookback_days=30, min_n=500)
    assert np.array_equal(tab[d], tab2[d])
    # earlier days too (same property)
    assert np.array_equal(tab[:d + 1], tab2[:d + 1], equal_nan=True)


# ------------------------------------------------------------------------------------------ no holdout read
def test_loader_refuses_the_holdout_and_every_file_is_before_2026():
    assert J.CUT == RD.CUT <= RD._ms(2026, 1, 1)
    with pytest.raises(ValueError):
        RD.load_k5("D:/does-not-matter", "BTCUSDT", RD._ms(2026, 1, 3))
    with pytest.raises(ValueError):
        RD.build_coin("D:/does-not-matter", "BTCUSDT", cut=RD._ms(2026, 2, 1))
    assert RD.LAST_MONTH == "2025-12"
    import pathlib
    p = pathlib.Path("D:/autofinx-ml-v4/data/BTCUSDT/5m")
    if p.exists():
        names = [f.name for f in RD.month_files(p)]
        assert names and max(names) <= "2025-12.parquet"


def test_plan_is_frozen_when_a_sha_file_exists():
    import hashlib
    import pathlib
    plan = pathlib.Path(__file__).resolve().parents[2] / "docs" / "ml-engine-v1" / "QUICK_TRADE_REPLAY_V1.md"
    sha = pathlib.Path("D:/autofinx-ml-v4/quick_trade_replay_v1/PLAN_SHA256.txt")
    if sha.exists() and plan.exists():
        assert hashlib.sha256(plan.read_bytes()).hexdigest() == sha.read_text().split()[0]


# ------------------------------------------------------------------------------------------ pipeline on synthetic data
@pytest.fixture(scope="module")
def synth_null():
    coin = make_coin(330, 60, seed=11, ar=0.0)
    arrays, meta = J.run_coin("none", "TESTUSDT", log=lambda *_: None, only_presets=("P1", "P3"), coin=coin)
    return arrays, meta


@pytest.fixture(scope="module")
def synth_planted():
    coin = make_coin(330, 60, seed=12, trend=0.0004)
    arrays, meta = J.run_coin("none", "TESTUSDT", log=lambda *_: None, only_presets=("P1",), coin=coin)
    return arrays, meta


def pooled_mean(a, cost=0.0):
    n = a[:, 0].sum()
    return (a[:, 1].sum() - a[:, 2].sum() - cost * n) / n


def test_random_direction_cannot_be_lifted_by_any_exit_or_gate(synth_null):
    """(a) a causal exit / gate cannot make random direction profitable. Gross (cost 0, no funding) of every A variant on a consistent martingale path must be
    within the fill-discretisation bound: crediting a stop at its exact level while the 5 s test path overshoots it is optimistic by ~0.5826 sigma_step x P(stop)
    (measured 0.0061 / 0.0046 / 0.0006 % for sub = 10 / 30 / 100 steps per bar, i.e. it vanishes as the path gets finer; real fills are worse, not better)."""
    arrays, _ = synth_null
    effs = []
    for k, a in arrays.items():
        parts = k.split("|")
        if len(parts) != 4 or parts[1] != "A" or parts[0] != "P1":
            continue
        if a[:, 0].sum() < 2000:                                        # skip very selective gates (too few trades to test)
            continue
        item = {"type": "P", "key": k}
        arrs = {k: np.c_[a[:, 0], a[:, 1], np.zeros(len(a))]}          # gross only, cost 0
        r = ST.evaluate(item, arrs, 0.0, k)
        effs.append((r["effect"], k))
    assert len(effs) >= 20
    assert max(e for e, _ in effs) < 0.009, sorted(effs)[-3:]          # % per trade: far below the 0.12 % cost
    assert min(e for e, _ in effs) > -0.03, sorted(effs)[:3]          # trailing / BE carry the declared adverse-ordering penalty, never a bonus
    # the plain expiry-only arm is exactly a martingale
    r = ST.evaluate({"type": "P", "key": "x"}, {"x": np.c_[arrays["P1|A|X1|G0"][:, 0], arrays["P1|A|X1|G0"][:, 1], np.zeros(len(arrays["P1|A|X1|G0"]))]}, 0.0, "x1")
    assert abs(r["effect"] / r["se"]) < 4


def test_random_null_with_costs_is_negative_by_about_the_cost(synth_null):
    arrays, _ = synth_null
    a = arrays["P1|A|X1|G0"]
    assert pooled_mean(a, 0.12) == pytest.approx(-0.12, abs=0.06)
    assert arrays["P1|A|X1|G0|seed"].shape == (A.S_SEEDS, 3)


def test_planted_momentum_effect_is_detected_by_the_matched_null(synth_planted):
    arrays, _ = synth_planted
    arm = arrays["P1|B|X1|G0"]
    null = arrays["P1|Bnull|X1|G0|seed"]
    arm_mean = pooled_mean(arm, 0.12)
    seed_means = (null[:, 1] - null[:, 2] - 0.12 * null[:, 0]) / null[:, 0]
    p_dir = (1 + (seed_means >= arm_mean).sum()) / (len(seed_means) + 1)
    assert arm_mean > seed_means.max()
    assert p_dir <= 1 / 101 + 1e-12


def test_no_effect_data_does_not_reject_the_direction_null(synth_null):
    arrays, _ = synth_null
    arm = arrays["P1|B|X1|G0"]
    null = arrays["P1|Bnull|X1|G0|seed"]
    arm_mean = pooled_mean(arm, 0.12)
    seed_means = (null[:, 1] - null[:, 2] - 0.12 * null[:, 0]) / null[:, 0]
    p_dir = (1 + (seed_means >= arm_mean).sum()) / (len(seed_means) + 1)
    assert p_dir > 0.01


def test_gate_and_exit_bookkeeping(synth_null):
    arrays, meta = synth_null
    # every exit arm of an arm uses the SAME trades as X1 (entry-matched)
    n1 = arrays["P1|B|X1|G0"][:, 0]
    for k, a in arrays.items():
        p = k.split("|")
        if len(p) == 4 and p[0] == "P1" and p[1] == "B" and p[3] == "G0":
            assert np.array_equal(a[:, 0], n1), k
    # a gate keeps a subset; skipped = all - kept
    kept = arrays["P1|B|X1|G1b"][:, 0].sum()
    gx = arrays["P1|B|X1|G1b|gx"]
    assert kept + (gx[0] + gx[2]) == pytest.approx(n1.sum() - (n1.sum() - kept - gx[0] - gx[2]))
    assert gx[4] == kept and gx[5] == n1.sum()
    assert kept <= n1.sum()


def test_binary_counts_add_up(synth_null):
    arrays, meta = synth_null
    b = arrays["P1|B|bin"]
    assert b.sum() == arrays["P1|B|X1|G0"][:, 0].sum()
    s = arrays["P1|A|bin"].reshape(A.S_SEEDS, 3, 3)
    assert np.allclose(s.sum(axis=(1, 2)), arrays["P1|A|X1|G0|seed"][:, 0])


# ------------------------------------------------------------------------------------------ statistics: power and calibration
def day_arrays(delta, n_days=1000, per_day=20, sd=0.30, seed=0):
    rng = np.random.default_rng(seed)
    n = np.full(n_days, float(per_day))
    base = rng.normal(0.0, sd, (n_days, per_day))
    arm = base + delta
    zero = np.zeros(n_days)
    a_base = np.c_[n, base.sum(axis=1), zero]
    a_arm = np.c_[n, arm.sum(axis=1), zero]
    return a_base, a_arm


def test_paired_delta_power_and_calibration():
    # planted exit effect of +0.05 % per trade is detected; no effect is not
    b, a = day_arrays(0.05)
    r = ST.evaluate({"type": "DX", "key": "a", "base": "b"}, {"a": a, "b": b}, 0.12, "t1")
    assert r["effect"] == pytest.approx(0.05, abs=1e-9) and r["p"] < 1e-3
    b, a = day_arrays(0.0)
    r0 = ST.evaluate({"type": "DX", "key": "a", "base": "b"}, {"a": a, "b": b}, 0.12, "t2")
    assert r0["p"] > 0.01 or r0["effect"] == 0
    # false-positive rate of the one-sided p-value on pure noise (calibration)
    ps = []
    for s in range(60):
        rng = np.random.default_rng(100 + s)
        n = np.full(400, 20.0)
        g = rng.normal(0, 0.3, (400, 20)).sum(axis=1)
        ps.append(ST.evaluate({"type": "P", "key": "x"}, {"x": np.c_[n, g, np.zeros(400)]}, 0.0, f"c{s}")["p"])
    assert np.mean(np.array(ps) < 0.05) < 0.15


def test_gate_delta_detects_a_planted_gate_and_verdict_rule():
    rng = np.random.default_rng(5)
    nd, per = 1000, 20
    x = rng.normal(0, 0.3, (nd, per))
    keep = np.zeros((nd, per), bool)
    keep[:, :10] = True
    x[keep] += 0.06                                                     # kept trades are better by 0.06 %
    n_all = np.full(nd, float(per))
    a_all = np.c_[n_all, x.sum(axis=1), np.zeros(nd)]
    a_kept = np.c_[keep.sum(axis=1).astype(float), (x * keep).sum(axis=1), np.zeros(nd)]
    r = ST.evaluate({"type": "DG", "key": "k", "base": "a"}, {"k": a_kept, "a": a_all}, 0.0, "g")
    assert r["effect"] > 0.02 and r["p"] < 0.01
    assert ST.verdict(dict(effect=0.07, se=0.01, mde=0.028, ci90=[0.05, 0.09], p=1e-6), 0.001, [0.05] * 3, [0.05, 0.06]) == "LEAD"
    assert ST.verdict(dict(effect=0.07, se=0.01, mde=0.028, ci90=[0.05, 0.09], p=1e-6), 0.001, [0.05, -0.01, 0.05], [0.05, 0.06]) == "INCONCLUSIVE"
    assert ST.verdict(dict(effect=-0.02, se=0.01, mde=0.028, ci90=[-0.04, 0.0], p=0.97), 0.9, [-0.02] * 3, [-0.02] * 2) == "FAIL"
    assert ST.verdict(dict(effect=0.0, se=0.05, mde=0.14, ci90=[-0.08, 0.08], p=0.5), 0.9, [0, 0, 0], [0, 0]) == "INCONCLUSIVE"


def test_year_mapping_and_day_array():
    assert J.N_DAYS == 1096 and list(J.YEAR_START_DAY) == [0, 365, 731, 1096]
    assert J.year_of_day(np.array([0, 364, 365, 730, 731, 1095])).tolist() == [0, 0, 1, 1, 2, 2]


def test_wilson_interval():
    lo, hi = ST.wilson(55, 100)
    assert lo < 0.55 < hi and 0.45 < lo < 0.46 and 0.64 < hi < 0.65


def test_matched_null_grid_and_difference_in_differences(synth_null):
    arrays, _ = synth_null
    # the matched null exists for every exit under G0 and every gate under X1, on the SAME entries as the arm
    for x in A.EXITS:
        assert f"P1|Bnull|{x}|G0" in arrays and np.array_equal(arrays[f"P1|Bnull|{x}|G0"][:, 0], arrays["P1|Bnull|X1|G0"][:, 0])
    n_arm = arrays["P1|B|X1|G0"][:, 0]
    assert np.array_equal(arrays["P1|Bnull|X1|G0"][:, 0], n_arm * A.S_SEEDS)
    # a planted DiD is detected (arm improves under the exit, the null does not) and a pure mechanical effect (both improve alike) is not
    rng = np.random.default_rng(9)
    nd = 800
    def arr(mean):
        n = np.full(nd, 20.0)
        return np.c_[n, rng.normal(mean, 0.3, (nd, 20)).sum(axis=1), np.zeros(nd)]
    base, null_base = arr(0.0), arr(0.0)
    x_arm, x_null = arr(0.06), arr(0.0)
    r = ST.evaluate({"type": "DD", "key": "xa", "base": "ba", "nkey": "xn", "nbase": "bn"}, {"xa": x_arm, "ba": base, "xn": x_null, "bn": null_base}, 0.12, "dd1")
    assert r["effect"] > 0.03 and r["p"] < 0.01
    x_null2 = arr(0.06)
    r2 = ST.evaluate({"type": "DD", "key": "xa", "base": "ba", "nkey": "xn", "nbase": "bn"}, {"xa": x_arm, "ba": base, "xn": x_null2, "bn": null_base}, 0.12, "dd2")
    assert abs(r2["effect"]) < 0.04 and r2["p"] > 0.001
