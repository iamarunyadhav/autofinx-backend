"""P5 rules (SPEC §103, D-028), including mandatory tests M (cap => WAIT), N (poor net => WAIT), O (conflict)."""

import sys
from pathlib import Path

import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import p5  # noqa: E402

FIVE = 300_000
COST, EDGE = 0.12, 0.06


def lv(policy=p5.ExitPolicy(1.0, 0.8, "vol"), **kw):
    base = dict(q25_mfe=[0.6], q50_mfe=[1.0], q75_mfe=[1.4], q75_adv=[0.3], q50_pull=[0.2], sigma=[0.4],
                cap=0.5, cost=COST, edge=EDGE, unc_limit=2.0, ood_wait=[False])
    base.update(kw)
    return p5.levels(policy, **base)


def test_dynamic_levels_follow_the_formulas():
    out = lv()
    assert out["sl"][0] == pytest.approx(0.3) and out["act"][0] == pytest.approx(0.48)
    assert out["trail"][0] == pytest.approx(0.2) and out["reason"][0] == 0      # 0.5 x sigma 0.4
    out = lv(p5.ExitPolicy(1.25, 0.6, "pull_1.5"))
    assert out["sl"][0] == pytest.approx(0.375) and out["act"][0] == pytest.approx(0.36) and out["trail"][0] == pytest.approx(0.3)


def test_reward_floor_waits_instead_of_forcing_activation_up():
    assert p5.WAIT_CODES[lv(q25_mfe=[0.17])["reason"][0]] == "REWARD_FLOOR"


def test_M_required_sl_above_the_cap_waits():
    assert p5.WAIT_CODES[lv(q75_adv=[0.6])["reason"][0]] == "SL_ABOVE_CAP"


def test_N_activation_floor_and_inconsistency_and_uncertainty_and_ood_wait():
    assert p5.WAIT_CODES[lv(q25_mfe=[0.2])["reason"][0]] == "ACTIVATION_FLOOR"          # 0.8 x 0.2 = 0.16 <= 0.18
    assert p5.WAIT_CODES[lv(q50_mfe=[0.25], q25_mfe=[0.24], q75_mfe=[0.3])["reason"][0]] in ("ACTIVATION_FLOOR", "INCONSISTENT")
    assert p5.WAIT_CODES[lv(q50_mfe=[0.2])["reason"][0]] == "INCONSISTENT"               # q50 MFE / SL < 1
    assert p5.WAIT_CODES[lv(unc_limit=0.5)["reason"][0]] == "UNCERTAIN"
    assert p5.WAIT_CODES[lv(ood_wait=[True])["reason"][0]] == "OOD"


def test_O_conflicting_sides_wait_and_the_ev_gate_applies():
    one = np.array([True])
    args = dict(base_buy=0.1, base_sell=0.1, lift=None, margin=0.03, ok_buy=one, ok_sell=one, edge=EDGE)
    assert p5.decide(np.array([0.3]), np.array([0.3]), np.array([0.15]), np.array([0.14]), **args)[0] == 0   # too close
    assert p5.decide(np.array([0.3]), np.array([0.3]), np.array([0.20]), np.array([0.10]), **args)[0] == 1
    assert p5.decide(np.array([0.3]), np.array([0.3]), np.array([0.08]), np.array([0.0]), **args)[0] == 0    # EV < edge + margin
    lifted = dict(args, lift=2.0)
    assert p5.decide(np.array([0.15]), np.array([0.0]), np.array([0.5]), np.array([0.0]), **lifted)[0] == 0   # p < 2 x base


def test_sl_cap_uses_unambiguous_reached_rows_only():
    adv = np.array([0.1, 0.2, np.nan, 5.0] + [0.3] * 20)
    amb = np.array([0, 0, 0, 1] + [0] * 20)
    assert p5.sl_cap(adv, amb) == pytest.approx(0.3)


def test_quality_gate_passes_a_useful_model_and_fails_crossing_or_no_gain():
    rng = np.random.default_rng(0)
    folds = []
    for _ in range(3):
        mu = rng.uniform(0.2, 2.0, 3000)
        y = mu * rng.uniform(0.5, 1.5, 3000)
        folds.append({"y": y, "q25": mu * 0.75, "q50": mu, "q75": mu * 1.25, "const": {0.25: np.quantile(y, .25), 0.5: np.median(y), 0.75: np.quantile(y, .75)}})
    assert p5.quality_gate(folds, 0.25)["passed"]
    useless = [{**f, "q25": np.full(len(f["y"]), 9.0)} for f in folds]
    assert not p5.quality_gate(useless, 0.25)["passed"]
    crossed = [{**f, "q25": f["q75"] * 2} for f in folds]
    r = p5.quality_gate(crossed, 0.5)
    assert r["crossing_rate"] > 0.05 and not r["passed"]


def test_nested_windows_are_chronological_disjoint_and_purged():
    anchors = 1_600_000_000_000 + FIVE * np.arange(10_000)
    w = p5.nested_windows(anchors, np.arange(10_000), 240)
    assert anchors[w["A"]].max() + 240 * 60_000 <= anchors[w["B"]].min()
    assert anchors[w["B"]].max() + 240 * 60_000 <= anchors[w["C"]].min()
    assert not set(w["A"]) & set(w["B"]) and not set(w["B"]) & set(w["C"])


def test_selection_rule_and_simplicity_tie_break():
    good = [np.array([0.2, 0.1, 0.3] * 10), np.array([0.2, -0.05, 0.25] * 10), np.array([0.15, 0.1] * 10)]
    ok, score, _ = p5.qualifies(good, 60)
    assert ok and score > 0
    assert not p5.qualifies([np.array([0.5])] * 3, 60)[0]                         # too few trades
    assert not p5.qualifies([np.array([0.2] * 20), np.array([-0.1] * 20), np.array([-0.1] * 20)], 60)[0]
    simple = (0.110, (0, 0, 0), 0.02, "simple")
    complex_ = (0.125, (1, 1, 2), 0.02, "complex")
    assert p5.pick([complex_, simple])[3] == "simple"                              # within 0.02%: simpler wins
    assert p5.pick([(0.20, (1, 1, 2), 0.0, "clear"), simple])[3] == "clear"        # clearly better wins
    assert p5.pick([]) is None


def test_forward_chaining_blocks_never_use_the_same_or_later_data():
    anchors = 1_600_000_000_000 + FIVE * np.arange(5_000)
    blocks = p5.forward_blocks(anchors, np.arange(5_000), 60)
    assert len(blocks) == 4                                                       # block 1 = warm-up only
    for train, predict in blocks:
        assert anchors[train].max() + 60 * 60_000 < anchors[predict].min()


def test_exit_family_has_12_policies_with_shared_multipliers():
    fam = p5.exit_family()
    assert len(fam) == 12 and len(set(fam)) == 12
    assert min(fam, key=lambda p: p.complexity) == p5.ExitPolicy(1.0, 0.8, "vol")
