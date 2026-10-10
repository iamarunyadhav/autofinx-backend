"""Pooled trade-set statistics for Q2 / Q3 (plan section 5). Pure numpy.

Conventions: trades are in % of notional; DISC = BTC + ETH pooled; day blocks = whole UTC days of the entry time (pooled across coins);
random-filter null = random subsets of the unfiltered trades with the same size; max drawdown = max peak-to-trough fall of the
cumulative net of the exit-time-ordered trades (percentage points of notional, one unit per trade).
"""

from __future__ import annotations

import zlib

import numpy as np
from scipy import stats as sps

from edge_search.core import DAY, MDE_FACTOR
from .q1 import YEARS, years_of

C0 = 0.12
MAKER_COST = 2 * (0.02 + 0.01)
N_BOOT = 2000
N_NULL = 2000


def pf(x):
    x = np.asarray(x, float)
    neg = -x[x < 0].sum()
    return float(x[x > 0].sum() / neg) if neg > 0 else (None if x[x > 0].sum() == 0 else float("inf"))


def mdd(x, exit_t):
    if len(x) == 0:
        return 0.0
    eq = np.r_[0.0, np.cumsum(np.asarray(x, float)[np.argsort(exit_t, kind="stable")])]
    return float((np.maximum.accumulate(eq) - eq).max())


def mean_or_none(x):
    return float(np.mean(x)) if len(x) else None


def day_index(t_ms):
    u, inv = np.unique(np.asarray(t_ms, np.int64) // DAY, return_inverse=True)
    return inv, len(u)


def boot_means(x, inv, k, rng, B=N_BOOT, chunk=250):
    """Day-block bootstrap of the mean of x (whole days resampled)."""
    s, c = np.bincount(inv, weights=x, minlength=k), np.bincount(inv, minlength=k).astype(float)
    out = np.empty(B)
    for a in range(0, B, chunk):
        m = min(chunk, B - a)
        idx = rng.integers(0, k, size=(m, k))
        out[a:a + m] = s[idx].sum(axis=1) / np.maximum(c[idx].sum(axis=1), 1)
    return out


def boot_delta(x, keep, inv, k, rng, B=N_BOOT, chunk=250):
    """Day-block bootstrap of mean(x[keep]) - mean(x) (same resampled days)."""
    s_all, c_all = np.bincount(inv, weights=x, minlength=k), np.bincount(inv, minlength=k).astype(float)
    s_k, c_k = np.bincount(inv, weights=x * keep, minlength=k), np.bincount(inv, weights=keep.astype(float), minlength=k)
    out = np.empty(B)
    for a in range(0, B, chunk):
        m = min(chunk, B - a)
        idx = rng.integers(0, k, size=(m, k))
        ck = c_k[idx].sum(axis=1)
        out[a:a + m] = np.where(ck > 0, s_k[idx].sum(axis=1) / np.maximum(ck, 1), np.nan) - s_all[idx].sum(axis=1) / np.maximum(c_all[idx].sum(axis=1), 1)
    return out


def random_subset_means(x, k, rng, D=N_NULL, chunk=50):
    """Means of D random subsets of size k of x (random filter that skips the same share)."""
    N = len(x)
    out = np.empty(D)
    comp = k > N / 2
    kk = N - k if comp else k
    tot = x.sum()
    for a in range(0, D, chunk):
        m = min(chunk, D - a)
        r = rng.random((m, N))
        sel = np.argpartition(r, kk - 1, axis=1)[:, :kk] if 0 < kk < N else None
        if sel is None:
            out[a:a + m] = x.mean()
            continue
        sums = x[sel].sum(axis=1)
        out[a:a + m] = (tot - sums) / k if comp else sums / k
    return out


def rng_for(key: str) -> np.random.Generator:
    return np.random.default_rng(zlib.crc32(key.encode()))


def basic(net, gross, fund, exit_t):
    n = len(net)
    if n == 0:
        return {"n": 0}
    sd = float(np.std(net, ddof=1)) if n > 1 else None
    se = sd / np.sqrt(n) if sd is not None else None
    t = float(np.mean(net) / se) if se else None
    return {"n": n, "gross": float(np.mean(gross)), "net": float(np.mean(net)), "net_maker_proxy": float(np.mean(gross - MAKER_COST - fund)),
            "sd": sd, "t": t, "mde": float(MDE_FACTOR * se) if se else None, "pf": pf(net), "mdd": mdd(net, exit_t),
            "win": float((net > 0).mean())}


def summarize(P: dict, keep: np.ndarray, tests: bool, key: str) -> dict:
    """P: pooled arrays (net, gross, fund, entry_t, exit_t, coin). keep: bool per trade."""
    net, gross, fund, et, xt, coin = P["net"], P["gross"], P["fund"], P["entry_t"], P["exit_t"], P["coin"]
    keep = np.asarray(keep, bool)
    r = {"all": basic(net, gross, fund, xt), "kept": basic(net[keep], gross[keep], fund[keep], xt[keep])}
    r["retention"] = float(keep.mean()) if len(keep) else None
    n_all, n_k = len(net), int(keep.sum())
    r["delta"] = (r["kept"]["net"] - r["all"]["net"]) if n_k and n_all else None
    skip = ~keep
    r["skipped_losers"] = {"n": int((skip & (net < 0)).sum()), "sum_net": float(net[skip & (net < 0)].sum())}
    r["skipped_winners"] = {"n": int((skip & (net > 0)).sum()), "sum_net": float(net[skip & (net > 0)].sum())}
    yrs = years_of(et)
    by = {}
    for y in YEARS:
        m = yrs == y
        mk = m & keep
        by[str(y)] = {"n_all": int(m.sum()), "n_kept": int(mk.sum()),
                      "gross_all": mean_or_none(gross[m]), "net_all": mean_or_none(net[m]),
                      "gross_kept": mean_or_none(gross[mk]), "net_kept": mean_or_none(net[mk]),
                      "delta": (float(net[mk].mean() - net[m].mean()) if mk.sum() and m.sum() else None)}
    r["by_year"] = by
    byc = {}
    for c in np.unique(coin):
        m = coin == c
        mk = m & keep
        byc[str(c)] = {"n_all": int(m.sum()), "n_kept": int(mk.sum()), "net_all": mean_or_none(net[m]), "net_kept": mean_or_none(net[mk]),
                       "gross_kept": mean_or_none(gross[mk]),
                       "delta": (float(net[mk].mean() - net[m].mean()) if mk.sum() and m.sum() else None)}
    r["by_coin"] = byc
    if tests and n_k >= 2 and n_all > n_k:
        rng = rng_for(key)
        inv, k = day_index(et)
        nul = random_subset_means(net, n_k, rng)
        obs = float(net[keep].mean())
        r["p_help"] = float((1 + np.sum(nul >= obs - 1e-12)) / (len(nul) + 1))
        r["null_mean"], r["null_sd"] = float(nul.mean()), float(nul.std(ddof=1))
        r["mde_delta"] = float(MDE_FACTOR * nul.std(ddof=1))
        bd = boot_delta(net, keep, inv, k, rng)
        r["ci90_delta"] = [float(np.nanpercentile(bd, 5)), float(np.nanpercentile(bd, 95))]
    if tests and n_k >= 30:
        rng = rng_for(key + "|p")
        inv, k = day_index(et[keep])
        bm = boot_means(net[keep], inv, k, rng)
        r["p_profit"] = float((1 + np.sum(bm <= 0)) / (len(bm) + 1))
        r["ci95_net"] = [float(np.percentile(bm, 2.5)), float(np.percentile(bm, 97.5))]
    return r


def bh(p):
    from edge_search.core import bh_adjust
    p = np.asarray([1.0 if (v is None or not np.isfinite(v)) else v for v in p], float)
    return bh_adjust(p) if len(p) else p
