"""Rule Baseline v1: causality / prefix invariance, swing confirmation, SL / TP ordering, fill timing, distance guard, non-overlap, random-null planted-effect
power, no holdout read. Plan: docs/ml-engine-v1/RULE_BASELINE_V1.md."""

import hashlib
from pathlib import Path

import numpy as np
import pytest

from edge_search.core import Bars, Coin, outcomes_time
from quick_trade_replay_v1 import paths as P
from quick_trade_replay_v1 import stats as ST
from regime_v1 import data as RD
from rule_baseline_v1 import analysis as AN
from rule_baseline_v1 import job as J
from rule_baseline_v1 import spec as S

M5, DAY = 300_000, 86_400_000


def make_bars(n, seed=1, vol=0.002, sub=10, trend=0.0):
    rng = np.random.default_rng(seed)
    m = n * sub
    e = rng.normal(0.0, vol / np.sqrt(sub), m)
    if trend:                                   # planted persistent trend state (AR(1) level, rho 0.97): breakout following has a real edge
        mu = np.zeros(n)
        z = rng.normal(0, trend * np.sqrt(1 - 0.97 ** 2), n)
        for i in range(1, n):
            mu[i] = 0.97 * mu[i - 1] + z[i]
        e = e + np.repeat(mu / sub, sub)
    c1 = 100.0 * np.exp(np.cumsum(e))
    c1b = c1.reshape(n, sub)
    c = c1b[:, -1]
    o = np.r_[100.0, c[:-1]]
    return o, np.maximum(c1b.max(axis=1), o), np.minimum(c1b.min(axis=1), o), c


def make_coin(n_days=200, pre_days=40, seed=3, vol=0.002, trend=0.0, funding=True):
    t0 = RD.WINDOW_LO - pre_days * DAY
    n = (n_days + pre_days) * 288
    o, h, l, c = make_bars(n, seed, vol, trend=trend)
    v = np.full(n, 10.0)
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": v, "taker_buy_volume": v / 2}
    ft = t0 + np.arange(1, (n_days + pre_days) * 3, dtype=np.int64) * 8 * 3_600_000
    f = {"funding_time": ft, "funding_rate": np.full(len(ft), 0.0001)} if funding else {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    return Coin("TESTUSDT", t0, n, k5, f, None, ("5m", "15m"))


def bars_from(o, h, l, c, tf="5m"):
    o, h, l, c = (np.asarray(x, float) for x in (o, h, l, c))
    n = len(o)
    return Bars(tf, 0, o, h, l, c, np.full(n, 10.0), np.full(n, 5.0))


def W1(rows):
    a = np.array(rows, float)
    return tuple(a[:, i][None, :] for i in range(4))


# ------------------------------------------------------------------------------------------ swing confirmation
def test_swing_not_visible_before_confirmation_bar():
    h = np.array([10, 10, 10, 10, 15, 10, 10, 10, 10, 10.0])        # swing high at j = 4
    l = np.array([9, 9, 9, 9, 9.5, 9, 9, 9, 9, 9.0])
    low, high = S.swing_levels(h, l, k=3)
    for t in range(0, 7):                                              # j + 3 = 7 is the confirmation bar
        assert np.isnan(high[t]), t
    assert high[7] == 15.0 and high[9] == 15.0
    # the same series cut at bar 6 (data known at t = 6) never shows it either
    low2, high2 = S.swing_levels(h[:7], l[:7], k=3)
    assert np.isnan(high2).all()


def test_swing_low_mirror_and_most_recent_wins():
    l = np.array([10, 10, 10, 8, 10, 10, 10, 9, 10, 10, 10, 10.0])    # swing lows at j = 3 and j = 7
    h = l + 1
    low, _ = S.swing_levels(h, l, k=3)
    assert np.isnan(low[5]) and low[6] == 8.0 and low[9] == 8.0       # j = 3 confirmed at 6; j = 7 confirmed at 10
    assert low[10] == 9.0 and low[11] == 9.0


# ------------------------------------------------------------------------------------------ causality
def _sig_equal(coin, m_bars, var):
    b = coin.bars["5m"]
    full = S.signals(b, var)
    pre = S.signals(Bars("5m", b.t0, b.o[:m_bars], b.h[:m_bars], b.l[:m_bars], b.c[:m_bars], b.v[:m_bars], b.tbv[:m_bars]), var)
    for k in ("side", "stop_px", "dist"):
        a, c = full[k][:m_bars], pre[k]
        assert np.array_equal(np.nan_to_num(a, nan=-9e9), np.nan_to_num(c, nan=-9e9)), (k, var)


@pytest.mark.parametrize("vname", list(S.VARIANTS))
def test_prefix_invariance_signals_swing_distance(vname):
    coin = make_coin(60, 40, seed=5)
    n = coin.bars["5m"].n
    for m in (n // 3, n // 2 + 7, n - 200):
        _sig_equal(coin, m, S.VARIANTS[vname])
    assert (S.signals(coin.bars["5m"], S.VARIANTS[vname])["side"] != 0).sum() > 100


def test_planted_lookahead_is_caught_by_the_prefix_check():
    coin = make_coin(30, 30, seed=7)
    b = coin.bars["5m"]
    m = b.n // 2
    leaky = lambda bb: np.where(np.r_[bb.c[1:], np.nan] > bb.c, 1, -1)       # uses the NEXT close
    pre = leaky(Bars("5m", b.t0, b.o[:m], b.h[:m], b.l[:m], b.c[:m], b.v[:m], b.tbv[:m]))
    assert not np.array_equal(leaky(b)[:m], pre)                              # differs at the last prefix bar: a future close was used


def test_breakout_uses_previous_bars_only_and_ema_filter():
    n = 60
    c = np.full(n, 100.0)
    h = c + 0.5
    l = c - 0.5
    o = c.copy()
    c[45] = 101.0                                                           # closes above the previous 20-bar highest high (100.5)
    h[45] = 101.2
    b = bars_from(o, h, l, c)
    off = {"ema": None, "brk": 20, "sl": "swing"}
    s = S.signals(b, off)["side"]
    assert s[45] == 1 and (s[:45] == 0).all()
    # a high inside the window blocks the breakout; the current bar's own high does not
    h2 = h.copy()
    h2[40] = 101.5
    assert S.signals(bars_from(o, h2, l, c), off)["side"][45] == 0
    # EMA filter: close must be above EMA200 (needs 200 bars; before that no signal)
    assert (S.signals(b, {"ema": 200, "brk": 20, "sl": "swing"})["side"] == 0).all()


# ------------------------------------------------------------------------------------------ SL / TP ordering and ambiguity
def test_stop_tp_ordering_ambiguity_gap_and_timeout():
    R = 0.01
    # long, entry open 100: stop 99, tp 102
    ok = lambda rows: P.simulate(W1(rows), 1, stop=R, tp=2 * R)
    tp = ok([(100, 100.5, 99.5, 100.2), (100.2, 102.5, 100, 102.2)])
    assert tp["reason"][0] == P.TP and tp["exit_k"][0] == 1 and np.isclose(tp["gross"][0], 2.0)
    sl = ok([(100, 100.5, 98.5, 99.0)])
    assert sl["reason"][0] == P.SL and np.isclose(sl["gross"][0], -1.0)
    both = ok([(100, 102.5, 98.9, 100.0)])                                     # the same bar reaches both: stop
    assert both["reason"][0] == P.AMBIG and np.isclose(both["gross"][0], -1.0)
    gap = ok([(100, 100.5, 99.5, 99.9), (98.0, 98.5, 97.0, 97.5)])             # next bar OPENS below the stop: fills at the open (worse)
    assert gap["reason"][0] == P.GAP and np.isclose(gap["gross"][0], -2.0)
    tmo = ok([(100, 100.4, 99.6, 100.1), (100.1, 100.4, 99.6, 100.3)])
    assert tmo["reason"][0] == P.TIME and np.isclose(tmo["gross"][0], 0.3)
    # short mirror: stop 101, tp 98
    sh = P.simulate(W1([(100, 100.5, 97.5, 98.0)]), -1, stop=R, tp=2 * R)
    assert sh["reason"][0] == P.TP and np.isclose(sh["gross"][0], 2.0)
    shs = P.simulate(W1([(100, 101.5, 99.5, 101.0)]), -1, stop=R, tp=2 * R)
    assert shs["reason"][0] == P.SL and np.isclose(shs["gross"][0], -1.0)


# ------------------------------------------------------------------------------------------ fill timing and the candidate pipeline
def test_entry_at_next_open_exit_time_and_equals_engine_time_exit():
    coin = make_coin(40, 40, seed=11, funding=False)
    b5 = coin.bars["5m"]
    var = S.VARIANTS["AB_PLAIN"]
    tf, h, H5 = S.PRESETS["P1"]
    c, stat = J.candidates(coin, b5, tf, h, H5, var)
    assert len(c["idx"]) > 50
    b = coin.bars["5m"]
    # entry bar: the 5m bar whose OPEN time is the signal bar's close time; its open is the fill
    assert np.array_equal(b5.open_time[c["e0"]], c["entry_t"]) and np.array_equal(c["entry_t"], b.close_time[c["idx"]])
    # an unconstrained (no stop / tp) simulation of the same entries equals the engine's time exit
    out = outcomes_time(b, h)
    W = P.windows(b5, c["e0"], H5)
    r = P.simulate(W, c["side"])
    eng = np.where(c["side"] > 0, out.g_long[c["idx"]], out.g_short[c["idx"]])
    assert np.allclose(r["gross"], eng, rtol=1e-12, atol=1e-12)
    # with the rule's stop / tp the trade never starts before the signal close and ends within the expiry
    sim = J.simulate_candidates(coin, b5, H5, c)
    assert (sim["exit_t"] > c["entry_t"]).all() and (sim["exit_t"] <= c["entry_t"] + H5 * M5).all()


def test_distance_guard_and_stop_geometry():
    assert S.distance_ok(np.array([0.0024, 0.0025, 0.01, 0.015, 0.0151, -0.01, np.nan])).tolist() == [False, True, True, True, False, False, False]
    coin = make_coin(60, 40, seed=13, funding=False)
    b5 = coin.bars["5m"]
    for vname in ("PRIMARY", "AB_NOSWING"):
        c, _ = J.candidates(coin, b5, "5m", 12, 12, S.VARIANTS[vname])
        assert len(c["idx"]) > 20
        assert (c["frac"] * 100 >= S.FLOOR_PCT - 1e-9).all() and (c["frac"] * 100 <= S.CAP_PCT + 1e-9).all()
        if vname == "PRIMARY":                                                 # long stop below the entry, short stop above it, at the swing price
            sg = S.signals(coin.bars["5m"], S.VARIANTS["PRIMARY"])
            px = sg["stop_px"][c["idx"]]
            E = b5.o[c["e0"]]
            assert (np.where(c["side"] > 0, px < E, px > E)).all()
            assert np.allclose(c["frac"], np.abs(E - px) / E)


def test_chain_non_overlap_and_trade_table():
    coin = make_coin(60, 40, seed=17)
    arrs: dict = {}
    st = J.run_variant(coin, "TESTUSDT", "P1", "AB_PLAIN", arrs)
    pre = "P1|AB_PLAIN|"
    assert st["trades"] > 30 and st["trades"] <= st["candidates"]
    e, x = arrs[pre + "tbl_entry_t"], arrs[pre + "tbl_exit_t"]
    assert (x > e).all() and (e[1:] >= x[:-1]).all()                          # non-overlapping, ordered
    assert arrs[pre + "day"][:, 0].sum() == st["trades"]
    assert arrs[pre + "null_seed"].shape == (S.S_SEEDS, 3)
    assert np.isclose(arrs[pre + "null"][:, 0].sum(), st["trades"] * S.S_SEEDS)
    keep = J.chain(np.array([0, 1, 2, 5, 6]), np.array([3, 2, 4, 7, 6]))       # 0->exit3 blocks 1,2; 5 ok (>=3); 6 blocked by exit 7
    assert keep.tolist() == [0, 3]


# ------------------------------------------------------------------------------------------ random null: planted effect power and no false positive
def _coin_to_results(coin, tmp_path, sym="BTCUSDT", presets=("P1",), variants=("PRIMARY",)):
    arrays, meta = J.run_coin(None, sym, lambda *_: None, only_presets=presets, only_variants=variants, coin=coin)
    d = tmp_path / "arrays"
    d.mkdir(parents=True, exist_ok=True)
    np.savez_compressed(d / f"{sym}.npz", **arrays)
    (d / f"{sym}.meta.json").write_text('{"stats": {}}', "utf-8")
    return arrays


def _delta(arrays, pre, cost):
    a = {"S": arrays[pre + "day"], "N": arrays[pre + "null"]}
    item = {"type": "DG", "key": "S", "base": "N"}
    return ST.evaluate(item, a, cost, "t|" + pre)


def test_null_power_planted_trend_detected_and_no_effect_not_rejected(tmp_path):
    cost = 0.0                                                                  # a signal-quality test: costs would only shift both arms equally
    planted = J.run_coin(None, "TESTUSDT", lambda *_: None, only_presets=("P1",), only_variants=("AB_PLAIN",), coin=make_coin(400, 40, seed=21, trend=0.0004))[0]
    r1 = _delta(planted, "P1|AB_PLAIN|", cost)
    assert r1["effect"] > 0 and r1["ci90"][0] > 0, r1                           # breakout following beats random sides when persistence is planted
    nul = J.run_coin(None, "TESTUSDT", lambda *_: None, only_presets=("P1",), only_variants=("AB_PLAIN",), coin=make_coin(400, 40, seed=22))[0]
    r0 = _delta(nul, "P1|AB_PLAIN|", cost)
    assert abs(r0["effect"]) < 3 * r0["se"] or r0["p"] > 0.001, r0                # a martingale: no breakout edge
    # and the rule cannot make money net on a martingale
    net = ST.evaluate({"type": "P", "key": "S"}, {"S": nul["P1|AB_PLAIN|day"]}, 0.12, "t|net")
    assert net["effect"] < 0


def test_seed_reproducible_and_matched_geometry():
    coin = make_coin(40, 40, seed=31)
    a: dict = {}
    b: dict = {}
    J.run_variant(coin, "TESTUSDT", "P2", "PRIMARY", a)
    J.run_variant(coin, "TESTUSDT", "P2", "PRIMARY", b)
    assert all(np.array_equal(a[k], b[k]) for k in a)
    # the null trades the SAME number of entries per seed as the rule
    assert np.allclose(a["P2|PRIMARY|null_seed"][:, 0], a["P2|PRIMARY|day"][:, 0].sum())


# ------------------------------------------------------------------------------------------ holdout, plan hash, verdict rules
def test_no_holdout_read_loader_refuses_2026():
    with pytest.raises(ValueError):
        RD.build_coin(Path("."), "BTCUSDT", cut=RD.CUT + 1)
    assert RD.LAST_MONTH == "2025-12" and J.CUT == RD.CUT
    assert not any(p.name >= "2026-01.parquet" for p in RD.month_files(Path(r"D:\autofinx-ml-v4\data\BTCUSDT\5m"))) if Path(r"D:\autofinx-ml-v4\data\BTCUSDT\5m").exists() else True


def test_plan_sha_check(tmp_path, monkeypatch):
    from rule_baseline_v1 import runner as R
    plan = Path(R.PLAN)
    assert plan.exists()
    monkeypatch.setattr(R, "OUT", tmp_path)
    (tmp_path / "PLAN_SHA256.txt").write_text(hashlib.sha256(plan.read_bytes()).hexdigest() + " *x\n")
    assert R.plan_sha() == hashlib.sha256(plan.read_bytes()).hexdigest()
    (tmp_path / "PLAN_SHA256.txt").write_text("0" * 64 + " *x\n")
    with pytest.raises(RuntimeError):
        R.plan_sha()


def test_verdict_rules():
    good = {"effect": 0.1, "se": 0.01, "mde": 0.028, "ci90": [0.08, 0.12]}
    byc = {"BTCUSDT": 0.1, "ETHUSDT": 0.1, "BNBUSDT": 0.1, "SOLUSDT": -0.1}
    assert AN.verdict(good, 0.01, [0.1, 0.1, 0.1], byc) == "LEAD"
    assert AN.verdict(good, 0.2, [0.1, 0.1, 0.1], byc) == "INCONCLUSIVE"                   # not FDR-significant
    assert AN.verdict(good, 0.01, [0.1, -0.1, 0.1], byc) == "INCONCLUSIVE"                  # a losing year
    assert AN.verdict(good, 0.01, [0.1, 0.1, 0.1], {**byc, "ETHUSDT": -0.1}) == "INCONCLUSIVE"
    assert AN.verdict(good, 0.01, [0.1, 0.1, 0.1], {**byc, "BNBUSDT": -0.1}) == "INCONCLUSIVE"   # only 2 of 4 coins
    fail = {"effect": -0.1, "se": 0.01, "mde": 0.028, "ci90": [-0.12, -0.08]}
    assert AN.verdict(fail, 0.9, [-0.1] * 3, byc) == "FAIL"
    assert AN.verdict({**fail, "mde": 0.1}, 0.9, [-0.1] * 3, byc) == "INCONCLUSIVE"         # under-powered
