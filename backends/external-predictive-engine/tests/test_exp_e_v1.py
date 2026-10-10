"""Experiment E v1: sealed-data loader, label maturity, fold chronology, calibrator window, look-ahead detection, target arithmetic, calibration metrics, buckets.
Plan: docs/ml-engine-v1/EXPERIMENT_E_V1.md."""

from pathlib import Path

import numpy as np
import pytest

from edge_search.core import Coin, outcomes_time
from quick_trade_replay_v1 import paths as P
from exp_e_v1 import data as D
from exp_e_v1 import job as J
from exp_e_v1 import leakage as LK
from exp_e_v1 import metrics as MT
from exp_e_v1 import targets as T

M5, H1, DAY = 300_000, 3_600_000, 86_400_000


# ------------------------------------------------------------------------------------------ sealed data
def test_check_cut_refuses_after_2026():
    D.check_cut(D.CUT)
    with pytest.raises(ValueError):
        D.check_cut(D.CUT + 1)


def test_refuse_path_2026_files():
    D.refuse_path(Path("2025-12.parquet"))
    for name in ("2026-01.parquet", "BTCUSDT-5m-2026-03-05.parquet", "2027-01.parquet"):
        with pytest.raises(ValueError):
            D.refuse_path(Path(name))


def test_month_files_never_returns_2026_daily_or_tail(tmp_path):
    (tmp_path / "monthly").mkdir()
    (tmp_path / "daily").mkdir()
    for n in ("2021-09", "2021-10", "2025-12", "2026-01", "2026-10"):
        (tmp_path / "monthly" / f"{n}.parquet").write_bytes(b"x")
    (tmp_path / "daily" / "2025-12-31.parquet").write_bytes(b"x")
    (tmp_path / "tail.parquet").write_bytes(b"x")
    names = [p.name for p in D.month_files(tmp_path)]
    assert names == ["2021-10.parquet", "2025-12.parquet"]
    assert [p.name for p in D.month_files(tmp_path, "2021-10", "2030-01")] == ["2021-10.parquet", "2025-12.parquet"]      # `last` is clamped


def test_load_klines_never_opens_a_2026_file(tmp_path, monkeypatch):
    base = tmp_path / "data" / "TESTUSDT" / "5m" / "monthly"
    base.mkdir(parents=True)
    for n in ("2025-11", "2025-12", "2026-01"):
        (base / f"{n}.parquet").write_bytes(b"x")
    opened = []

    def fake(path):
        opened.append(Path(path).name)
        t0 = D._ms(2025, 12, 1) if "2025-12" in str(path) else D._ms(2025, 11, 1)
        n = 288 * 30
        return {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": np.ones(n), "high": np.ones(n), "low": np.ones(n), "close": np.ones(n),
                "volume": np.ones(n), "close_time": t0 + np.arange(n, dtype=np.int64) * M5 + M5 - 1, "quote_volume": np.ones(n), "count": np.ones(n),
                "taker_buy_volume": np.ones(n), "taker_buy_quote_volume": np.ones(n)}
    monkeypatch.setattr(D.bd, "read_parquet", fake)
    out = D.load_klines(tmp_path, "TESTUSDT", "5m")
    assert "2026-01.parquet" not in opened
    assert int(out["open_time"].max()) + M5 <= D.CUT
    with pytest.raises(ValueError):
        D.load_klines(tmp_path, "TESTUSDT", "5m", cut=D.CUT + DAY)


def test_anchor_table_predicate_drops_rows_at_or_after_the_cut(tmp_path):
    import pyarrow as pa
    import pyarrow.parquet as pq
    a = D.CUT + np.arange(-5, 5, dtype=np.int64) * M5
    pq.write_table(pa.table({"anchor_time_ms": a, "x": np.arange(10.0)}), tmp_path / "f.parquet")
    out = D.read_anchor_table(tmp_path / "f.parquet")
    assert len(out["anchor_time_ms"]) == 5 and out["anchor_time_ms"].max() < D.CUT
    with pytest.raises(ValueError):
        D.read_anchor_table(tmp_path / "f.parquet", cut=D.CUT + M5)


# ------------------------------------------------------------------------------------------ maturity and fold chronology
def _anchors(y0=2021, y1=2026):
    return np.arange(D._ms(y0, 10), D._ms(y1, 1), M5, dtype=np.int64)


def test_mature_mask_excludes_unmatured_labels():
    a = np.array([100, 200, 300], np.int64)
    exit_t = a + 60
    assert LK.mature_mask(exit_t, 300, embargo=10).tolist() == [True, True, False]
    assert LK.mature_mask(exit_t, 269, embargo=10).tolist() == [True, False, False]


def test_fold_rows_are_chronological_and_mature():
    a = _anchors()
    universe = np.ones(len(a), bool)
    fit, cal, test = J.fold_rows(a, universe, 12 * M5, 2024)
    t_cal0, t_test0, t_test1 = J.windows_for(2024)
    assert a[fit].max() + 12 * M5 + LK.EMBARGO_MS <= t_cal0
    assert a[cal].min() >= t_cal0 and a[cal].max() + 12 * M5 + LK.EMBARGO_MS <= t_test0
    assert a[test].min() >= t_test0 and a[test].max() < t_test1
    # the embargo leaves an explicit gap between the windows
    assert t_cal0 - a[fit].max() >= LK.EMBARGO_MS and t_test0 - a[cal].max() >= LK.EMBARGO_MS
    assert len(np.intersect1d(fit, cal)) == 0 and len(np.intersect1d(cal, test)) == 0


def test_fold_check_rejects_overlapping_windows():
    a = _anchors()
    t_cal0, t_test0, _ = J.windows_for(2024)
    fit = np.nonzero(a < t_cal0)[0]                      # NOT purged: the last fit labels reach into the calibration window
    cal = np.nonzero((a >= t_cal0) & (a < t_test0 - LK.EMBARGO_MS - 12 * M5))[0]
    test = np.nonzero(a >= t_test0)[0]
    with pytest.raises(LK.LeakageError):
        LK.check_fold(a, fit, cal, test, 12 * M5, t_cal0, t_test0)
    # calibration rows into the test year are rejected as well
    fit2, cal2, _ = J.fold_rows(a, np.ones(len(a), bool), 12 * M5, 2024)
    with pytest.raises(LK.LeakageError):
        LK.check_fold(a, fit2, np.r_[cal2, test[:10]], test, 12 * M5, t_cal0, t_test0)
    # a test row before the test window
    with pytest.raises(LK.LeakageError):
        LK.check_fold(a, fit2, cal2, np.r_[cal2[-5:], test], 12 * M5, t_cal0, t_test0)


def test_calibrator_fit_on_the_evaluation_window_is_rejected():
    a = _anchors()
    t_cal0, t_test0, t_test1 = J.windows_for(2024)
    rng = np.random.default_rng(0)
    test = np.nonzero((a >= t_test0) & (a < t_test1))[0]
    raw, y = rng.random(len(test)), (rng.random(len(test)) < 0.5).astype(float)
    with pytest.raises(LK.LeakageError):
        MT.fit_calibrator_checked("platt", a, test, raw, y, t_cal0, t_test0, 12 * M5)
    _, cal, _ = J.fold_rows(a, np.ones(len(a), bool), 12 * M5, 2024)
    cal_raw, cal_y = rng.random(len(cal)), (rng.random(len(cal)) < 0.5).astype(float)
    assert MT.fit_calibrator_checked("platt", a, cal, cal_raw, cal_y, t_cal0, t_test0, 12 * M5).method == "platt"
    # rows of the calibration window whose labels are not yet mature at the test boundary are rejected too
    late = np.nonzero((a >= t_test0 - 3 * M5) & (a < t_test0))[0]
    with pytest.raises(LK.LeakageError):
        MT.fit_calibrator_checked("platt", a, np.r_[cal[:100], late], np.r_[cal_raw[:100], rng.random(len(late))], np.r_[cal_y[:100], np.zeros(len(late))],
                                  t_cal0, t_test0, 12 * M5)


# ------------------------------------------------------------------------------------------ planted look-ahead
def _causal(c):
    k = 5
    s = np.cumsum(np.r_[0.0, c])
    out = np.full(len(c), np.nan)
    out[k - 1:] = (s[k:] - s[:-k]) / k
    return out


def _leaky(c):
    out = np.full(len(c), np.nan)
    out[:-3] = c[3:] / c[:-3] - 1.0                      # the return of the NEXT three bars
    return out


def test_prefix_invariance_catches_planted_lookahead():
    c = 100 * np.exp(np.cumsum(np.random.default_rng(1).normal(0, 0.001, 600)))
    cuts = (100, 250, 400, 599)
    assert LK.prefix_invariant(_causal, (c,), cuts)
    assert not LK.prefix_invariant(_leaky, (c,), cuts)


def test_planted_lookahead_trips_the_too_good_guard():
    rng = np.random.default_rng(2)
    n = 40_000
    anchors = np.arange(n, dtype=np.int64) * M5
    c = 100 * np.exp(np.cumsum(rng.normal(0, 0.001, n)))
    fut = np.r_[c[12:] / c[:-12] - 1.0, np.zeros(12)]
    X = np.column_stack([rng.normal(size=n), np.r_[0.0, np.diff(c)] / c, fut])      # feature 3 = the realised future return: a planted leak
    y = (fut > 0).astype(float)
    fit = np.arange(0, 30_000)
    test = np.arange(30_000, n)
    b = J.fit_model(X, y, anchors, fit, "clf")
    a = MT.auc(y[test].astype(int), b.predict(X[test]))
    assert a > LK.TOO_GOOD_AUC
    with pytest.raises(LK.LeakageError):
        LK.too_good_guard(a, "planted")
    # without the leak the same recipe stays near 0.5 and the guard stays quiet
    b2 = J.fit_model(X[:, :2], y, anchors, fit, "clf")
    a2 = MT.auc(y[test].astype(int), b2.predict(X[test][:, :2]))
    assert a2 < 0.56
    LK.too_good_guard(a2, "clean")


# ------------------------------------------------------------------------------------------ targets
def flat_coin(n_days=2, price=100.0, plant=None, t0=None):
    t0 = D._ms(2023, 1, 1) if t0 is None else t0
    n = 288 * n_days
    o = np.full(n, price)
    h, l, c = o.copy(), o.copy(), o.copy()
    for i, (oo, hh, ll, cc) in (plant or {}).items():
        o[i], h[i], l[i], c[i] = oo, hh, ll, cc
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": np.full(n, 10.0), "taker_buy_volume": np.full(n, 5.0)}
    ft = np.array([t0 + 8 * H1, t0 + 16 * H1, t0 + 24 * H1], np.int64)
    return Coin("TESTUSDT", t0, n, k5, {"funding_time": ft, "funding_rate": np.full(3, 0.0001)}, None, ("5m", "15m"))


def test_barrier_and_terminal_targets_on_hand_built_bars():
    t0 = D._ms(2023, 1, 1)
    # events enter at bar index e0 = 10 (long TP), 40 (long SL), 70 (same-bar SL + TP), 100 (nothing touched, drift up), 130 (gap through the stop)
    plant = {12: (100, 101.2, 100, 101),                 # TP (+1 % R) reached on the 3rd bar of event 1 (index 10 + 2)
             42: (100, 100, 98.5, 99),                   # SL reached, event 2
             72: (100, 101.5, 98.5, 100),                # both in one bar: AMBIGUOUS -> stop
             104: (100, 100.4, 100, 100.3), 109: (100.3, 100.5, 100.3, 100.5), 121: (100.5, 100.5, 100.5, 100.5),
             132: (97, 97.5, 96.8, 97)}                  # gap open through the stop of event 5
    coin = flat_coin(plant=plant)
    e0 = np.array([10, 40, 70, 100, 130], np.int64)
    entry_t = t0 + e0 * M5
    Rfrac = np.full(5, 0.01)
    out = T.event_targets(coin, e0, entry_t, 12, Rfrac)
    assert out["ok"].all()
    assert out["rBL"].tolist()[:3] == [P.TP, P.SL, P.AMBIG]
    assert out["rBL"][3] == P.TIME and out["gBL"][3] == pytest.approx(out["gX1"][3])
    assert out["rBL"][4] == P.GAP
    assert out["gBL"][0] == pytest.approx(1.0) and out["gBL"][1] == pytest.approx(-1.0)
    # terminal gross = close of the last horizon bar relative to the entry open, in %
    assert out["gX1"][0] == pytest.approx((100.0 / 100.0 - 1) * 100)
    assert out["gX1"][3] == pytest.approx((100.5 / 100.0 - 1) * 100) or out["gX1"][3] == pytest.approx((100.0 / 100.0 - 1) * 100)


def test_expiry_gross_equals_the_replay_outcomes_time():
    rng = np.random.default_rng(5)
    n = 288 * 3
    t0 = D._ms(2023, 1, 1)
    c = 100 * np.exp(np.cumsum(rng.normal(0, 0.0008, n)))
    o = np.r_[100.0, c[:-1]]
    h, l = np.maximum(o, c) * 1.0004, np.minimum(o, c) * 0.9996
    k5 = {"open_time": t0 + np.arange(n, dtype=np.int64) * M5, "open": o, "high": h, "low": l, "close": c, "volume": np.full(n, 10.0), "taker_buy_volume": np.full(n, 5.0)}
    coin = Coin("TESTUSDT", t0, n, k5, {"funding_time": np.zeros(0, np.int64), "funding_rate": np.zeros(0)}, None, ("5m", "15m"))
    b = coin.bars["5m"]
    ref = outcomes_time(b, 12)
    sig_bar = np.arange(40, 400)                               # signal on bar sig_bar, entry at the open of the next bar
    e0 = sig_bar + 1
    out = T.event_targets(coin, e0, t0 + e0 * M5, 12, np.full(len(e0), 0.005))
    assert np.allclose(out["gX1"], ref.g_long[sig_bar], equal_nan=True)


def test_q_labels_cost_funding_and_ties():
    ev = {"gX1_P1": np.array([0.30, -0.30, 0.0, 0.10]), "fX1_P1": np.array([0.01, 0.01, 0.0, 0.0]),
          "gBL_P1": np.array([1.0, -1.0, np.nan, 0.0]), "gBS_P1": np.array([-1.0, 1.0, np.nan, 0.0]),
          "rBL_P1": np.array([2, 1, -1, 0], np.int8), "rBS_P1": np.array([1, 2, -1, 0], np.int8)}
    L = J.q_labels(ev, "P1")
    assert np.isnan(L["yA"][2]) and L["yA"].tolist()[:2] == [1.0, 0.0] and L["yA"][3] == 1.0
    assert L["netL"][0] == pytest.approx(0.30 - 0.12 - 0.01) and L["netS"][0] == pytest.approx(-0.30 - 0.12 + 0.01)
    assert L["yCL"].tolist()[0] == 1.0 and L["yCL"][3] == 0.0 and L["yCS"][1] == 1.0
    assert L["yBL"].tolist()[:2] == [1.0, 0.0] and np.isnan(L["yBL"][2]) and L["yBS"][1] == 1.0


# ------------------------------------------------------------------------------------------ metrics
def test_logistic_fit_recovers_planted_parameters():
    rng = np.random.default_rng(3)
    x = rng.normal(size=200_000)
    p = MT.expit(-0.3 + 0.7 * x)
    y = (rng.random(len(x)) < p).astype(float)
    a, b = MT.fit_logistic(x, y)
    assert abs(a + 0.3) < 0.03 and abs(b - 0.7) < 0.03


def test_platt_repairs_a_planted_miscalibration_and_calibrated_input_stays_calibrated():
    rng = np.random.default_rng(4)
    n = 120_000
    true_p = rng.uniform(0.3, 0.7, n)
    y = (rng.random(n) < true_p).astype(float)
    raw = MT.expit(2.0 * MT.logit(true_p) + 0.4)             # over-confident and shifted
    cal, test = slice(0, 60_000), slice(60_000, n)
    cal_p = MT.Calibrator("platt").fit(raw[cal], y[cal]).predict(raw[test])
    m_raw, m_cal = MT.point_metrics(raw[test], y[test]), MT.point_metrics(cal_p, y[test])
    assert m_cal["brier"] < m_raw["brier"] and m_cal["ece_mass"] < 0.01 < m_raw["ece_mass"]
    assert abs(m_cal["slope"] - 1) < 0.05 and abs(m_cal["intercept"]) < 0.05
    good = MT.point_metrics(true_p[test], y[test])
    assert abs(good["slope"] - 1) < 0.05 and good["ece_mass"] < 0.01
    # isotonic is monotone and clipped; base-rate and 0.5 calibrators are constants
    iso = MT.Calibrator("iso").fit(raw[cal], y[cal]).predict(np.sort(raw[test]))
    assert (np.diff(iso) >= -1e-12).all()
    assert np.allclose(MT.Calibrator("base").fit(raw[cal], y[cal]).predict(raw[:5]), y[cal].mean())
    assert np.allclose(MT.Calibrator("c50").fit(raw[cal], y[cal]).predict(raw[:5]), 0.5)


def test_brier_logloss_ece_formulas():
    p = np.array([0.2, 0.8, 0.5, 0.5])
    y = np.array([0.0, 1.0, 1.0, 0.0])
    m = MT.point_metrics(p, y)
    assert m["brier"] == pytest.approx((0.04 + 0.04 + 0.25 + 0.25) / 4)
    assert m["logloss"] == pytest.approx(-(np.log(0.8) + np.log(0.8) + np.log(0.5) * 2) / 4)
    assert MT.ece_mass(np.array([0.1, 0.1, 0.9, 0.9]), np.array([0.0, 0.0, 1.0, 1.0]), bins=2) == pytest.approx(0.1)
    assert m["citl"] == pytest.approx(0.5 - 0.5)


def test_binned_bootstrap_interval_contains_the_planted_slope():
    rng = np.random.default_rng(6)
    n, nd = 80_000, 400
    day = rng.integers(0, nd, n)
    p = rng.uniform(0.2, 0.8, n)
    y = (rng.random(n) < MT.expit(0.8 * MT.logit(p))).astype(float)
    bs = MT.BinStats(p, y, day, nd)
    ci = MT.binned_cal_ci(bs, "t", B=300)
    assert ci["slope_ci90"][0] < 0.8 < ci["slope_ci90"][1]
    assert ci["ece_mass_ci90"][0] >= 0


def test_bucket_edges_are_the_pre_registered_ones():
    conf = np.array([0.49, 0.50, 0.599, 0.60, 0.699, 0.70, 0.749, 0.75, 0.80, 0.899, 0.90, 1.0])
    assert MT.bucket_of(conf).tolist() == [-1, 0, 0, 1, 1, 2, 2, 3, 4, 4, 5, 5]
    assert MT.BUCKET_NAMES == ("50-60", "60-70", "70-75", "75-80", "80-90", "90-100")


def test_effective_n_chain_and_fdr():
    anchor = np.array([0, M5, 2 * M5, 12 * M5, 13 * M5], np.int64)
    coin = np.zeros(5, np.int64)
    assert MT.effective_n(coin, anchor, 12 * M5) == 2
    assert MT.effective_n(np.array([0, 0, 1, 1, 1]), anchor, 12 * M5) == 3
    side = np.array([1, 1, -1, 1, 1])
    exit_t = anchor + 12 * M5
    assert MT.greedy_chain(anchor, side, exit_t).tolist() == [0, 3]
    q = MT.bh([0.001, 0.01, 0.04, 0.5])
    assert (q >= np.array([0.001, 0.01, 0.04, 0.5])).all() and q[-1] == pytest.approx(0.5)


def test_effect_stats_detects_a_planted_effect_and_not_noise():
    rng = np.random.default_rng(7)
    nd, per = 300, 50
    day = np.repeat(np.arange(nd), per)
    noise = rng.normal(0, 0.5, nd * per)
    null = MT.effect_stats(MT.day_sums(day, nd, noise)[:, 0], np.bincount(day, minlength=nd).astype(float), "null")
    planted = MT.effect_stats(MT.day_sums(day, nd, noise + 0.08)[:, 0], np.bincount(day, minlength=nd).astype(float), "planted")
    assert null["p"] > 0.01 and planted["p"] < 0.001 and planted["ci90"][0] > 0
    assert planted["mde"] < 0.06


# ------------------------------------------------------------------------------------------ real data (skipped when the local data is absent)
WORK = Path(r"D:\autofinx-ml-v4")


@pytest.mark.skipif(not (WORK / "features" / "BTCUSDT" / "v3.parquet").exists(), reason="local V3 features not available")
def test_v3_feature_anchor_is_the_close_of_the_bar_that_opened_one_bar_earlier():
    from v4 import binance_data as bd
    f = bd.read_parquet(WORK / "data" / "BTCUSDT" / "5m" / "monthly" / "2022-06.parquet")
    ot, c = f["open_time"], f["close"]
    tab = D.read_anchor_table(WORK / "features" / "BTCUSDT" / "v3.parquet", columns=["ret_1"])
    a = tab["anchor_time_ms"]
    sel = np.nonzero((a >= int(ot[10])) & (a < int(ot[10]) + 50 * M5))[0]
    idx = {int(t): i for i, t in enumerate(ot)}
    err = {-1: [], 0: [], 1: []}
    for k in sel:
        for off in err:                                   # off 0 = the bar that CLOSES at the anchor (open_time = anchor - 5m)
            j = idx[int(a[k]) - M5 + off * M5]
            err[off].append(abs(tab["ret_1"][k] - (c[j] / c[j - 1] - 1)))
    med = {o: float(np.median(v)) for o, v in err.items()}
    assert med[0] < 2e-5 and med[0] * 20 < med[-1] and med[0] * 20 < med[1]     # aligned at offset 0; the neighbouring bars match far worse (no look-ahead)
    assert int(a.max()) < D.CUT


@pytest.mark.skipif(not (WORK / "exp_e_v1" / "events" / "BTCUSDT.npz").exists(), reason="event table not built yet")
def test_event_table_contains_nothing_at_or_after_the_cut():
    ev = np.load(WORK / "exp_e_v1" / "events" / "BTCUSDT.npz")
    assert int(ev["anchor"].max()) < D.CUT
    ok = ev["ok_P1"]
    assert int((ev["anchor"][ok] + 12 * M5).max()) <= D.CUT


# ------------------------------------------------------------------------------------------ analysis: planted edge is found, noise is not
def synth_ts(edge, n=60_000, seed=11):
    from exp_e_v1 import analysis as A
    rng = np.random.default_rng(seed)
    coin = rng.integers(0, 3, n).astype(np.int8)
    year = (2023 + rng.integers(0, 3, n)).astype(np.int16)
    anchor = np.array([D._ms(int(y), 1, 1) for y in year], np.int64) + rng.integers(0, 360 * DAY // M5, n) * M5
    order = np.lexsort((anchor, coin))
    coin, year, anchor = coin[order], year[order], anchor[order]
    z = rng.normal(size=n)
    gross = edge * z + rng.normal(0, 0.4, n)
    ts = {"coin": coin, "year": year, "anchor": anchor, "day": ((anchor - D.TEST_LO) // DAY).astype(np.int64), "gX1": gross, "netL": gross - 0.12, "netS": -gross - 0.12,
          "H_ms": 12 * M5, "P": "P1", "sigB": np.zeros(n, np.int8), "sigC": np.zeros(n, np.int8), "M": np.zeros(n)}
    ev_l, ev_s = 0.2 * z - 0.12, -0.2 * z - 0.12                       # the model's predicted net EV is driven by z, which is the true signal only when edge > 0
    return A, ts, ev_l, ev_s


def test_planted_edge_is_found_by_the_economics_machinery():
    A, ts, evl, evs = synth_ts(edge=0.2)
    side = A.decide(evl, evs)
    it = A.policy_item(ts, "Q-EVp", side, "t|planted")
    A.family([it], "econ")
    assert it["trades"] > 5_000 and it["effect"] > 0.1 and it["verdict"] == "LEAD" and it["chain"]["pf"] > 1.15
    rnd = A.paired_item(ts, "Q-EVp", side, "random", {}, "t|planted|rnd")
    A.family([rnd], "econ")
    assert rnd["verdict"] == "LEAD"


def test_noise_is_not_reported_as_a_lead_and_is_an_adequately_powered_fail():
    A, ts, evl, evs = synth_ts(edge=0.0)
    side = A.decide(evl, evs)
    it = A.policy_item(ts, "Q-EVp", side, "t|null")
    A.family([it], "econ")
    assert it["effect"] < -0.08 and it["verdict"] == "FAIL"
    sd = A.policy_item(ts, "Q-SIDE", A.decide(evl, evs, "SIDE"), "t|null|side")
    A.family([sd], "econ")
    assert sd["verdict"] == "FAIL" and sd["trades"] == len(ts["anchor"])


def test_calibration_skill_item_detects_information_and_rejects_noise():
    from exp_e_v1 import analysis as A
    rng = np.random.default_rng(12)
    n = 80_000
    coin = rng.integers(0, 3, n).astype(np.int8)
    year = (2023 + rng.integers(0, 3, n)).astype(np.int16)
    anchor = np.array([D._ms(int(y), 1, 1) for y in year], np.int64) + rng.integers(0, 360 * DAY // M5, n) * M5
    day = ((anchor - D.TEST_LO) // DAY).astype(np.int64)
    z = rng.normal(size=n)
    y = (rng.random(n) < MT.expit(0.6 * z)).astype(float)
    base = np.full(n, y.mean(), np.float32)
    informative = MT.expit(0.6 * z).astype(np.float32)
    noise = MT.expit(0.6 * rng.normal(size=n)).astype(np.float32)
    ts = {"coin": coin, "year": year, "anchor": anchor, "day": day, "yA": y,
          "prob": {("M", "A", "platt"): informative, ("M", "A", "base"): base, ("N", "A", "platt"): noise, ("N", "A", "base"): base}}
    items = A.f1_items(ts, ["M", "N"], lambda m: ("A",), ("platt",), "P1")
    A.family(items, "f1")
    by = {i["model"]: i for i in items}
    assert by["M"]["verdict"] == "LEAD" and by["N"]["verdict"] != "LEAD" and by["N"]["effect"] < 0


def test_f3_rule_needs_only_q_ci_and_materiality():
    from exp_e_v1 import analysis as A
    r = {"effect": 0.05, "se": 0.01, "mde": 0.08, "ci90": [0.03, 0.07], "year": {2023: -1.0, 2024: -1.0, 2025: 0.2}, "coin": {"a": -1.0, "b": -1.0, "c": 0.1}}
    assert A.verdict_item(r, 0.01, "f3") == "LEAD"                       # F3: q < 0.05, CI lower > 0, effect >= 0.03 (plan section 9)
    assert A.verdict_item(r, 0.01, "econ") != "LEAD"                      # F2 additionally needs the year / coin signs and MDE <= 0.06
    assert A.verdict_item({**r, "effect": 0.02, "ci90": [0.01, 0.03]}, 0.01, "f3") == "INCONCLUSIVE"
    assert A.verdict_item({**r, "effect": -0.05, "ci90": [-0.07, -0.01], "mde": 0.04}, 0.9, "f3") == "FAIL"
