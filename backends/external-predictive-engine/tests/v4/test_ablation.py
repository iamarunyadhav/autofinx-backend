"""P4B ablation rule (D-025): folds stay inside dev_train with a purge; the keep rule is fixed."""

import sys
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import ablation as ab  # noqa: E402

FIVE = 300_000


def test_folds_are_chronological_purged_and_disjoint():
    anchors = 1_600_000_000_000 + FIVE * np.arange(10_000)
    for fit, test in ab.folds(anchors, np.arange(10_000), horizon=60):
        assert anchors[fit].max() + 60 * 60_000 < anchors[test].min() + FIVE
        assert not set(fit) & set(test)
    assert len(ab.folds(anchors, np.arange(10_000), 60)) == 3


def test_keep_rule_requires_median_gain_fold_consistency_calibration_and_cap():
    assert ab.keep_group([0.004, 0.003, -0.001], 0.0, 50) == (True, "kept")
    assert not ab.keep_group([0.010, -0.002, -0.001], 0.0, 50)[0]      # one big fold hides two worse ones
    assert not ab.keep_group([0.001, 0.001, 0.001], 0.0, 50)[0]        # below the minimum gain
    assert not ab.keep_group([0.004, 0.003, 0.003], 0.001, 50)[0]      # calibration got worse
    assert not ab.keep_group([0.004, 0.003, 0.003], 0.0, 81)[0]        # feature cap
