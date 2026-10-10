"""Day-block statistics for the replay (plan section 4). Items are built from per-day arrays [n, sum gross, sum f]; net(c) = gross - c n - f.

effect = sum(num1) / sum(den1) - sum(num2) / sum(den2) (second term optional): a profit item has one term, an exit delta two terms over the same trades,
a gate delta two terms (kept minus all). SE from a day-block bootstrap (whole UTC days resampled jointly across terms and across the pooled coins);
one-sided p = 1 - Phi(effect / SE).
"""

from __future__ import annotations

import zlib

import numpy as np
from scipy import stats as sps

from edge_search.core import MDE_FACTOR, bh_adjust

N_BOOT = 2000
MDE_OK = 0.06          # % per trade
FAIL_UPPER = 0.03      # % per trade
MATERIAL = 0.03        # % per trade: a LEAD must also be economically material (AMENDMENT 1)


def rng_for(key: str) -> np.random.Generator:
    return np.random.default_rng(zlib.crc32(key.encode()))


def net_cols(a, cost):
    """(num, den) of mean net per trade for a per-day array."""
    return a[:, 1] - a[:, 2] - cost * a[:, 0], a[:, 0]


def terms(item: dict, arrs: dict, cost: float) -> list:
    """[(num, den, sign)] for an item: ('P', key) / ('DX', key, base) / ('DG', kept, all)."""
    t = item["type"]
    if t == "P":
        n, d = net_cols(arrs[item["key"]], cost)
        return [(n, d, 1.0)]
    if t == "DX":
        a, b = arrs[item["key"]], arrs[item["base"]]
        n = (a[:, 1] - a[:, 2]) - (b[:, 1] - b[:, 2])
        return [(n, a[:, 0], 1.0)]
    if t == "DG":
        nk, dk = net_cols(arrs[item["key"]], cost)
        na, da = net_cols(arrs[item["base"]], cost)
        return [(nk, dk, 1.0), (na, da, -1.0)]
    if t == "DD":      # difference in differences vs the matched random-direction null (same entries, random sides)
        out = []
        for k, sg in ((item["key"], 1.0), (item["base"], -1.0), (item["nkey"], -1.0), (item["nbase"], 1.0)):
            n, d = net_cols(arrs[k], cost)
            out.append((n, d, sg))
        return out
    raise KeyError(t)


def point(tms, mask=None) -> float | None:
    tot = 0.0
    for n, d, s in tms:
        if mask is not None:
            n, d = n[mask], d[mask]
        den = d.sum()
        if den <= 0:
            return None
        tot += s * n.sum() / den
    return float(tot)


def boot(tms, key: str, B: int = N_BOOT, chunk: int = 500) -> np.ndarray:
    rng = rng_for(key)
    D = len(tms[0][0])
    out = np.empty(B)
    for a in range(0, B, chunk):
        m = min(chunk, B - a)
        idx = rng.integers(0, D, size=(m, D))
        acc = np.zeros(m)
        for n, d, s in tms:
            den = d[idx].sum(axis=1)
            acc += s * np.where(den > 0, n[idx].sum(axis=1) / np.maximum(den, 1e-12), np.nan)
        out[a:a + m] = acc
    return out


def evaluate(item: dict, arrs: dict, cost: float, key: str) -> dict:
    tms = terms(item, arrs, cost)
    eff = point(tms)
    n = float(arrs[item["key"]][:, 0].sum())
    r = {"effect": eff, "n": n}
    if eff is None or n < 30:
        r.update({"se": None, "p": 1.0, "mde": None, "ci90": None})
        return r
    b = boot(tms, key)
    se = float(np.nanstd(b, ddof=1))
    r["se"] = se
    r["mde"] = float(MDE_FACTOR * se) if se > 0 else None
    r["ci90"] = [eff - 1.645 * se, eff + 1.645 * se]
    r["p"] = float(sps.norm.sf(eff / se)) if se > 0 else (0.0 if eff > 0 else 1.0)
    return r


def verdict(r: dict, q: float | None, by_year: list, by_coin: list) -> str:
    """LEAD / FAIL / INCONCLUSIVE per the frozen plan (section 4)."""
    if r.get("effect") is None or r.get("se") is None or r.get("mde") is None:
        return "INCONCLUSIVE"
    lead = (q is not None and q < 0.05 and r["ci90"][0] > 0 and r["effect"] >= MATERIAL and r["mde"] <= MDE_OK
            and all(v is not None and v > 0 for v in by_year) and all(v is not None and v > 0 for v in by_coin))
    if lead:
        return "LEAD"
    if r["ci90"][1] < FAIL_UPPER and r["mde"] <= MDE_OK:
        return "FAIL"
    return "INCONCLUSIVE"


def bh(p) -> np.ndarray:
    p = np.asarray([1.0 if (v is None or not np.isfinite(v)) else v for v in p], float)
    return bh_adjust(p) if len(p) else p


def wilson(w: float, n: float, z: float = 1.96):
    if n <= 0:
        return None, None
    p = w / n
    den = 1 + z * z / n
    c = (p + z * z / (2 * n)) / den
    h = z * np.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / den
    return float(c - h), float(c + h)
