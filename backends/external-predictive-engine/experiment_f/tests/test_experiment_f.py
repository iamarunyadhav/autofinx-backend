"""Experiment F tests: swing confirmation delay, no early availability (planted look-ahead caught), HH/HL/LH/LL, BOS close vs wick, CHoCH states, S/R causality,
multi-timeframe sync, missing / duplicate / invalid bars, prefix invariance (synthetic AND real BTC), POC rule / accuracy / profit accounting, holdout protection,
isolation manifest. Run: python -m pytest experiment_f/tests -q -p no:cacheprovider (from external-predictive-engine)."""

import json
import os
import sys
from pathlib import Path

import numpy as np
import pytest

ENGINE = Path(__file__).resolve().parents[2]
if str(ENGINE) not in sys.path:
    sys.path.insert(0, str(ENGINE))

from edge_search.core import Bars, Coin, outcomes_time  # noqa: E402
from regime_v1 import data as RD  # noqa: E402
from regime_v1.features import htf_align  # noqa: E402

from experiment_f import isolation  # noqa: E402
from experiment_f.core import dataq  # noqa: E402
from experiment_f.core import params as P  # noqa: E402
from experiment_f.core import structure as S  # noqa: E402
from experiment_f.poc import market_structure_poc as POC  # noqa: E402

M5, DAY = 300_000, 86_400_000
WORK = Path(os.environ.get("AUTOFINX_V4_WORK", r"D:\autofinx-ml-v4"))


# ------------------------------------------------------------------------------------------ helpers
def path_bars(waypoints, half=0.1):
    """Closes follow a straight line (step <= 1 per bar) through the waypoints; bar high / low = close +- half. Returns o, h, l, c."""
    c = [float(waypoints[0])]
    for a, b in zip(waypoints[:-1], waypoints[1:]):
        n = int(abs(b - a))
        c += list(np.linspace(a, b, n + 1)[1:])
    c = np.array(c)
    o = np.r_[c[0], c[:-1]]
    return o, c + half, c - half, c


def _append(arrs, rows):
    """Append bars given as (high, low, close) rows to (o, h, l, c)."""
    o, h, l, c = arrs
    for hh, ll, cc in rows:
        o, h, l, c = np.r_[o, c[-1]], np.r_[h, hh], np.r_[l, ll], np.r_[c, cc]
    return o, h, l, c


def walk(n, seed=1, vol=0.004):
    rng = np.random.default_rng(seed)
    c = 100.0 * np.exp(np.cumsum(rng.normal(0, vol, n)))
    o = np.r_[100.0, c[:-1]]
    h = np.maximum(o, c) * (1 + np.abs(rng.normal(0, vol / 2, n)))
    l = np.minimum(o, c) * (1 - np.abs(rng.normal(0, vol / 2, n)))
    return o, h, l, c


def synth_coin(n_days=120, pre_days=60, seed=3, vol=0.0015, funding=True):
    t0 = RD.WINDOW_LO - pre_days * DAY
    n = (n_days + pre_days) * 288
    rng = np.random.default_rng(seed)
    c = 100.0 * np.exp(np.cumsum(rng.normal(0, vol, n)))
    o = np.r_[100.0, c[:-1]]
    h = np.maximum(o, c) * (1 + np.abs(rng.normal(0, vol / 3, n)))
    l = np.minimum(o, c) * (1 - np.abs(rng.normal(0, vol / 3, n)))
    v = np.full(n, 10.0)
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": v, "taker_buy_volume": v / 2}
    ft = t0 + np.arange(1, (n_days + pre_days) * 3, dtype=np.int64) * 8 * 3_600_000
    f = {"funding_time": ft, "funding_rate": np.full(len(ft), 0.0001)} if funding else {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}
    return Coin("TESTUSDT", t0, n, k5, f, None, ("5m", "15m", "1h", "4h"))


def assert_prefix_invariant(fn, x, cuts):
    """fn(array) -> array of the same length; the value at index t must not change when only x[:cut] is supplied (t < cut)."""
    full = fn(x)
    for cut in cuts:
        pre = fn(x[:cut])
        a, b = np.asarray(full[:cut], float), np.asarray(pre, float)
        assert np.array_equal(np.nan_to_num(a, nan=-9e9), np.nan_to_num(b, nan=-9e9)), f"prefix variance at cut {cut}"


# ------------------------------------------------------------------------------------------ swings
def test_swing_confirmation_delay_exact():
    h = np.array([10, 10, 10, 10, 15, 10, 10, 10, 10, 10.0])
    l = h - 1
    sw = S.find_swings(h, l, 3)
    assert sw["occ"].tolist() == [4] and sw["conf"].tolist() == [7] and sw["kind"].tolist() == [1]
    o = np.r_[10.0, 10.0 * np.ones(9)]
    st = S.run(o, h, l, h - 0.5, 3)
    assert np.isnan(st["H"][:7]).all() and st["H"][7] == 15.0 and st["H_occ"][7] == 4        # known only from bar j + k, and the occurrence time is kept


def test_swing_needs_strict_extreme_and_complete_neighbours():
    h = np.array([10, 10, 10, 10, 15, 15, 10, 10, 10, 10.0])       # equal highs: no strict pivot
    assert len(S.find_swings(h, h - 1, 3)["kind"]) == 0
    h2 = np.array([10, 10, 10, 10, 15, np.nan, 10, 10, 10, 10.0])  # a missing neighbour bar: no pivot
    assert len(S.find_swings(h2, h2 - 1, 2)["kind"]) == 0


def test_hh_hl_lh_ll_labels_and_trend():
    o, h, l, c = path_bars([10, 20, 15, 25, 18, 24])
    sw = S.find_swings(h, l, 2)
    names = [S.label_name(k, lab) for k, lab in zip(sw["kind"], sw["label"])]
    assert names.count("HH") == 1 and names.count("HL") == 1                 # peaks 20 -> 25, troughs 15 -> 18
    st = S.run(o, h, l, c, 2)
    assert st["trend"][-1] == P.BULLISH
    o2, h2, l2, c2 = path_bars([30, 20, 25, 15, 22, 16])
    sw2 = S.find_swings(h2, l2, 2)
    names2 = [S.label_name(k, lab) for k, lab in zip(sw2["kind"], sw2["label"])]
    assert "LH" in names2 and "LL" in names2
    assert S.run(o2, h2, l2, c2, 2)["trend"][-1] == P.BEARISH


def test_trend_uncertain_without_two_swings_and_sideways_when_mixed():
    o, h, l, c = path_bars([10, 20, 15])
    assert (S.run(o, h, l, c, 2)["trend"] == P.UNCERTAIN).all()
    o, h, l, c = path_bars([10, 20, 15, 25, 12, 24])                         # HH with LL: mixed
    assert S.run(o, h, l, c, 2)["trend_raw"][-1] == P.SIDEWAYS


# ------------------------------------------------------------------------------------------ BOS / CHoCH
BULL = [10, 20, 15, 25, 18, 24]


def test_bos_is_close_based_wick_is_not_a_bos():
    o, h, l, c = path_bars(BULL)
    o, h, l, c = (np.r_[x, y] for x, y in ((o, [c[-1], 24.9, 25.6]), (h, [24.5, 25.2, 25.8]), (l, [23.5, 24.4, 25.0]), (c, [24.4, 24.9, 25.6])))
    st = S.run(o, h, l, c, 2)
    i = int(np.nonzero(st["bos"] == 1)[0][0])
    assert c[i] > 25 and h[i - 1] > 25 and c[i - 1] <= 25                    # the previous bar only wicked above the level
    assert st["wick_up"][i - 1] == 1 and st["bos"][i - 1] == 0
    assert (st["bos"] == 1).sum() == 1                                      # a level breaks once
    # a pure wick: spike high above 25 but close back below
    o2, h2, l2, c2 = (x.copy() for x in path_bars(BULL))
    h2[-1] = 26.0
    st2 = S.run(o2, h2, l2, c2, 2)
    assert (st2["bos"] == 0).all() and st2["wick_up"][-1] == 1


def test_bos_uses_only_confirmed_swings():
    o, h, l, c = path_bars([10, 20, 15, 25, 30])                              # the 25 peak is never confirmed as a swing (price keeps rising)
    st = S.run(o, h, l, c, 2)
    assert (st["bos"] == 0).all()


def test_choch_unconfirmed_potential_confirmed_and_failure():
    o, h, l, c = _append(path_bars(BULL + [27, 20]), [(19.0, 17.5, 18.5), (18.0, 17.0, 17.5), (17.6, 16.5, 17.0), (17.0, 16.0, 16.5)])
    st = S.run(o, h, l, c, 2)
    iw = int(np.nonzero(st["ch_state"] == P.CH_UNCONFIRMED)[0][0])
    ip = int(np.nonzero(st["ch_state"] == P.CH_POTENTIAL)[0][0])
    ic = int(np.nonzero(st["ch_conf"] == -1)[0][0])
    assert iw < ip < ic and ic - ip == P.CHOCH_CONFIRM_CLOSES - 1
    assert st["ch_state"][iw] == P.CH_UNCONFIRMED and st["ch_dir"][iw] == -1
    assert st["trend_raw"][ic] == P.BULLISH and st["trend"][ic] == P.UNCERTAIN       # confirmed CHoCH against the trend: transition
    o, h, l, c = _append(path_bars(BULL + [27, 20]), [(18.0, 17.0, 17.5), (19.0, 17.6, 18.8)])     # one close below L* = 17.9, then back inside: cancelled
    st = S.run(o, h, l, c, 2)
    assert (st["ch_conf"] == 0).all() and st["ch_state"][-1] == P.CH_NONE
    assert (st["ch_state"] == P.CH_POTENTIAL).any()


# ------------------------------------------------------------------------------------------ causality
def _state_fn(key, k=3):
    def fn(x):
        n = len(x[0])
        return S.run(x[0], x[1], x[2], x[3], k)[key]
    return fn


class Stack:
    """Array-of-arrays wrapper so assert_prefix_invariant can slice o / h / l / c together."""
    def __init__(self, o, h, l, c):
        self.a = (o, h, l, c)

    def __len__(self):
        return len(self.a[0])

    def __getitem__(self, s):
        return Stack(*(x[s] for x in self.a)) if isinstance(s, slice) else self.a[s]


def test_prefix_invariance_all_state_arrays_synthetic():
    x = Stack(*walk(3000, seed=5))
    keys = ("trend_raw", "trend", "bos", "wick_up", "wick_dn", "rb", "ch_state", "ch_dir", "ch_conf", "ch_pot", "lab_h", "lab_l", "H", "L", "H_occ", "L_occ", "ch_count")
    for key in keys:
        def fn(s, key=key):
            return S.run(s[0], s[1], s[2], s[3], 3)[key]
        assert_prefix_invariant(fn, x, (700, 1501, 2222, 2999))


def test_planted_lookahead_is_caught_by_prefix_invariance():
    x = Stack(*walk(1500, seed=6))

    def leaky(s):                                  # uses the NEXT close: must break prefix invariance
        c = s[3]
        out = np.zeros(len(c))
        out[:-1] = (c[1:] > c[:-1]).astype(float)
        out[-1] = 0.0
        return out
    with pytest.raises(AssertionError):
        assert_prefix_invariant(leaky, x, (500, 900))

    def early_swing(s):                            # a pivot reported at its occurrence bar instead of its confirmation bar
        sw = S.find_swings(s[1], s[2], 3)
        out = np.zeros(len(s))
        out[sw["occ"]] = 1.0
        return out
    with pytest.raises(AssertionError):
        assert_prefix_invariant(early_swing, x, (500, 900, 1200))


def test_zone_status_and_touch_counts_never_use_the_future():
    o, h, l, c = walk(2500, seed=8)
    bars = Bars("15m", 0, o, h, l, c, np.full(2500, 10.0), np.full(2500, 5.0))
    full = S.analyze_tf(bars, 3)
    cut = 1800
    pre = S.analyze_tf(Bars("15m", 0, o[:cut], h[:cut], l[:cut], c[:cut], np.full(cut, 10.0), np.full(cut, 5.0)), 3)
    n_checked = 0
    for t in range(300, cut - 1, 37):
        zf, zp = S.zones_at(full["zones"], t), S.zones_at(pre["zones"], t)
        assert zf == zp, t
        n_checked += len(zf)
    assert n_checked > 20
    # a zone is only born at its swing's confirmation bar: nothing known before it
    z = full["zones"]
    assert (z["born"] == z["occ"] + 3).all()
    assert all(zz["born"] <= t for t in (400, 900) for zz in S.zones_at(z, t))


def test_zone_broken_by_close_and_touch_episodes():
    o, h, l, c = path_bars([10, 20, 12, 19, 12, 19, 25, 25])
    atr = np.full(len(c), 0.5)
    sw = S.find_swings(h, l, 2)
    z = S.build_zones(sw, atr, h, l, c, 100)
    r = [i for i in range(len(z["kind"])) if z["kind"][i] > 0][0]        # resistance at 20
    assert 19.5 < z["center"][r] < 20.5 and z["touch_idx"][r].size >= 1
    brk = int(z["broken_at"][r])
    assert c[brk] > z["hi"][r] and (c[:brk][z["born"][r] + 1:] <= z["hi"][r]).all()
    assert S.zones_at(z, brk - 1)[r]["status"] == "ACTIVE" if brk - 1 >= z["born"][r] else True
    assert [q for q in S.zones_at(z, brk) if q["id"] == r][0]["status"] == "BROKEN"


# ------------------------------------------------------------------------------------------ multi-timeframe
def test_htf_value_only_from_completed_higher_bars():
    ct15 = 900_000 * np.arange(1, 13)                                    # 15m bars closing at 15, 30, ... 180 minutes
    ct1h = 3_600_000 * np.arange(1, 4)                                   # 1h bars closing at 60, 120, 180 minutes
    tr = np.array([1, -1, 0])
    a = htf_align(ct15, ct1h, tr, fill=2)
    assert a[:3].tolist() == [2, 2, 2]                                   # before the first 1h close nothing is known (fill)
    assert a[3] == 1 and a[4:7].tolist() == [1, 1, 1]                    # the 1h bar closing at 60 min is usable from the 15m bar closing at 60 min
    assert a[7] == -1 and a[11] == 0                                     # and the next one only from its own close


def test_poc_signals_use_completed_htf_bars_only_on_synthetic_coin():
    coin = synth_coin(60, 60, seed=11)
    sig = POC.build_signals(coin)
    b1 = coin.bars["1h"]
    st1 = S.run(b1.o, b1.h, b1.l, b1.c, P.PIVOT_K["1h"])
    ct15 = coin.bars["15m"].close_time
    j = np.searchsorted(b1.close_time, ct15, side="right") - 1
    ok = j >= 0
    assert np.array_equal(sig["t1"][ok], st1["trend"][j[ok]])
    assert (b1.close_time[j[ok]] <= ct15[ok]).all()


def test_missing_candles_duplicates_and_ohlc_validity():
    coin = synth_coin(5, 5)
    k = coin.bars["15m"]
    assert dataq.ohlc_problems(k.o, k.h, k.l, k.c, k.v)["high_invalid"] == 0
    o, h, l, c = walk(50, seed=2)
    assert dataq.ohlc_problems(o, h, l, c)["high_invalid"] == 0
    h2 = h.copy()
    h2[3] = c[3] - 1
    assert dataq.ohlc_problems(o, h2, l, c)["high_invalid"] == 1
    assert dataq.duplicate_times(np.array([1, 2, 2, 3])) == 1
    # a missing 5m bar blanks the whole 15m bar (closed-bar rule) and the engine raises no event on it
    t0 = RD.WINDOW_LO
    n = 288 * 3
    cc = 100 + np.sin(np.arange(n) / 20.0) * 5
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": cc, "high": cc + 0.2, "low": cc - 0.2, "close": cc, "volume": np.ones(n), "taker_buy_volume": np.ones(n)}
    drop = 100
    k5 = {key: np.delete(v, drop) for key, v in k5.items()}
    cn = Coin("X", t0, n, k5, {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}, None, ("5m", "15m"))
    assert np.isnan(cn.bars["15m"].c[drop // 3]) and np.isnan(cn.bars["5m"].c[drop])
    b = cn.bars["15m"]
    st = S.run(b.o, b.h, b.l, b.c, 3)
    assert st["bos"][drop // 3] == 0 and st["ch_state"][drop // 3] == 0


# ------------------------------------------------------------------------------------------ POC rule and accounting
def test_make_predictions_exact_mapping():
    bos = np.array([1, 1, 1, 1, -1, -1, -1, 0, 1, -1], np.int8)
    t1 = np.array([1, 1, 0, 1, -1, -1, 1, 1, 2, -1], np.int8)
    t4 = np.array([1, 0, 1, -1, -1, 0, -1, 1, 1, -1], np.int8)
    r = POC.make_predictions(bos, t1, t4)
    assert r["pred"].tolist() == [1, 0, 0, 0, -1, 0, 0, 0, 0, -1]
    assert r["pred_nohtf"].tolist() == [1, 1, 1, 1, -1, -1, -1, 0, 1, -1]


def test_accuracy_excludes_wait_and_flat_and_counts_excess():
    g = np.array([0.1, -0.1, 0.0, 0.2, -0.3, 0.5])
    pred = np.array([1, 1, 1, -1, -1, 0], np.int8)
    day = np.zeros(6, np.int64)
    a, ex, hh = POC.accuracy_block(g, pred, day)
    assert a["signals"] == 5 and a["flat"] == 1 and a["nonflat"] == 4 and a["hits"] == 2          # UP right, UP wrong, flat out, DOWN wrong, DOWN right
    assert a["accuracy"] == 0.5 and a["up_accuracy"] == 0.5 and a["down_accuracy"] == 0.5
    assert abs(ex[0, 1] - (2 - 0.5 * 4)) < 1e-12 and ex[0, 0] == 5


def test_forward_entry_is_next_open_and_window_edges():
    coin = synth_coin(20, 40, seed=4)
    b = coin.bars["15m"]
    g, elig = POC.forward(b, 4)
    i = int(np.nonzero(elig)[0][10])
    assert abs(g[i] - (b.c[i + 4] / b.o[i + 1] - 1) * 100) < 1e-9
    assert not elig[b.close_time < RD.WINDOW_LO].any() and not elig[b.close_time + 4 * 900_000 > RD.CUT].any()


def test_profit_accounting_and_chain():
    keep = POC.chain(np.array([0, 10, 20, 35]), np.array([15, 25, 40, 50]))
    assert keep.tolist() == [0, 2]                                         # 10 < 15 overlaps, 20 ok, 35 < 40 overlaps
    m = POC.metrics(np.array([0.3, -0.1, 0.2]), np.array([0.0, 0.01, -0.01]), 0.12, 0.02)
    net = np.array([0.3 - 0.12, -0.1 - 0.12 - 0.01, 0.2 - 0.12 + 0.01])
    assert abs(m["net"] - net.mean()) < 1e-12 and abs(m["pf"] - net[net > 0].sum() / -net[net <= 0].sum()) < 1e-12
    assert abs(POC.FEE_TOTAL + POC.SLIP_MEAS - 0.12) < 1e-12 and abs(POC.FEE_TOTAL + POC.SLIP_CODE - 0.14) < 1e-12


def test_poc_end_to_end_on_synthetic_coin_and_verdict_not_promising_without_edge():
    coin = synth_coin(150, 60, seed=21)
    R = POC.run_coin(coin, "TEST")
    res = POC.evaluate_items(R["_arrays"])
    assert len(res) == 15 and all("q" in r for r in res.values())
    ov = POC.overall(res)
    assert ov["verdict"] in ("PROMISING", "NOT PROMISING", "INCONCLUSIVE") and ov["verdict"] != "PROMISING"
    c = R["horizons"]["15m"]["RULE"]["coverage"]
    assert c["UP"] + c["DOWN"] + c["WAIT"] == c["eligible"]


# ------------------------------------------------------------------------------------------ holdout
def test_loader_refuses_cut_at_or_after_seal():
    with pytest.raises(ValueError):
        RD.check_cut(RD.CUT + 1)
    assert RD.CUT == 1_767_225_600_000                                      # 2026-01-01 00:00 UTC, earlier than the 2026-01-03 global seal
    assert RD.LAST_MONTH == "2025-12"
    assert max(f.name for f in RD.month_files(WORK / "data" / "BTCUSDT" / "5m")) <= "2025-12.parquet" if (WORK / "data" / "BTCUSDT" / "5m").exists() else True


def test_poc_module_cannot_build_a_coin_past_the_cut():
    with pytest.raises(ValueError):
        RD.build_coin(WORK, "BTCUSDT", cut=RD.CUT + 300_000)


# ------------------------------------------------------------------------------------------ real data (skipped if the local files are absent)
@pytest.fixture(scope="module")
def btc():
    if not (WORK / "data" / "BTCUSDT" / "5m" / "monthly").exists():
        pytest.skip("local BTC data absent")
    return RD.build_coin(WORK, "BTCUSDT", tfs=("15m", "1h", "4h"))


def test_real_btc_holdout_reads_zero_and_bar_quality(btc):
    for tf in ("15m", "1h", "4h"):
        b = btc.bars[tf]
        fin = np.isfinite(b.c)
        assert int(b.close_time[fin].max()) <= RD.CUT
        q = dataq.ohlc_problems(b.o, b.h, b.l, b.c, b.v)
        assert q["high_invalid"] == 0 and q["low_invalid"] == 0 and q["non_positive"] == 0 and q["negative_volume"] == 0
        assert (np.diff(b.open_time) == b.tf_ms).all()                  # strictly regular grid: no duplicates, no reordering


def test_real_btc_replay_prefix_invariance_for_the_poc_rule(btc):
    """Predictions for every 15m bar up to a cut equal the predictions computed from data that ends at the cut (all three timeframes truncated at the cut time)."""
    sig = POC.build_signals(btc)
    b15, b1, b4 = (btc.bars[t] for t in ("15m", "1h", "4h"))
    for frac in (0.35, 0.7):
        n15 = int(len(b15.c) * frac)
        T = int(b15.close_time[n15 - 1])
        pre = {}
        for tf, b in (("15m", b15), ("1h", b1), ("4h", b4)):
            m = int(np.searchsorted(b.close_time, T, side="right"))
            pre[tf] = S.run(b.o[:m], b.h[:m], b.l[:m], b.c[:m], P.PIVOT_K[tf]) | {"ct": b.close_time[:m]}
        t1 = htf_align(b15.close_time[:n15], pre["1h"]["ct"], pre["1h"]["trend"], fill=P.UNCERTAIN)
        t4 = htf_align(b15.close_time[:n15], pre["4h"]["ct"], pre["4h"]["trend"], fill=P.UNCERTAIN)
        p = POC.make_predictions(pre["15m"]["bos"][:n15], t1, t4)
        assert np.array_equal(p["pred"], sig["pred"][:n15]) and np.array_equal(p["pred_nohtf"], sig["pred_nohtf"][:n15])
        assert (p["pred"] != 0).sum() > 20


# ------------------------------------------------------------------------------------------ isolation
def test_isolation_manifest_no_protected_file_changed():
    mp = WORK / "experiment_f_v1" / "MANIFEST_BEFORE.json"
    if not mp.exists():
        pytest.skip("no baseline manifest")
    r = isolation.compare(json.loads(mp.read_text("utf-8")), isolation.snapshot())
    assert r["ok"], r
