"""Leakage controls for Experiment E v1: label maturity, fold-chronology checks, calibrator-window guard, prefix-invariance (look-ahead) detector."""

from __future__ import annotations

import numpy as np

EMBARGO_MS = 24 * 3_600_000


def mature_mask(exit_time, boundary: int, embargo: int = EMBARGO_MS) -> np.ndarray:
    """A label is mature for `boundary` iff its exit time + embargo <= boundary. Immature labels must never be used (fit, calibration)."""
    return np.asarray(exit_time, np.int64) + int(embargo) <= int(boundary)


class LeakageError(AssertionError):
    pass


def check_fold(anchor, fit_idx, cal_idx, test_idx, label_ms: int, t_cal0: int, t_test0: int, embargo: int = EMBARGO_MS) -> None:
    """Raises LeakageError unless fit < calibration < test chronologically with every fit / calibration label mature for the next boundary."""
    a = np.asarray(anchor, np.int64)
    fit_idx, cal_idx, test_idx = (np.asarray(x, np.int64) for x in (fit_idx, cal_idx, test_idx))
    if len(fit_idx) and int(a[fit_idx].max()) + label_ms + embargo > t_cal0:
        raise LeakageError("a fit label is not mature at the calibration boundary (overlap / embargo violated)")
    if len(cal_idx) and int(a[cal_idx].min()) < t_cal0:
        raise LeakageError("calibration rows start before the calibration window")
    if len(cal_idx) and int(a[cal_idx].max()) + label_ms + embargo > t_test0:
        raise LeakageError("a calibration label is not mature at the test boundary")
    if len(test_idx) and int(a[test_idx].min()) < t_test0:
        raise LeakageError("test rows start before the test window")
    if len(fit_idx) and len(test_idx) and int(a[fit_idx].max()) >= int(a[test_idx].min()):
        raise LeakageError("fit rows overlap the test window")
    s_fit, s_cal, s_test = set(fit_idx.tolist()), set(cal_idx.tolist()), set(test_idx.tolist())
    if s_fit & s_cal or s_fit & s_test or s_cal & s_test:
        raise LeakageError("a row is in two of fit / calibration / test")


def assert_calibration_rows(anchor, rows, t_cal0: int, t_test0: int, label_ms: int, embargo: int = EMBARGO_MS) -> None:
    """A calibrator may only be fit on rows of the calibration window whose labels are matured before the test window starts."""
    a = np.asarray(anchor, np.int64)[np.asarray(rows, np.int64)]
    if len(a) == 0:
        raise LeakageError("empty calibration window")
    if a.min() < t_cal0 or a.max() + label_ms + embargo > t_test0:
        raise LeakageError("calibrator rows are outside the matured calibration window (fitting on the evaluation window is not allowed)")


def prefix_invariant(fn, arrays: tuple, cut_points, tol: float = 1e-9) -> bool:
    """True iff fn(*arrays[:m]) equals fn(*arrays)[:m] at every cut point (a feature that reads the future fails)."""
    full = np.asarray(fn(*arrays), float)
    for m in cut_points:
        part = np.asarray(fn(*[a[:m] for a in arrays]), float)
        both = np.isfinite(part) & np.isfinite(full[:m])
        if (np.isfinite(part) != np.isfinite(full[:m])).any() or (np.abs(part[both] - full[:m][both]) > tol).any():
            return False
    return True


TOO_GOOD_AUC = 0.70


def too_good_guard(auc_value: float | None, name: str) -> None:
    """Out-of-sample AUC of a direction / success model above 0.70 on 5-minute crypto data is a leak signature: abort the job."""
    if auc_value is not None and auc_value > TOO_GOOD_AUC:
        raise LeakageError(f"{name}: out-of-sample AUC {auc_value:.3f} > {TOO_GOOD_AUC} - leak signature, job aborted")
