"""Calibration metrics, calibrators, day-block bootstrap, confidence buckets, greedy chains (plan sections 6-9). Pure numpy / scipy / sklearn."""

from __future__ import annotations

import zlib

import numpy as np
from scipy import stats as sps

from edge_search.core import MDE_FACTOR, bh_adjust

EPS = 1e-6
CLIP = 1e-4                       # probabilities are clipped to [CLIP, 1 - CLIP] for log loss
BUCKET_EDGES = (0.50, 0.60, 0.70, 0.75, 0.80, 0.90, 1.0000001)
BUCKET_NAMES = ("50-60", "60-70", "70-75", "75-80", "80-90", "90-100")
N_BOOT = 2000


def rng_for(key: str) -> np.random.Generator:
    return np.random.default_rng(zlib.crc32(key.encode()))


def logit(p):
    p = np.clip(np.asarray(p, float), EPS, 1 - EPS)
    return np.log(p / (1 - p))


def expit(x):
    return 1.0 / (1.0 + np.exp(-np.clip(x, -35, 35)))


# ------------------------------------------------------------------------------------------ logistic fit (intercept + slope), optionally weighted
def fit_logistic(x, y, w=None, iters: int = 60) -> tuple[float, float]:
    """Unpenalised logistic regression y ~ a + b x by Newton / IRLS. Returns (a, b); (nan, nan) if y is constant or x is constant."""
    x = np.asarray(x, float)
    y = np.asarray(y, float)
    w = np.ones_like(x) if w is None else np.asarray(w, float)
    if w.sum() <= 0 or np.ptp(y[w > 0]) == 0 or np.ptp(x[w > 0]) == 0:
        return float("nan"), float("nan")
    a, b = float(np.log((np.sum(w * y) + 0.5) / (np.sum(w * (1 - y)) + 0.5))), 0.0
    for _ in range(iters):
        p = expit(a + b * x)
        g = np.array([np.sum(w * (y - p)), np.sum(w * (y - p) * x)])
        v = w * p * (1 - p)
        H = np.array([[v.sum(), (v * x).sum()], [(v * x).sum(), (v * x * x).sum()]]) + 1e-12 * np.eye(2)
        step = np.linalg.solve(H, g)
        a, b = a + step[0], b + step[1]
        if np.abs(step).max() < 1e-10:
            break
    return float(a), float(b)


def fit_logistic_batch(x, y, W, iters: int = 30):
    """B replicates at once: x, y (m,), W (B, m) weights. Returns (a, b) arrays (B,)."""
    B = W.shape[0]
    a = np.log((W @ y + 0.5) / (W @ (1 - y) + 0.5))
    b = np.zeros(B)
    for _ in range(iters):
        p = expit(a[:, None] + b[:, None] * x[None, :])
        r = W * (y[None, :] - p)
        g0, g1 = r.sum(1), (r * x[None, :]).sum(1)
        v = W * p * (1 - p)
        h00, h01, h11 = v.sum(1) + 1e-12, (v * x[None, :]).sum(1), (v * x[None, :] ** 2).sum(1) + 1e-12
        det = h00 * h11 - h01 ** 2
        det = np.where(np.abs(det) < 1e-18, 1e-18, det)
        s0, s1 = (h11 * g0 - h01 * g1) / det, (-h01 * g0 + h00 * g1) / det
        a, b = a + s0, b + s1
        if max(np.abs(s0).max(), np.abs(s1).max()) < 1e-9:
            break
    return a, b


# ------------------------------------------------------------------------------------------ calibrators
class Calibrator:
    """method in raw | platt | iso | base | c50. fit() takes ONLY calibration-window rows (the caller proves it with leakage.assert_calibration_rows)."""

    def __init__(self, method: str):
        self.method = method
        self.a = self.b = self.base = None
        self.iso = None

    def fit(self, raw, y):
        raw, y = np.asarray(raw, float), np.asarray(y, float)
        ok = np.isfinite(raw) & np.isfinite(y)
        raw, y = raw[ok], y[ok]
        self.base = float(y.mean()) if len(y) else 0.5
        if self.method == "platt":
            self.a, self.b = fit_logistic(logit(raw), y)
            if not np.isfinite(self.a):
                self.method = "base"
        elif self.method == "iso":
            from sklearn.isotonic import IsotonicRegression
            self.iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip")
            self.iso.fit(raw, y)
        return self

    def predict(self, raw):
        raw = np.asarray(raw, float)
        if self.method == "raw":
            return np.clip(raw, 0.0, 1.0)
        if self.method == "platt":
            return expit(self.a + self.b * logit(raw))
        if self.method == "iso":
            return self.iso.predict(raw)
        if self.method == "base":
            return np.full(len(raw), self.base)
        if self.method == "c50":
            return np.full(len(raw), 0.5)
        raise KeyError(self.method)


def fit_calibrator_checked(method: str, anchor, rows, raw, y, t_cal0: int, t_test0: int, label_ms: int) -> "Calibrator":
    """Fit a calibrator on `rows` ONLY if they are inside the matured calibration window (leakage.assert_calibration_rows raises otherwise).
    raw / y are aligned to `rows`."""
    from . import leakage as LK
    LK.assert_calibration_rows(anchor, rows, t_cal0, t_test0, label_ms)
    return Calibrator(method).fit(raw, y)


# ------------------------------------------------------------------------------------------ point metrics
def auc(y, p):
    from sklearn.metrics import roc_auc_score
    y = np.asarray(y)
    if len(np.unique(y)) < 2:
        return None
    return float(roc_auc_score(y, p))


def ece_mass(p, y, bins: int = 10) -> float:
    order = np.argsort(p, kind="stable")
    n = len(p)
    tot = 0.0
    for g in range(bins):
        s = order[(g * n) // bins:((g + 1) * n) // bins]
        if len(s):
            tot += len(s) / n * abs(float(y[s].mean()) - float(p[s].mean()))
    return float(tot)


def ece_width(p, y, bins: int = 10) -> float:
    b = np.clip((np.asarray(p) * bins).astype(int), 0, bins - 1)
    tot = 0.0
    for g in range(bins):
        m = b == g
        if m.any():
            tot += m.mean() * abs(float(y[m].mean()) - float(p[m].mean()))
    return float(tot)


def point_metrics(p, y, base_p: float | None = None) -> dict:
    p = np.clip(np.asarray(p, float), 0.0, 1.0)
    y = np.asarray(y, float)
    n = len(y)
    if n == 0:
        return {"n": 0}
    pc = np.clip(p, CLIP, 1 - CLIP)
    brier = float(np.mean((p - y) ** 2))
    ll = float(-np.mean(y * np.log(pc) + (1 - y) * np.log(1 - pc)))
    a, b = fit_logistic(logit(p), y)
    out = {"n": int(n), "base_rate": float(y.mean()), "mean_p": float(p.mean()), "brier": brier, "logloss": ll, "ece_mass": ece_mass(p, y),
           "ece_width": ece_width(p, y), "citl": float(y.mean() - p.mean()), "intercept": a, "slope": b, "auc": auc(y, p)}
    if base_p is not None:
        bb = float(np.mean((base_p - y) ** 2))
        out["brier_base"] = bb
        out["bss"] = float(1 - brier / bb) if bb > 0 else None
    return out


def reliability(p, y, bins: int = 10) -> list:
    order = np.argsort(p, kind="stable")
    n = len(p)
    rows = []
    for g in range(bins):
        s = order[(g * n) // bins:((g + 1) * n) // bins]
        if len(s):
            rows.append({"bin": g + 1, "n": int(len(s)), "p_lo": float(p[s].min()), "p_hi": float(p[s].max()), "mean_p": float(p[s].mean()), "observed": float(y[s].mean())})
    return rows


# ------------------------------------------------------------------------------------------ day-block bootstrap of ratio statistics
def day_sums(day, n_days: int, *vals) -> np.ndarray:
    """(n_days, k) per-day sums of the value arrays (rows with NaN in any value are NOT dropped here; callers pass clean arrays)."""
    return np.stack([np.bincount(day, weights=np.asarray(v, float), minlength=n_days) for v in vals], axis=1)


def ratio_boot(num_d, den_d, key: str, B: int = N_BOOT, chunk: int = 500) -> np.ndarray:
    rng = rng_for(key)
    D = len(num_d)
    out = np.empty(B)
    for a in range(0, B, chunk):
        m = min(chunk, B - a)
        idx = rng.integers(0, D, size=(m, D))
        den = den_d[idx].sum(1)
        out[a:a + m] = np.where(den > 0, num_d[idx].sum(1) / np.maximum(den, 1e-12), np.nan)
    return out


def effect_stats(num_d, den_d, key: str, B: int = N_BOOT) -> dict:
    """Mean = sum(num) / sum(den); SE from the day-block bootstrap; one-sided p (H1: mean > 0); CI90; MDE = 2.8 SE."""
    den = float(np.sum(den_d))
    if den <= 0:
        return {"effect": None, "n": 0.0, "se": None, "p": 1.0, "mde": None, "ci90": None}
    eff = float(np.sum(num_d) / den)
    r = {"effect": eff, "n": den}
    if den < 30:
        r.update({"se": None, "p": 1.0, "mde": None, "ci90": None})
        return r
    b = ratio_boot(np.asarray(num_d, float), np.asarray(den_d, float), key, B)
    se = float(np.nanstd(b, ddof=1))
    r["se"] = se
    r["mde"] = float(MDE_FACTOR * se) if se > 0 else None
    r["ci90"] = [eff - 1.645 * se, eff + 1.645 * se]
    r["p"] = float(sps.norm.sf(eff / se)) if se > 0 else (0.0 if eff > 0 else 1.0)
    return r


def bh(p) -> np.ndarray:
    p = np.asarray([1.0 if (v is None or not np.isfinite(v)) else v for v in p], float)
    return bh_adjust(p) if len(p) else p


# ------------------------------------------------------------------------------------------ binned calibration bootstrap (slope / intercept / ECE CIs)
NB = 100


class BinStats:
    """Per-day, per-equal-mass-bin sufficient statistics of (p, y): count, sum y, sum p, sum logit(p), sum squared error. 100 fine bins nest into 10 deciles."""

    def __init__(self, p, y, day, n_days: int, nb: int = NB):
        p = np.clip(np.asarray(p, float), 0.0, 1.0)
        y = np.asarray(y, float)
        order = np.argsort(p, kind="stable")
        rank = np.empty(len(p), np.int64)
        rank[order] = np.arange(len(p))
        b = np.minimum((rank * nb) // len(p), nb - 1)
        self.nb, self.D = nb, n_days
        flat = np.asarray(day, np.int64) * nb + b
        size = n_days * nb
        f = lambda w: np.bincount(flat, weights=w, minlength=size).reshape(n_days, nb)
        self.N, self.SY, self.SP = f(np.ones(len(p))), f(y), f(p)
        self.SX, self.SB = f(logit(p)), f((p - y) ** 2)

    def totals(self, mult):
        """mult: (B, D) day multiplicities -> (B, nb) totals of each statistic."""
        return [mult @ a for a in (self.N, self.SY, self.SP, self.SX, self.SB)]


def binned_cal_ci(bs: BinStats, key: str, B: int = N_BOOT) -> dict:
    """Bootstrap CIs (percentile 90 %) of ECE (deciles), intercept, slope, CITL and Brier from day resampling of the binned statistics."""
    rng = rng_for(key)
    mult = np.zeros((B, bs.D))
    draws = rng.integers(0, bs.D, size=(B, bs.D))
    for r in range(B):
        mult[r] = np.bincount(draws[r], minlength=bs.D)
    N, SY, SP, SX, SB = bs.totals(mult)
    g = np.arange(bs.nb) // (bs.nb // 10)
    Ng, SYg, SPg = (np.stack([a[:, g == k].sum(1) for k in range(10)], 1) for a in (N, SY, SP))
    tot = N.sum(1)
    ece = np.abs(SYg - SPg).sum(1) / tot
    citl = (SY.sum(1) - SP.sum(1)) / tot
    brier = SB.sum(1) / tot
    # grouped-data logistic fit on the pooled fine-bin centres (x = pooled mean logit of the bin): every bin contributes SY successes (y = 1) and N - SY failures
    # (y = 0) at its centre; day-resampled replicate weights give the replicate fits (vectorised over replicates)
    xc = bs.SX.sum(0) / np.maximum(bs.N.sum(0), 1e-12)
    x2 = np.concatenate([xc, xc])
    y2 = np.concatenate([np.ones(bs.nb), np.zeros(bs.nb)])
    W2 = np.concatenate([SY, N - SY], axis=1)
    a_s, b_s = fit_logistic_batch(x2, y2, W2)
    q = lambda v: [float(np.nanpercentile(v, 5)), float(np.nanpercentile(v, 95))]
    return {"ece_mass_ci90": q(ece), "citl_ci90": q(citl), "brier_ci90": q(brier), "intercept_ci90": q(a_s), "slope_ci90": q(b_s)}


# ------------------------------------------------------------------------------------------ buckets, chains, effective N
def bucket_of(conf) -> np.ndarray:
    """Index 0..5 of the fixed confidence buckets (-1 below 0.50)."""
    conf = np.asarray(conf, float)
    idx = np.searchsorted(np.asarray(BUCKET_EDGES), conf, side="right") - 1
    idx = np.where(conf < BUCKET_EDGES[0], -1, idx)
    return np.minimum(idx, len(BUCKET_NAMES) - 1)


def effective_n(coin_id, anchor, block_ms: int) -> int:
    """Distinct (coin, horizon-block) cells containing at least one event: the number of non-overlapping independent-by-construction windows."""
    key = np.asarray(coin_id, np.int64) * 10_000_000 + (np.asarray(anchor, np.int64) // int(block_ms))
    return int(len(np.unique(key)))


def greedy_chain(anchor, side, exit_t) -> np.ndarray:
    """Indices of executed trades: first signalled event, then the first signalled event at or after its exit (one position at a time). Inputs sorted by anchor."""
    idx = np.nonzero(np.asarray(side) != 0)[0]
    out, free = [], -1
    a, x = np.asarray(anchor), np.asarray(exit_t)
    for i in idx:
        if a[i] >= free:
            out.append(i)
            free = x[i]
    return np.asarray(out, np.int64)


def trade_summary(net, gross=None) -> dict:
    net = np.asarray(net, float)
    n = len(net)
    if n == 0:
        return {"n": 0}
    pos, neg = net[net > 0], net[net < 0]
    eq = np.r_[0.0, np.cumsum(net)]
    dd = float((np.maximum.accumulate(eq) - eq).max())
    out = {"n": n, "net": float(net.mean()), "win_rate": float((net > 0).mean()), "avg_win": float(pos.mean()) if len(pos) else None,
           "avg_loss": float(neg.mean()) if len(neg) else None, "pf": float(pos.sum() / -neg.sum()) if len(neg) and neg.sum() < 0 else None,
           "max_dd": dd, "total": float(net.sum())}
    if gross is not None:
        out["gross"] = float(np.mean(gross))
    return out


def wilson(w: float, n: float, z: float = 1.96):
    if n <= 0:
        return None, None
    p = w / n
    den = 1 + z * z / n
    c = (p + z * z / (2 * n)) / den
    h = z * np.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / den
    return float(c - h), float(c + h)
