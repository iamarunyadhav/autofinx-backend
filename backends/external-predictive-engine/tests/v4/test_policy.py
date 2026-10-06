"""P3 Layer B tests — SPEC §78 cases A-F, G, H, I, L (M-O are decision gates, P5) plus a slow
reference simulator compared on random paths."""

import random
import sys
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import policy as pol  # noqa: E402

MIN = 60_000
T0 = 1_633_046_400_000


def bars(prices, start=T0):
    p = np.array(prices, dtype=float)
    return {"open_time": start + MIN * np.arange(len(p)), "open": p[:, 0], "high": p[:, 1], "low": p[:, 2], "close": p[:, 3]}


def run(prices, side="long", sl=0.5, act=0.4, trail=0.2, cost=0.12, n=None, funding=None, anchors=None):
    n = n or len(prices)
    anchors = np.array([T0]) if anchors is None else anchors
    return pol.simulate(bars(prices), anchors, n, side, sl, act, trail, cost, funding)


def test_A_long_activation_then_higher_peak_then_trailing_reversal():
    # 100 -> 100.5 (arms at +0.4) -> 101.0 peak -> drop to 100.7 (< 101.0 - 0.2 = 100.8)
    r = run([(100, 100.1, 99.9, 100.05), (100.05, 100.5, 100.35, 100.45), (100.45, 101.0, 100.9, 100.95), (100.95, 100.95, 100.7, 100.75), (100.75, 100.8, 100.7, 100.8)])
    assert pol.REASONS[r["reason"][0]] == "TRAILING_PROFIT"
    assert r["gross_pct"][0] == pytest.approx(0.8)                     # exit at peak 1.0% - trail 0.2%
    assert r["net_pct"][0] == pytest.approx(0.68) and r["exit_bar"][0] == 3


def test_B_long_stop_first_then_huge_rally_stays_a_loss():
    r = run([(100, 100.1, 99.6, 99.7), (99.7, 99.7, 99.45, 99.5), (99.5, 102, 99.5, 102), (102, 103, 102, 103)])
    assert pol.REASONS[r["reason"][0]] == "HARD_STOP"
    assert r["gross_pct"][0] == pytest.approx(-0.5) and r["exit_bar"][0] == 1


def test_C_short_mirror_of_A_and_B():
    a = run([(100, 100.1, 99.9, 99.95), (99.95, 99.65, 99.5, 99.55), (99.15, 99.18, 99.0, 99.05), (99.05, 99.3, 99.05, 99.25), (99.25, 99.3, 99.2, 99.2)], side="short")
    assert pol.REASONS[a["reason"][0]] == "TRAILING_PROFIT" and a["gross_pct"][0] == pytest.approx(0.8)
    b = run([(100, 100.4, 99.9, 100.3), (100.3, 100.55, 100.3, 100.5), (100.5, 100.5, 97, 97)], side="short")
    assert pol.REASONS[b["reason"][0]] == "HARD_STOP" and b["gross_pct"][0] == pytest.approx(-0.5)


def test_D_neither_activation_nor_stop_is_a_time_exit():
    r = run([(100, 100.2, 99.8, 100.1), (100.1, 100.3, 99.9, 100.2), (100.2, 100.3, 100.0, 100.3)])
    assert pol.REASONS[r["reason"][0]] == "TIME_EXIT_PROFIT"
    assert r["gross_pct"][0] == pytest.approx(0.3) and r["net_pct"][0] == pytest.approx(0.18)
    assert r["exit_time_ms"][0] == T0 + 3 * MIN


def test_E_gross_profit_below_cost_is_not_a_success():
    r = run([(100, 100.1, 99.95, 100.05), (100.05, 100.1, 100.0, 100.1)])
    assert r["gross_pct"][0] == pytest.approx(0.1) and r["net_pct"][0] < 0
    assert pol.REASONS[r["reason"][0]] == "TIME_EXIT_LOSS"


def test_F_same_bar_touches_stop_and_activation_is_ambiguous_and_never_a_win():
    r = run([(100, 100.6, 99.4, 100.2), (100.2, 101, 100.2, 101)])
    assert pol.REASONS[r["reason"][0]] == "AMBIGUOUS_INTRABAR"
    assert r["gross_pct"][0] == pytest.approx(-0.5)                    # evaluated as the worse outcome


def test_G_missing_bar_is_invalid_data():
    m1 = bars([(100, 100, 100, 100)] * 6)
    keep = np.ones(6, bool); keep[2] = False
    m1 = {k: v[keep] for k, v in m1.items()}
    r = pol.simulate(m1, np.array([T0]), 4, "long", 0.5, 0.4, 0.2, 0.12)
    assert pol.REASONS[r["reason"][0]] == "INVALID_DATA" and np.isnan(r["net_pct"][0])


def test_gap_through_the_stop_fills_at_the_open_not_the_stop():
    r = run([(100, 100.1, 99.8, 99.9), (99.0, 99.2, 98.8, 99.1)])       # opens at -1.0% below the -0.5% stop
    assert pol.REASONS[r["reason"][0]] == "HARD_STOP" and r["gross_pct"][0] == pytest.approx(-1.0)


def test_H_I_funding_only_until_the_exit():
    funding = {"funding_time": np.array([T0 + 1 * MIN, T0 + 30 * MIN]), "funding_rate": np.array([0.001, 0.05])}
    r = run([(100, 100.1, 99.6, 99.7), (99.7, 99.7, 99.45, 99.5)] + [(99.5, 99.6, 99.4, 99.5)] * 40, funding=funding, n=42)
    assert r["exit_bar"][0] == 1 and r["funding_pct"][0] == pytest.approx(0.1)      # second event after the exit
    assert r["net_pct"][0] == pytest.approx(-0.5 - 0.12 - 0.1)
    s = run([(100, 100.1, 99.9, 100)] * 3, side="short", funding={"funding_time": np.array([T0 + 9 * MIN]), "funding_rate": np.array([0.01])})
    assert s["funding_pct"][0] == 0.0


def test_arming_bar_that_also_dips_under_the_new_stop_exits_conservatively():
    # The bar reaches +0.5% (arms, stop 0.3%) but its low is 0.0%: order unknown -> exit at the stop, flagged.
    r = run([(100, 100.1, 99.9, 100.05), (100.05, 100.5, 100.0, 100.45), (100.45, 101.0, 100.9, 100.95)])
    assert pol.REASONS[r["reason"][0]] == "TRAILING_PROFIT"
    assert r["gross_pct"][0] == pytest.approx(0.3) and r["same_bar_trail"][0]


def test_L_trailing_stop_never_loosens_and_new_peak_same_bar_is_conservative():
    # arms, peak 1.0, then a bar with a new high 1.3 AND a low under 1.3-0.2: exit at 1.1 (new stop), flagged
    r = run([(100, 100.1, 99.95, 100.05), (100.05, 100.5, 100.35, 100.45), (100.45, 101.0, 100.9, 100.95), (100.95, 101.3, 100.95, 101.0), (101, 101.1, 101.0, 101.05)])
    assert pol.REASONS[r["reason"][0]] == "TRAILING_PROFIT"
    assert r["gross_pct"][0] == pytest.approx(1.1) and r["same_bar_trail"][0]
    # A pullback that does not reach the stop must not loosen it: later stop exits use the old peak.
    r = run([(100, 100.1, 99.95, 100.05), (100.05, 100.5, 100.35, 100.45), (100.45, 101.0, 100.9, 100.95), (100.95, 100.95, 100.85, 100.9), (100.9, 100.9, 100.7, 100.7)])
    assert r["gross_pct"][0] == pytest.approx(0.8) and r["exit_bar"][0] == 4


def test_stop_and_activation_must_be_positive():
    with pytest.raises(ValueError):
        run([(100, 100, 100, 100)] * 2, sl=0)


def reference(prices, side, sl, act, trail, cost):
    """Slow per-bar loop implementing the documented rules literally."""
    s = 1 if side == "long" else -1
    e = prices[0][0]
    def pct(x):
        return (x / e - 1) * 100 * s
    floor, armed, peak = -sl, False, None
    for j, (o, h, l, c) in enumerate(prices):
        fav, adv, opn = (pct(h), pct(l), pct(o)) if s > 0 else (pct(l), pct(h), pct(o))
        if not armed:
            hit_sl, hit_act = adv <= floor, fav >= act
            if hit_sl:
                return ("AMBIGUOUS_INTRABAR" if hit_act else "HARD_STOP"), min(opn, floor), j
            if hit_act:
                armed, peak = True, fav
                stop = max(floor, peak - trail)
                if adv <= stop:
                    return ("TRAILING_PROFIT" if stop > floor + 1e-12 else "HARD_STOP"), stop, j
            continue
        prev = max(floor, peak - trail)
        if adv <= prev:
            return ("TRAILING_PROFIT" if prev > floor + 1e-12 else "HARD_STOP"), min(opn, prev), j
        peak = max(peak, fav)
        stop = max(floor, peak - trail)
        if adv <= stop:
            return ("TRAILING_PROFIT" if stop > floor + 1e-12 else "HARD_STOP"), stop, j
    close = pct(prices[-1][3])
    net = close - cost
    return ("TIME_EXIT_PROFIT" if net > 0.01 else "TIME_EXIT_LOSS" if net < -0.01 else "TIME_EXIT_FLAT"), close, len(prices) - 1


@pytest.mark.parametrize("seed", [1, 2, 3, 4])
def test_vectorized_simulator_matches_reference_on_random_paths(seed):
    rng = random.Random(seed)
    price, prices = 100.0, []
    for _ in range(600):
        o = price * (1 + rng.gauss(0, 0.0004))
        c = o * (1 + rng.gauss(0, 0.002))
        h = max(o, c) * (1 + abs(rng.gauss(0, 0.0012)))
        l = min(o, c) * (1 - abs(rng.gauss(0, 0.0012)))
        prices.append((o, h, l, c)); price = c
    m1 = bars(prices)
    anchors = T0 + 5 * MIN * np.arange(100)
    for n in (15, 60):
        for side in ("long", "short"):
            for sl, act, trail in ((0.3, 0.2, 0.1), (0.5, 0.4, 0.25), (1.0, 0.3, 0.5)):
                for chunk in (7, 4000):
                    got = pol.simulate(m1, anchors, n, side, sl, act, trail, 0.12, chunk=chunk)
                    for i, a in enumerate(anchors):
                        start = int((a - T0) // MIN)
                        if start + n > len(prices):
                            continue
                        why, move, j = reference(prices[start:start + n], side, sl, act, trail, 0.12)
                        assert pol.REASONS[got["reason"][i]] == why, (n, side, sl, i)
                        assert got["gross_pct"][i] == pytest.approx(move, abs=1e-9), (n, side, sl, i)
                        assert got["exit_bar"][i] == j
