"""Chronological splits for V4 (SPEC §41-42, D-005, D-016 proposed).

  holdout  = the newest HOLDOUT_FRACTION of each symbol's anchors (by time). SEALED until P6.
  purge    = MAX_HORIZON_MIN of anchors before the holdout start are dropped from development, so no
             development path (any horizon) can reach into the holdout window.
  dev      = everything before (holdout_start - purge). P3/P4/P5 may only read dev rows.
  dev_train / dev_val = chronological split of dev at DEV_TRAIN_FRACTION, with the same purge between.

Every P3+ module must call `dev_mask` (or `dev_train_val`) before computing any statistic.
"""

from __future__ import annotations

import numpy as np

HOLDOUT_FRACTION = 0.15
DEV_TRAIN_FRACTION = 0.70
MAX_HORIZON_MIN = 1440


def holdout_start_ms(anchors: np.ndarray) -> int:
    a = np.sort(np.asarray(anchors, np.int64))
    return int(a[int(len(a) * (1 - HOLDOUT_FRACTION))])


def dev_end_ms(anchors: np.ndarray) -> int:
    """Exclusive end of development data: holdout start minus the longest path."""
    return holdout_start_ms(anchors) - MAX_HORIZON_MIN * 60_000


def dev_mask(anchors: np.ndarray) -> np.ndarray:
    return np.asarray(anchors, np.int64) < dev_end_ms(anchors)


def dev_train_val(anchors: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    a = np.asarray(anchors, np.int64)
    dev = dev_mask(a)
    dev_times = np.sort(a[dev])
    cut = int(dev_times[int(len(dev_times) * DEV_TRAIN_FRACTION)])
    train = dev & (a < cut - MAX_HORIZON_MIN * 60_000)
    val = dev & (a >= cut)
    return train, val


def describe(anchors: np.ndarray) -> dict:
    import datetime as dt

    def utc(ms: int) -> str:
        return dt.datetime.fromtimestamp(ms / 1000, dt.timezone.utc).strftime("%Y-%m-%d %H:%M")
    a = np.asarray(anchors, np.int64)
    train, val = dev_train_val(a)
    return {
        "first_anchor": utc(int(a.min())), "dev_end_exclusive": utc(dev_end_ms(a)),
        "holdout_start": utc(holdout_start_ms(a)), "holdout_rows_sealed": int((a >= holdout_start_ms(a)).sum()),
        "dev_rows": int(dev_mask(a).sum()), "dev_train_rows": int(train.sum()), "dev_val_rows": int(val.sum()),
        "dev_train_end": utc(int(a[train].max())) if train.any() else None,
        "dev_val_start": utc(int(a[val].min())) if val.any() else None,
    }
