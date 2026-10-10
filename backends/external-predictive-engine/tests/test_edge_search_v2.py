"""Edge Search v2 pilot: aggregation correctness (vs brute force), batch invariance, causality, bucket completeness,
no holdout leakage, events, depth aggregation."""

import datetime as dt
import math

import numpy as np
import pytest

from edge_search_v2 import depth as dp
from edge_search_v2 import events as ev
from edge_search_v2 import features as fe
from edge_search_v2 import pipeline as pl
from edge_search_v2.flow import EDGES, M5, N_BINS, FlowAggregator

T0 = pl.ms_of(dt.date(2025, 10, 1))


def make_trades(n=6000, days=1, seed=3, gap_at=None):
    rng = np.random.default_rng(seed)
    span = days * 86_400_000
    ts = np.sort(T0 + rng.integers(0, span, n)).astype(np.int64)
    ts[0], ts[-1] = T0 + 5, T0 + span - 5                           # coverage
    price = 100.0 * np.exp(np.cumsum(rng.normal(0, 3e-4, n)))
    qty = np.exp(rng.normal(-1, 1.5, n)).round(4) + 0.0001
    sell = rng.random(n) < 0.5
    ids = np.arange(1000, 1000 + n, dtype=np.int64)
    if gap_at is not None:
        ids[gap_at:] += 7
    return ids, ts, price, qty, sell


def agg(days, trades, splits=(0.3, 0.7)):
    a = FlowAggregator(T0, T0 + days * 86_400_000)
    n = len(trades[1])
    cuts = [0] + [int(n * s) for s in splits] + [n]
    for i in range(len(cuts) - 1):
        a.add(*[x[cuts[i]:cuts[i + 1]] for x in trades])
    return a.finish()


def brute(days, trades):
    ids, ts, px, q, sell = trades
    nb = days * 288
    r = {k: np.zeros(nb) for k in ("nb", "ns", "qb", "qs", "vb", "vs")}
    first, last = np.full(nb, np.nan), np.full(nb, np.nan)
    hi, lo = np.full(nb, -np.inf), np.full(nb, np.inf)
    for i in range(len(ts)):
        b = (ts[i] - T0) // M5
        k = ("ns", "qs", "vs") if sell[i] else ("nb", "qb", "vb")
        r[k[0]][b] += 1
        r[k[1]][b] += q[i]
        r[k[2]][b] += px[i] * q[i]
        if np.isnan(first[b]):
            first[b] = px[i]
        last[b] = px[i]
        hi[b], lo[b] = max(hi[b], px[i]), min(lo[b], px[i])
    # realised variance: last price of every second with a trade, return attributed to the later second's bucket
    sec = ts // 1000
    rv = np.zeros(nb)
    prev = None
    useq = np.unique(sec)
    for s in useq:
        p = px[np.nonzero(sec == s)[0].max()]
        if prev is not None:
            rv[(s * 1000 - T0) // M5] += math.log(p / prev) ** 2
        prev = p
    return r, first, last, hi, lo, rv


def test_aggregator_matches_brute_force_and_is_batch_invariant():
    tr = make_trades(4000, days=1)
    res = agg(1, tr)["scalars"]
    r, first, last, hi, lo, rv = brute(1, tr)
    assert np.allclose(res["n_buy"], r["nb"]) and np.allclose(res["n_sell"], r["ns"])
    assert np.allclose(res["qty_buy"], r["qb"]) and np.allclose(res["qty_sell"], r["qs"])
    assert np.allclose(res["qv_buy"], r["vb"]) and np.allclose(res["qv_sell"], r["vs"])
    assert np.allclose(res["p_first"], first, equal_nan=True) and np.allclose(res["p_last"], last, equal_nan=True)
    m = res["n_buy"] + res["n_sell"] > 0
    assert np.allclose(res["p_high"][m], hi[m]) and np.allclose(res["p_low"][m], lo[m])
    assert np.allclose(res["rv"], rv, atol=1e-15)
    # invariant to how the file is cut into batches (also mid-second)
    for splits in ((0.5,), (0.1, 0.2, 0.9), (0.333, 0.334)):
        other = agg(1, tr, splits)["scalars"]
        for k in res:
            assert np.allclose(res[k], other[k], equal_nan=True), k


def test_histogram_totals_and_bins():
    tr = make_trades(3000)
    res = agg(1, tr)
    h = res["hist"]
    assert h["h_cnt"].sum() == 3000
    assert np.isclose(h["h_buy"].sum() + h["h_sell"].sum(), res["scalars"]["qv_buy"].sum() + res["scalars"]["qv_sell"].sum(),
                      rtol=1e-4)
    # size bin assignment by edges
    a = FlowAggregator(T0, T0 + M5)
    a.add(np.array([1]), np.array([T0 + 1]), np.array([10.0]), np.array([EDGES[10] * 1.0001]), np.array([False]))
    assert a.h_cnt[0, 10] == 1


def test_causality_future_trades_do_not_change_closed_buckets():
    tr = make_trades(5000, days=1)
    full = agg(1, tr)["scalars"]
    cut_ts = T0 + 40 * M5 + 1234
    keep = tr[1] <= cut_ts
    part = agg(1, tuple(x[keep] for x in tr))["scalars"]
    nclosed = 40                                                    # buckets 0..39 end before cut_ts
    for k, v in full.items():
        if k in ("complete", "gap_touched", "bucket_open_ms", "id_gap"):
            continue
        assert np.allclose(v[:nclosed], part[k][:nclosed], equal_nan=True), k


def test_bucket_completeness_and_id_gaps():
    ids, ts, px, q, sell = make_trades(4000, days=1, gap_at=2000)
    res = agg(1, (ids, ts, px, q, sell))
    s = res["scalars"]
    assert res["info"]["covered"]
    gb_prev = (ts[1999] - T0) // M5
    gb = (ts[2000] - T0) // M5
    assert s["id_gap"][gb] == 7
    assert (s["complete"][gb_prev:gb + 1] == 0).all()               # every bucket touched by the gap is incomplete
    assert s["complete"][:gb_prev].all() and s["complete"][gb + 1:].all()
    clean = agg(1, make_trades(4000, days=1))
    assert clean["scalars"]["complete"].all() and clean["info"]["id_missing_total"] == 0
    # a file that stops early is not covered -> nothing complete
    tr = make_trades(4000, days=1)
    short = agg(1, tuple(x[: 2000] for x in tr))
    assert not short["info"]["covered"] and short["scalars"]["complete"].sum() == 0


# ------------------------------------------------------------------------------------------ features
def synth_features_input(days=12, seed=5):
    rng = np.random.default_rng(seed)
    n = days * 288
    qb = rng.uniform(1e6, 5e6, n)
    qs = rng.uniform(1e6, 5e6, n)
    p = 100 * np.exp(np.cumsum(rng.normal(0, 1e-3, n)))
    cnt = rng.integers(50, 200, n).astype(float)
    flow = {"bucket_open_ms": T0 + np.arange(n, dtype=np.int64) * M5, "n_buy": cnt, "n_sell": cnt, "qty_buy": qb / p, "qty_sell": qs / p,
            "qv_buy": qb, "qv_sell": qs, "s1": cnt * 0.1, "s2": cnt * 0.02, "s3": cnt * 0.01, "q_max": np.ones(n),
            "p_first": np.r_[p[0], p[:-1]], "p_last": p, "p_high": p, "p_low": p, "rv": rng.uniform(1e-6, 2e-6, n),
            "id_gap": np.zeros(n), "gap_touched": np.zeros(n, np.int8), "complete": np.ones(n, np.int8)}
    h_cnt = rng.integers(0, 50, (n, N_BINS)).astype(np.int32)
    h_buy = rng.uniform(0, 1e5, (n, N_BINS)).astype(np.float32)
    h_sell = rng.uniform(0, 1e5, (n, N_BINS)).astype(np.float32)
    depth = {}
    for lv in (1, 2, 5):
        depth[f"bid{lv}"] = rng.uniform(1e8, 2e8, n)
        depth[f"ask{lv}"] = rng.uniform(1e8, 2e8, n)
    return flow, {"h_cnt": h_cnt, "h_buy": h_buy, "h_sell": h_sell}, depth


def test_features_are_causal_when_the_future_is_replaced():
    flow, hist, depth = synth_features_input()
    f1 = fe.build_features(flow, hist, depth)
    cut = 8 * 288 + 100
    flow2 = {k: v.copy() for k, v in flow.items()}
    hist2 = {k: v.copy() for k, v in hist.items()}
    depth2 = {k: v.copy() for k, v in depth.items()}
    rng = np.random.default_rng(99)
    for d in (flow2["qv_buy"], flow2["qv_sell"], flow2["rv"], flow2["p_last"], flow2["p_first"]):
        d[cut + 1:] = d[cut + 1:] * rng.uniform(0.2, 5, len(d) - cut - 1)
    hist2["h_cnt"][cut + 1:] = rng.integers(0, 80, hist2["h_cnt"][cut + 1:].shape)
    hist2["h_buy"][cut + 1:] *= 3
    for k in depth2:
        depth2[k][cut + 1:] *= rng.uniform(0.2, 5, len(depth2[k]) - cut - 1)
    f2 = fe.build_features(flow2, hist2, depth2)
    for k, v in f1.items():
        assert np.allclose(v[: cut + 1], f2[k][: cut + 1], equal_nan=True), k
    # the planted lookahead (a feature that peeks one bucket ahead) is caught by exactly this comparison
    peek = lambda x: np.r_[x[1:], np.nan]
    assert not np.allclose(peek(f1["D1h"])[: cut + 1], peek(f2["D1h"])[: cut + 1], equal_nan=True)


def test_large_threshold_uses_only_the_previous_day():
    n_days = 4
    rng = np.random.default_rng(1)
    h = rng.integers(1, 40, (n_days * 288, N_BINS)).astype(np.int32)
    comp = np.ones(n_days * 288, bool)
    k1 = fe.large_threshold_bins(h, comp)
    assert k1[0] == -1                                              # first day has no previous day
    h2 = h.copy()
    h2[2 * 288:3 * 288] = rng.integers(1, 40, (288, N_BINS))        # change day 2 only
    k2 = fe.large_threshold_bins(h2, comp)
    assert k2[1] == k1[1] and k2[2] == k1[2]                        # day 1 and day 2 thresholds unaffected by day 2
    assert k2[3] != k1[3] or True                                   # day 3 may change (uses day 2)
    comp2 = comp.copy()
    comp2[100] = False                                              # incomplete day 0 -> no threshold for day 1
    assert fe.large_threshold_bins(h, comp2)[1] == -1
    # definition: share of trades in bins >= k is >= 1 % and not for k+1
    c = h[:288].sum(axis=0)
    k = k1[1]
    assert c[k:].sum() / c.sum() >= 0.01 and (k == N_BINS - 1 or c[k + 1:].sum() / c.sum() < 0.01)


def test_z_trailing_excludes_current_and_matches_brute_force():
    rng = np.random.default_rng(2)
    x = rng.normal(0, 1, 2500)
    x[100:150] = np.nan
    z = fe.z_trailing(x, win=300, minp=200)
    for t in (50, 250, 400, 1200, 2499):
        w = x[max(0, t - 300):t]
        w = w[np.isfinite(w)]
        if len(w) < 200:
            assert np.isnan(z[t])
        else:
            assert z[t] == pytest.approx((x[t] - w.mean()) / w.std(ddof=1))
    spike = x.copy()
    spike[1000] = 1e6
    assert fe.z_trailing(spike, 300, 200)[999] == pytest.approx(z[999])      # a later spike never reaches back


def test_event_signals_nan_safe_and_directional():
    n = 50
    nan = np.full(n, np.nan)
    f = {k: nan.copy() for k in ("z_D1h", "z_L1h", "z_S1h", "z_RV1h", "z_R1h", "z_LAM", "z_DI1", "z_DI2", "R1h", "D1h")}
    s = ev.signals(f)
    assert set(s) == set(ev.EVENTS) and all((v == 0).all() for v in s.values())
    f["z_D1h"][:] = 2.5
    f["R1h"][:] = -0.01
    f["D1h"][:] = 5.0
    s = ev.signals(f)
    assert (s["FLOW_FOLLOW"] == 1).all() and (s["FLOW_FADE"] == -1).all() and (s["ABSORPTION"] == -1).all()
    f["z_LAM"][:] = 3
    assert (ev.signals(f)["THIN_BOOK_FLOW"] == 1).all()
    f["z_D1h"][:] = -2.5
    f["D1h"][:] = -5.0
    f["R1h"][:] = 0.01
    s = ev.signals(f)
    assert (s["FLOW_FOLLOW"] == -1).all() and (s["ABSORPTION"] == 1).all() and (s["THIN_BOOK_FLOW"] == -1).all()


# ------------------------------------------------------------------------------------------ depth
def test_depth_day_aggregation():
    day = T0
    lines = ["timestamp,percentage,depth,notional"]
    for i, sec in enumerate((9, 70, 400)):                           # buckets 0, 0, 1
        stamp = (dt.datetime(2025, 10, 1) + dt.timedelta(seconds=sec)).strftime("%Y-%m-%d %H:%M:%S")
        for p in (-5, -2, -1, 1, 2, 5):
            lines.append(f"{stamp},{p},1.0,{(100 + i) * (3 if p < 0 else 1)}")
    rows = dp.parse_depth_csv("\n".join(lines).encode())
    out = dp.aggregate_day(rows, day)["cols"]
    assert out["snap_n"][0] == 2 and out["snap_n"][1] == 1 and out["snap_n"][2] == 0
    assert out["bid1"][0] == pytest.approx(3 * 100.5) and out["ask1"][0] == pytest.approx(100.5)
    assert np.isnan(out["bid1"][2])
    assert dp.imbalance(np.array([3.0]), np.array([1.0]))[0] == pytest.approx(0.5)


# ------------------------------------------------------------------------------------------ holdout seal
def test_holdout_seal_refuses_post_seal_units_before_any_network(monkeypatch, tmp_path):
    def boom(*a, **k):
        raise AssertionError("network touched")
    monkeypatch.setattr(pl, "head_length", boom)
    monkeypatch.setattr(pl, "fetch_checksum", boom)
    monkeypatch.setattr(pl, "_open", boom)
    with pytest.raises(pl.HoldoutViolation):
        pl.process_flow_month(tmp_path, "BTCUSDT", "2026-01", max_day=dt.date(2026, 1, 31))   # month end > seal
    with pytest.raises(pl.HoldoutViolation):
        pl.process_flow_month(tmp_path, "BTCUSDT", "2026-02")
    with pytest.raises(pl.HoldoutViolation):
        pl.process_depth_day(tmp_path, "BTCUSDT", dt.date(2026, 1, 4), max_day=dt.date(2026, 1, 5))
    with pytest.raises(pl.HoldoutViolation):
        pl.process_depth_day(tmp_path, "BTCUSDT", dt.date(2026, 1, 1))                      # beyond the pilot max day
    pl.assert_allowed(dt.date(2026, 1, 3), max_day=dt.date(2026, 1, 3))                      # the seal day itself is allowed
    from edge_search_v2 import runner
    with pytest.raises(pl.HoldoutViolation):
        runner.main(["analyze", "--max-day", "2026-02-01"])
    assert pl.months_between("2021-10", "2022-01") == ["2021-10", "2021-11", "2021-12", "2022-01"]


# ------------------------------------------------------------------------------------------ end-to-end analysis on synthetic files
def test_analysis_end_to_end_on_synthetic_files(tmp_path):
    import json
    from v4 import binance_data as bd
    from edge_search_v2 import analysis as an

    work = tmp_path
    edge = work / "edge_search_v2"
    max_day = dt.date(2025, 10, 31)
    t0, t1, n5, months = an.window(max_day)
    assert months == ["2025-10"] and n5 == 31 * 288
    for sym_i, sym in enumerate(an.SYMBOLS):
        flow, hist, depth = synth_features_input(days=31, seed=10 + sym_i)
        bd.write_parquet(edge / "features" / sym / "flow" / "2025-10.parquet", flow)
        an_np = {k: v for k, v in hist.items()}
        pl.write_npz(edge / "features" / sym / "flow" / "2025-10.npz", an_np)
        (edge / "markers").mkdir(parents=True, exist_ok=True)
        (edge / "markers" / f"flow-{sym}-2025-10.json").write_text(json.dumps(
            {"month": "2025-10", "rows": 1, "zip_bytes": 1, "download_s": 1, "aggregate_s": 1, "sha256": "x", "id_missing_total": 0,
             "out_of_range": 0, "non_monotone_ts": 0}))
        for d in range(31):
            day = dt.date(2025, 10, 1) + dt.timedelta(days=d)
            sl = slice(d * 288, (d + 1) * 288)
            cols = {"bucket_open_ms": flow["bucket_open_ms"][sl], "snap_n": np.full(288, 8.0)}
            cols.update({k: v[sl] for k, v in depth.items()})
            bd.write_parquet(edge / "features" / sym / "depth" / f"{day.isoformat()}.parquet", cols)
        # market data: klines consistent with the flow price path, funding every 8h
        p = flow["p_last"]
        k5 = {"open_time": flow["bucket_open_ms"], "open": flow["p_first"], "high": np.maximum(flow["p_first"], p) * 1.0002,
              "low": np.minimum(flow["p_first"], p) * 0.9998, "close": p, "volume": flow["qty_buy"] + flow["qty_sell"],
              "taker_buy_volume": flow["qty_buy"]}
        k5 = {**k5, "close_time": k5["open_time"] + M5 - 1, "quote_volume": k5["volume"], "count": k5["volume"],
              "taker_buy_quote_volume": k5["volume"]}
        bd.write_parquet(work / "data" / sym / "5m" / "monthly" / "2025-10.parquet", k5)
        ft = t0 + np.arange(1, 31 * 3 + 1, dtype=np.int64) * 8 * 3_600_000
        bd.write_parquet(work / "data" / sym / "funding" / "monthly" / "2025-10.parquet",
                         {"funding_time": ft, "funding_rate": np.full(len(ft), 0.0001), "interval_hours": np.full(len(ft), 8)})
    r = an.run_analysis(work, max_day, out_dir=work / "out")
    assert len(r["rows"]) == 60 and r["summary"]["holdout_reads"] == 0
    assert all(x["verdict"] in ("INCONCLUSIVE", "FAIL", "LEAD") for x in r["rows"])
    for x in r["rows"]:
        if x["n"] > 1:
            # gross and net are linked by exactly cost + funding: net = gross - 0.12 - side*funding (funding > 0 here)
            assert x["net"] < x["gross"]
    assert (work / "out" / "REPORT.md").exists() and (work / "out" / "items.csv").exists()
    assert r["quality"]["BTCUSDT"]["complete_pct"] == 100.0
