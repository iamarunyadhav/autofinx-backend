"""Regime + Tradeability v1: causality (prefix invariance), closed bars, swing confirmation, higher-timeframe alignment,
no-holdout-read, filter / trade-set invariants, null calibration, planted-effect power. Plan: docs/ml-engine-v1/REGIME_TRADEABILITY_V1.md."""

import hashlib
from pathlib import Path

import numpy as np
import pytest

from edge_search.core import COST_BASE, Coin, select_trades
from regime_v1 import data as D
from regime_v1 import features as F
from regime_v1 import q1, stats as S
from regime_v1 import trades as TR
from v4 import binance_data as bd

M5, DAY = 300_000, 86_400_000
T0S = D._ms(2023, 3, 1)


def make_ohlc(n=3000, seed=1, regime_switch=True):
    rng = np.random.default_rng(seed)
    drift = np.where((np.arange(n) // 400) % 2 == 0, 0.0008, 0.0) if regime_switch else np.zeros(n)
    vol = np.where((np.arange(n) // 300) % 2 == 0, 0.004, 0.012)
    c = 100.0 * np.exp(np.cumsum(rng.normal(drift, vol)))
    o = np.r_[c[0], c[:-1]]
    h = np.maximum(o, c) * (1 + rng.uniform(0, 0.003, n))
    l = np.minimum(o, c) * (1 - rng.uniform(0, 0.003, n))
    return o, h, l, c


def make_coin(n_days=120, seed=3, hole=None, funding=None):
    n = n_days * 288
    o, h, l, c = make_ohlc(n, seed)
    v = np.full(n, 10.0)
    k5 = {"open_time": T0S + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": v,
          "taker_buy_volume": v / 2}
    if hole is not None:
        keep = np.ones(n, bool)
        keep[hole[0]:hole[1]] = False
        k5 = {k: x[keep] for k, x in k5.items()}
    f = funding or {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    return Coin("TESTUSDT", T0S, n, k5, f, None, D.TFS)


def same(a, b):
    a, b = np.asarray(a, float), np.asarray(b, float)
    return np.array_equal(np.isnan(a), np.isnan(b)) and np.allclose(a[~np.isnan(a)], b[~np.isnan(b)], rtol=1e-12, atol=1e-12)


def prefix_invariant(fn, arrays, cuts):
    """fn(*arrays) -> dict of arrays; value at t must not change when only data[:m] is supplied (t < m)."""
    full = fn(*arrays)
    for m in cuts:
        part = fn(*[a[:m] for a in arrays])
        for k in full:
            if isinstance(full[k], dict):
                continue
            if not same(full[k][:m], part[k]):
                return False, k, m
    return True, None, None


# ------------------------------------------------------------------------------------------ causality
def test_regime_and_tradeability_are_prefix_invariant():
    o, h, l, c = make_ohlc(2500)
    ok, k, m = prefix_invariant(F.compute, (o, h, l, c), [300, 777, 1500, 2499])
    assert ok, f"feature {k} changed when future bars were removed (cut {m})"


def test_lookahead_is_detected_by_the_same_check():
    def leaky(o, h, l, c):                  # deliberately uses the NEXT close
        f = F.compute(o, h, l, c)
        f["leak"] = np.r_[c[1:], np.nan]
        return f
    o, h, l, c = make_ohlc(1200)
    ok, k, _ = prefix_invariant(leaky, (o, h, l, c), [600, 1000])
    assert not ok and k == "leak"


def test_regime_codes_are_exclusive_and_known():
    o, h, l, c = make_ohlc(2500)
    f = F.compute(o, h, l, c)
    assert set(np.unique(f["regime"])).issubset(set(range(9)))
    assert (f["regime"][:99] == F.UNCERTAIN).all()                 # 100-bar percentile window is not full yet
    assert len(np.unique(f["regime"])) >= 4
    T = f["T"][np.isfinite(f["T"])]
    assert T.min() >= 0 and T.max() <= 1


def test_incomplete_bar_is_uncertain_and_wait():
    coin = make_coin(hole=(60 * 288 + 50, 60 * 288 + 60))
    b = coin.bars["1h"]
    f = F.compute(b.o, b.h, b.l, b.c)
    bad = np.nonzero(~np.isfinite(b.c))[0]
    assert len(bad) >= 1
    for i in bad:
        assert f["regime"][i] == F.UNCERTAIN and not np.isfinite(f["T"][i])
        assert not F.t_gate(f["T"], 0.4)[i]
    # trades never use an incomplete window
    b15 = coin.bars["15m"]
    assert not np.isfinite(b15.c).all()


# ------------------------------------------------------------------------------------------ swings
def test_pivot_needs_k_confirming_bars_and_structure_is_causal():
    rng = np.random.default_rng(5)
    n = 400
    h = 100 + np.cumsum(rng.normal(0, 1, n))
    l = h - rng.uniform(0.2, 1.0, n)
    th, vh, tl, vl = F.pivots(h, l)
    assert len(th) > 5
    j = th - F.K_SWING
    assert (j >= F.K_SWING).all() and (h[j] > h[j - 1]).all() and (h[j] > h[j + 1]).all()
    st = F.structure(h, l)
    for m in (120, 250, 399):
        assert np.array_equal(F.structure(h[:m], l[:m]), st[:m])
    # destroy a pivot with a bar that only appears at its confirmation time: values before j+k must not change
    jj = int(j[3])
    h2 = h.copy()
    h2[jj + F.K_SWING] = h[jj] + 50.0
    st2 = F.structure(h2, l)
    assert np.array_equal(st2[:jj + F.K_SWING], st[:jj + F.K_SWING])


# ------------------------------------------------------------------------------------------ higher timeframe
def test_htf_uses_only_completed_higher_bars():
    ct_lo = T0S + (np.arange(1, 49) * 15 * 60_000)                 # 15m closes
    ct_hi = T0S + (np.arange(1, 13) * 60 * 60_000)                 # 1h closes
    val = np.arange(12)
    out = F.htf_align(ct_lo, ct_hi, val, fill=-1)
    assert out[0] == -1 and out[2] == -1                            # first hour not complete yet
    assert out[3] == 0                                              # at the 1h close the 1h bar 0 is usable
    assert out[4] == 0 and out[6] == 0 and out[7] == 1
    for i, t in enumerate(ct_lo):
        j = out[i]
        if j >= 0:
            assert ct_hi[j] <= t


# ------------------------------------------------------------------------------------------ no holdout read
def test_loader_ignores_files_after_the_cut_and_refuses_later_cut(tmp_path):
    base = tmp_path / "data" / "TESTUSDT" / "5m"
    def rows(t_start, n):
        t = t_start + np.arange(n, dtype=np.int64) * M5
        z = np.ones(n)
        return {"open_time": t, "open": z, "high": z, "low": z, "close": z, "volume": z, "close_time": t + M5 - 1,
                "quote_volume": z, "count": np.ones(n, np.int64), "taker_buy_volume": z, "taker_buy_quote_volume": z}
    bd.write_parquet(base / "monthly" / "2025-12.parquet", rows(D._ms(2025, 12, 1), 288 * 31))
    bd.write_parquet(base / "monthly" / "2026-01.parquet", rows(D._ms(2026, 1, 1), 288 * 31))
    bd.write_parquet(base / "monthly" / "2026-02.parquet", rows(D._ms(2026, 2, 1), 288 * 28))
    bd.write_parquet(base / "daily" / "2026-01-04.parquet", rows(D._ms(2026, 1, 4), 288))
    bd.write_parquet(base / "tail.parquet", rows(D._ms(2026, 1, 5), 10))
    names = [p.name for p in D.month_files(base)]
    assert names == ["2025-12.parquet"]
    k = D.load_k5(tmp_path, "TESTUSDT")
    assert int(k["open_time"].max()) + M5 <= D.CUT and int(k["open_time"].max()) >= D._ms(2025, 12, 31)
    with pytest.raises(ValueError):
        D.load_k5(tmp_path, "TESTUSDT", cut=D.CUT + 1)
    with pytest.raises(ValueError):
        D.check_cut(D._ms(2026, 1, 4))


def test_plan_hash_matches_the_frozen_plan():
    plan = Path(__file__).resolve().parents[2] / "docs" / "ml-engine-v1" / "REGIME_TRADEABILITY_V1.md"
    ref = Path(r"D:\autofinx-ml-v4\regime_v1\PLAN_SHA256.txt")
    if not ref.exists():
        pytest.skip("no frozen hash file on this machine")
    assert hashlib.sha256(plan.read_bytes()).hexdigest() == ref.read_text().split()[0]


# ------------------------------------------------------------------------------------------ trade sets / filters
def test_trade_sets_filters_and_costs():
    funding = {"funding_time": T0S + np.arange(1, 400, dtype=np.int64) * 8 * 3_600_000, "funding_rate": np.full(399, 0.0001)}
    coin = make_coin(funding=funding)
    world = D.World({"TESTUSDT": coin})
    b = coin.bars["1h"]
    feat = F.compute(b.o, b.h, b.l, b.c)
    res = TR.build_tf(world, "TESTUSDT", "1h", feat)
    for unit in TR.ALL_UNITS:
        for ex in TR.EXITS:
            k = f"{unit}|{ex}|"
            n = len(res[k + "net"])
            np.testing.assert_allclose(res[k + "net"], res[k + "gross"] - COST_BASE - res[k + "fund"], atol=1e-12)
            for m in ("T40", "T50", "T60", "M", "TM", "G", "X"):
                assert len(res[k + "m_" + m]) == n
            assert (res[k + "m_TM"] <= res[k + "m_T50"]).all() and (res[k + "m_TM"] <= res[k + "m_M"]).all()
            assert (res[k + "m_T60"] <= res[k + "m_T50"]).all() and (res[k + "m_T50"] <= res[k + "m_T40"]).all()
            assert not (res[k + "m_G"] & res[k + "m_X"]).any() or unit.startswith("S1") or unit.startswith("S2")
            t = res[k + "entry_t"]
            assert (t >= D.WINDOW_LO).all() and (res[k + "exit_t"] <= D.CUT).all()
    # non-overlap: each trade enters at or after the previous exit
    k = "S3_ema_cross|time|"
    assert (res[k + "entry_t"][1:] >= res[k + "exit_t"][:-1]).all()
    # funding paid by longs: long net < long gross - cost when funding > 0
    k = "S1_always_long|time|"
    assert (res[k + "fund"] > 0).any() and (res[k + "fund"] >= 0).all()
    # the unfiltered trade list equals the engine's select_trades
    from edge_search.core import outcomes_time
    sig = np.ones(b.n, np.int8)
    tr = select_trades(sig, outcomes_time(b, TR.H), b.close_time, D.WINDOW_LO, D.CUT)
    assert len(tr) == len(res[k + "net"]) and np.allclose(tr.gross, res[k + "gross"])


def test_gate_semantics():
    reg = np.array([F.STRONG_BULL, F.WEAK_BEAR, F.SIDEWAYS, F.BREAKOUT, F.BREAKOUT, F.UNCERTAIN])
    side = np.array([1, 1, -1, 1, -1, 1])
    brk = np.array([0, 0, 0, 1, 1, 0])
    assert TR.gate("trend", reg, side, brk).tolist() == [True, False, False, False, False, False]
    assert TR.gate("breakout", reg, side, brk).tolist() == [False, False, False, True, False, False]
    assert TR.gate("meanrev", reg, side, brk).tolist() == [False, False, True, False, False, False]


# ------------------------------------------------------------------------------------------ statistics
def test_stats_basics():
    x = np.array([1.0, -2.0, 3.0, -1.0])
    assert S.pf(x) == pytest.approx(4.0 / 3.0)
    assert S.mdd(np.array([1.0, -2.0, -1.0, 3.0]), np.arange(4)) == pytest.approx(3.0)
    rng = np.random.default_rng(1)
    v = rng.normal(0.0, 1.0, 3000)
    m = S.random_subset_means(v, 1000, rng, D=400)
    assert abs(m.mean() - v.mean()) < 0.03 and abs(m.std() - v.std() * np.sqrt((1 / 1000) * (1 - 1000 / 3000))) < 0.01
    mc = S.random_subset_means(v, 2500, rng, D=400)                 # complement path
    assert abs(mc.mean() - v.mean()) < 0.03


def test_filter_null_is_calibrated_and_planted_filter_is_found():
    rng = np.random.default_rng(2)
    N = 4000
    et = D.WINDOW_LO + np.sort(rng.integers(0, 600 * 86_400_000, N))
    keep_random = rng.random(N) < 0.4
    keep_good = rng.random(N) < 0.4
    net = rng.normal(-0.1, 1.0, N)
    net_planted = net + 0.5 * keep_good
    P = {"net": net, "gross": net + 0.12, "fund": np.zeros(N), "entry_t": et, "exit_t": et + 3_600_000, "coin": np.array(["A"] * N)}
    r0 = S.summarize(P, keep_random, True, "t1")
    assert r0["p_help"] > 0.01
    P2 = dict(P, net=net_planted, gross=net_planted + 0.12)
    r1 = S.summarize(P2, keep_good, True, "t2")
    assert r1["p_help"] < 0.01 and r1["ci90_delta"][0] > 0 and r1["delta"] > 0.2


def test_q1_circular_shift_null_calibrated_and_power():
    n = 6000
    rng = np.random.default_rng(4)
    lab = np.repeat(rng.integers(1, 5, n // 100 + 1), 100)[:n].astype(np.int8)     # autocorrelated random labels
    g = rng.normal(0, 0.3, n)
    rv = np.abs(rng.normal(0.3, 0.05, n))
    ct = D.WINDOW_LO + np.arange(1, n + 1) * 900_000
    mask = np.ones(n, bool)
    res = q1.cell_stats(lab, g, rv, mask, ct, "t", n_null=300)
    ps = [v["p_dir"] for v in res["regimes"].values() if v.get("n", 0) >= 30 and "p_dir" in v]
    assert min(ps) > 0.01                                                     # random labels: nothing significant
    g2 = g + 0.4 * (lab == 2)                                                 # planted drift in regime 2
    res2 = q1.cell_stats(lab, g2, rv, mask, ct, "t", n_null=300)
    assert res2["regimes"]["WEAK_BULL"]["p_dir"] < 0.02 and res2["regimes"]["WEAK_BULL"]["dD"] > 0.2
    g3 = g * np.where(lab == 3, 3.0, 1.0)                                      # planted magnitude in regime 3
    res3 = q1.cell_stats(lab, g3, rv, mask, ct, "t", n_null=300)
    assert res3["regimes"]["STRONG_BEAR"]["p_mag"] < 0.02 and res3["regimes"]["STRONG_BEAR"]["R"] > 2


def test_forward_rv_window_is_strictly_future():
    c = 100 * np.exp(np.cumsum(np.r_[0, np.full(99, 0.001)]))
    rv = q1.forward_rv(c, 1, 100, 2)
    assert rv[10] == pytest.approx(np.sqrt(2) * 0.1, rel=1e-9)               # returns at 11 and 12 only
    assert np.isnan(rv[98]) and np.isfinite(rv[97])
