"""P2 Layer A tests: exact definitions, gaps (G), entry timing (J), target side of leakage (K),
funding once (H/I), and the vectorized generator against a slow reference implementation."""

import math
import random
import sys
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import targets as tg  # noqa: E402

MIN = 60_000
T0 = 1_633_046_400_000  # 2021-10-01 00:00 UTC, a 5m boundary


def bars(prices, start=T0):
    """prices: list of (open, high, low, close) per minute starting at `start`."""
    p = np.array(prices, dtype=float)
    return {"open_time": start + MIN * np.arange(len(p)), "open": p[:, 0], "high": p[:, 1], "low": p[:, 2], "close": p[:, 3]}


def flat(n, price=100.0):
    return [(price, price, price, price)] * n


def reference(m1, anchor, n, funding, cost):
    """Slow, obviously-correct per-anchor implementation of the documented definitions."""
    times = {int(t): i for i, t in enumerate(m1["open_time"])}
    if any(int(anchor + j * MIN) not in times for j in range(n)):
        return None
    idx = [times[int(anchor + j * MIN)] for j in range(n)]
    e = m1["open"][idx[0]]
    fund = sum(r * 100 for f, r in funding if anchor < f <= anchor + n * MIN)
    out = {}
    for side in ("long", "short"):
        fav = [((m1["high"][i] / e - 1) * 100) if side == "long" else ((1 - m1["low"][i] / e) * 100) for i in idx]
        adv = [((1 - m1["low"][i] / e) * 100) if side == "long" else ((m1["high"][i] / e - 1) * 100) for i in idx]
        mfe = max(fav); mae = max(adv)
        out[f"{side}_mfe_pct"] = mfe
        out[f"{side}_mae_pct"] = mae
        out[f"{side}_time_to_mfe_s"] = (fav.index(mfe) + 1) * 60
        out[f"{side}_time_to_mae_s"] = (adv.index(mae) + 1) * 60
        gross = (m1["close"][idx[-1]] / e - 1) * 100 * (1 if side == "long" else -1)
        out[f"{side}_final_time_exit_gross_pct"] = gross
        out[f"{side}_final_time_exit_net_pct"] = gross - cost + (-fund if side == "long" else fund)
        k = next((j for j, f in enumerate(fav) if f >= cost), None)
        if k is None:
            for f in ("time_to_first_positive_net_s", "max_adverse_before_profit_pct", "profit_bar_ambiguous", "peak_retracement_pct"):
                out[f"{side}_{f}"] = math.nan
            continue
        before = max([0.0] + adv[:k])
        out[f"{side}_time_to_first_positive_net_s"] = (k + 1) * 60
        out[f"{side}_max_adverse_before_profit_pct"] = before
        out[f"{side}_profit_bar_ambiguous"] = 1.0 if adv[k] > before else 0.0
        retr = [max(fav[k:j]) + adv[j] for j in range(k + 1, n)]
        out[f"{side}_peak_retracement_pct"] = max(retr) if retr else math.nan
    return out


def as_funding(pairs):
    return {"funding_time": np.array([p[0] for p in pairs], np.int64), "funding_rate": np.array([p[1] for p in pairs])}


def test_long_profit_first_example():
    # entry 100 -> dip to 99.9 -> rally to 101 -> pullback to 100.5 -> close 100.6
    m1 = bars([(100, 100, 99.9, 99.95), (99.95, 100.3, 99.95, 100.3), (100.3, 101, 100.3, 101), (101, 101, 100.5, 100.6)])
    t = tg.build_targets(m1, np.array([T0]), 4, cost_pct=0.12)
    assert t["status"][0] == tg.VALID and t["entry_price"][0] == 100
    assert t["long_mfe_pct"][0] == pytest.approx(1.0)
    assert t["long_mae_pct"][0] == pytest.approx(0.1)
    assert t["long_time_to_mfe_s"][0] == 180
    assert t["long_time_to_first_positive_net_s"][0] == 120            # bar 1 high 100.3 >= 100.12
    assert t["long_max_adverse_before_profit_pct"][0] == pytest.approx(0.1)
    assert t["long_profit_bar_ambiguous"][0] == 0                       # bar 1 low 99.95 is not new adverse
    assert t["long_peak_retracement_pct"][0] == pytest.approx(0.5)      # 101 -> 100.5
    assert t["long_final_time_exit_net_pct"][0] == pytest.approx(0.6 - 0.12)


def test_long_dip_then_huge_rally_keeps_the_dip_as_adverse_before_profit():
    m1 = bars([(100, 100, 99.4, 99.5), (99.5, 99.6, 99.3, 99.5), (99.5, 103, 99.5, 102.5)])
    t = tg.build_targets(m1, np.array([T0]), 3)
    assert t["long_max_adverse_before_profit_pct"][0] == pytest.approx(0.7)   # 99.3 came first
    assert t["long_mfe_pct"][0] == pytest.approx(3.0)


def test_short_is_the_mirror():
    m1 = bars([(100, 100.1, 100, 100.05), (100.05, 100.05, 99, 99.2), (99.2, 99.6, 99.1, 99.5)])
    t = tg.build_targets(m1, np.array([T0]), 3)
    assert t["short_mfe_pct"][0] == pytest.approx(1.0)
    assert t["short_mae_pct"][0] == pytest.approx(0.1)
    assert t["short_max_adverse_before_profit_pct"][0] == pytest.approx(0.1)
    assert t["short_peak_retracement_pct"][0] == pytest.approx(0.6)          # trough 99 -> high 99.6
    assert t["short_final_time_exit_gross_pct"][0] == pytest.approx(0.5)


def test_profit_bar_with_new_low_is_flagged_ambiguous():
    m1 = bars([(100, 100.5, 99.5, 100.2), (100.2, 100.3, 100.1, 100.2)])
    t = tg.build_targets(m1, np.array([T0]), 2)
    assert t["long_time_to_first_positive_net_s"][0] == 60
    assert t["long_max_adverse_before_profit_pct"][0] == 0.0
    assert t["long_profit_bar_ambiguous"][0] == 1                           # high and low in one bar


def test_never_profitable_gives_nan_profit_fields():
    t = tg.build_targets(bars(flat(5)), np.array([T0]), 5)
    assert math.isnan(t["long_time_to_first_positive_net_s"][0])
    assert math.isnan(t["long_peak_retracement_pct"][0])
    assert t["long_final_time_exit_net_pct"][0] == pytest.approx(-0.12)     # flat path still pays costs


def test_G_missing_future_bar_is_invalid_never_filled():
    m1 = bars(flat(10))
    keep = np.ones(10, bool); keep[3] = False
    m1 = {k: v[keep] for k, v in m1.items()}
    t = tg.build_targets(m1, np.array([T0, T0 + 5 * MIN]), 4)          # minutes 0-3 (3 missing), 5-8
    assert t["status"].tolist() == [tg.GAP, tg.VALID]
    assert math.isnan(t["long_mfe_pct"][0]) and math.isnan(t["entry_price"][0])


def test_source_conflict_marks_anchor_candle_and_path_but_not_others():
    m1 = bars(flat(40))
    conflict = np.array([T0 + 10 * MIN])                  # 5m bucket [10, 15) minutes
    anchors = np.array([T0 + 5 * MIN, T0 + 15 * MIN, T0 + 20 * MIN])
    t = tg.build_targets(m1, anchors, 5, conflicts=conflict)
    # path [5,10) clean; anchor 15's own 5m candle is [10,15) -> conflict; anchor 20 clean
    assert t["status"].tolist() == [tg.VALID, tg.SOURCE_CONFLICT, tg.VALID]
    t = tg.build_targets(m1, np.array([T0 + 5 * MIN]), 10, conflicts=conflict)   # path [5,15) crosses it
    assert t["status"][0] == tg.SOURCE_CONFLICT


def test_path_beyond_data_is_out_of_range():
    t = tg.build_targets(bars(flat(5)), np.array([T0 + 3 * MIN]), 5)
    assert t["status"][0] == tg.OUT_OF_RANGE


def test_J_entry_is_the_open_of_the_bar_starting_at_T0_and_earlier_bars_do_not_matter():
    prices = [(90, 95, 85, 92)] * 5 + [(100, 100.2, 99.9, 100.1)] * 5
    a = tg.build_targets(bars(prices), np.array([T0 + 5 * MIN]), 5)
    assert a["entry_price"][0] == 100 and a["entry_time_ms"][0] == T0 + 5 * MIN
    changed = [(50, 200, 10, 70)] * 5 + prices[5:]                          # history before T0 changed
    b = tg.build_targets(bars(changed), np.array([T0 + 5 * MIN]), 5)
    for key in a:
        np.testing.assert_array_equal(a[key], b[key])


def test_K_changing_the_future_path_changes_the_target():
    prices = flat(6)
    a = tg.build_targets(bars(prices), np.array([T0]), 6)
    prices[4] = (100, 102, 100, 101)
    b = tg.build_targets(bars(prices), np.array([T0]), 6)
    assert b["long_mfe_pct"][0] == pytest.approx(2.0) and a["long_mfe_pct"][0] == 0.0


def test_H_I_funding_counted_once_inside_the_holding_window_only():
    funding = as_funding([(T0, 0.5), (T0 + 2 * MIN, 0.001), (T0 + 3 * MIN, 0.002), (T0 + 9 * MIN, 0.4)])
    t = tg.build_targets(bars(flat(5)), np.array([T0]), 3, funding)
    assert t["funding_long_pct"][0] == pytest.approx(0.3)                   # (0.001 + 0.002) * 100; T0 itself excluded
    t = tg.build_targets(bars(flat(5)), np.array([T0]), 1, funding)
    assert t["funding_long_pct"][0] == 0.0
    t = tg.build_targets(bars(flat(5)), np.array([T0]), 3, funding)
    assert t["long_final_time_exit_net_pct"][0] == pytest.approx(-0.12 - 0.3)
    assert t["short_final_time_exit_net_pct"][0] == pytest.approx(-0.12 + 0.3)


@pytest.mark.parametrize("seed", [1, 2, 3])
def test_vectorized_matches_reference_on_random_paths_with_gaps_and_any_chunk_size(seed):
    rng = random.Random(seed)
    price, prices = 100.0, []
    for _ in range(400):
        o = price
        c = o * (1 + rng.gauss(0, 0.002))
        h = max(o, c) * (1 + abs(rng.gauss(0, 0.001)))
        l = min(o, c) * (1 - abs(rng.gauss(0, 0.001)))
        prices.append((o, h, l, c)); price = c
    m1 = bars(prices)
    keep = np.ones(400, bool); keep[[57, 58, 211]] = False
    m1 = {k: v[keep] for k, v in m1.items()}
    funding = [(T0 + 37 * MIN + 5, 0.0003), (T0 + 190 * MIN, -0.0002)]
    anchors = T0 + 5 * MIN * np.arange(0, 80)
    for horizon in (1, 15, 60):
        for chunk in (3, 1000):
            got = tg.build_targets(m1, anchors, horizon, as_funding(funding), 0.12, chunk=chunk)
            for i, a in enumerate(anchors):
                ref = reference(m1, int(a), horizon, funding, 0.12)
                if ref is None:
                    assert got["status"][i] != tg.VALID
                    continue
                assert got["status"][i] == tg.VALID
                for key, value in ref.items():
                    if math.isnan(value):
                        assert math.isnan(got[key][i]), (horizon, i, key)
                    else:
                        assert got[key][i] == pytest.approx(value, rel=1e-9, abs=1e-9), (horizon, i, key)
