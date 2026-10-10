"""P4 context features: only information published at or before T0 is used."""

import numpy as np

from v4 import p4_context as cx

M = 60_000


def test_funding_uses_only_published_events():
    funding = {"funding_time": np.array([0, 480, 960], np.int64) * M, "funding_rate": np.array([0.0001, 0.0002, -0.0003]),
               "interval_hours": np.array([8, 8, 8])}
    anchors = np.array([479, 480, 959, 1000], np.int64) * M
    f = cx.funding_features(anchors, funding)
    assert np.allclose(f["ctx_funding_last"], [0.01, 0.02, 0.02, -0.03])
    assert np.allclose(f["ctx_funding_min_to_next"], [1, 480, 1, 440])


def test_metrics_lag_and_no_stale_carry():
    t = (np.arange(400, dtype=np.int64) * 5) * M
    m = {"create_time": t, "sum_open_interest_value": np.linspace(100, 200, 400),
         "count_toptrader_long_short_ratio": np.arange(400, dtype=float), "sum_toptrader_long_short_ratio": np.ones(400),
         "count_long_short_ratio": np.ones(400), "sum_taker_long_short_vol_ratio": np.full(400, 1.1)}
    anchor = np.array([100 * 5 * M], np.int64)                  # exactly at snapshot 100's create_time
    f = cx.metrics_features(anchor, m)
    assert f["ctx_top_ls_count"][0] == 99.0                     # snapshot 100 is not yet usable (lag 5 min)
    far = np.array([(399 * 5 + 30) * M], np.int64)              # 30 min after the last snapshot: archive gap
    assert np.isnan(cx.metrics_features(far, m)["ctx_top_ls_count"][0])


def test_metrics_future_values_do_not_leak():
    t = (np.arange(400, dtype=np.int64) * 5) * M
    base = {"create_time": t, "sum_open_interest_value": np.linspace(100, 200, 400),
            "count_toptrader_long_short_ratio": np.ones(400), "sum_toptrader_long_short_ratio": np.ones(400),
            "count_long_short_ratio": np.ones(400), "sum_taker_long_short_vol_ratio": np.full(400, 1.1)}
    late = {k: v.copy() for k, v in base.items()}
    late["sum_open_interest_value"][300:] *= 3
    anchors = (np.arange(50, 300, dtype=np.int64) * 5) * M
    a, b = cx.metrics_features(anchors, base), cx.metrics_features(anchors, late)
    for k in a:
        assert np.allclose(a[k], b[k], equal_nan=True), k
