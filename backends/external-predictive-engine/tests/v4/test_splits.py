"""Holdout sealing (D-005): development rows never reach into the holdout window."""

import sys
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).parents[2]))

from v4 import splits  # noqa: E402

FIVE = 300_000


def test_dev_is_purged_before_the_holdout_and_train_val_are_disjoint():
    anchors = 1_600_000_000_000 + FIVE * np.arange(200_000)
    hold = splits.holdout_start_ms(anchors)
    dev = splits.dev_mask(anchors)
    train, val = splits.dev_train_val(anchors)
    assert (anchors >= hold).sum() == 30_000                                    # newest 15%
    assert anchors[dev].max() + splits.MAX_HORIZON_MIN * 60_000 < hold + FIVE   # longest path ends before holdout
    assert not (train & val).any() and not (train & ~dev).any() and not (val & ~dev).any()
    assert anchors[train].max() + splits.MAX_HORIZON_MIN * 60_000 < anchors[val].min() + FIVE   # purge between
