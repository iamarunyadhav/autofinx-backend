"""Edge Search v1 core (docs/ml-engine-v1/EDGE_SEARCH_V1.md, Part B).

Sealed data loading, closed-candle bars, the trade engine (signal on bar close, fill at the next open, time or
ATR-stop exits, greedy non-overlap, actual funding) and the statistics (t, bootstrap, BH-FDR, random-entry null).
Pure numpy; nothing here reads the holdout unless a World is built with include_hold=True (HoldoutGate only).
"""

from __future__ import annotations

import json
import math
import os
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw
from scipy import stats as sps

from v4 import binance_data as bd
from v4 import splits
from v4 import strategy_v13 as v13
from v4.download import load_funding

MIN = 60_000
HOUR = 3_600_000
DAY = 86_400_000
M5 = 300_000
TF_MIN = {"5m": 5, "15m": 15, "1h": 60, "4h": 240, "1d": 1440}
TFS = tuple(TF_MIN)
SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")
DISCOVERY = ("BTCUSDT", "ETHUSDT")
HS = {"5m": (1, 3, 6, 12, 24), "15m": (1, 3, 6, 12, 24), "1h": (1, 3, 6, 12, 24), "4h": (1, 3, 6), "1d": (1,)}
FEE, SLIP = 0.05, 0.01
COST_BASE = 2 * (FEE + SLIP)             # 0.12 % round trip
COST_STRESS_A = 2 * (FEE + 2 * SLIP)     # slippage x2 = 0.14 %
GROSS_MIN = 0.5 * COST_BASE              # 0.06 %
MDE_FACTOR = 2.8                         # 80 % power, two-sided 5 %


# ------------------------------------------------------------------------------------------ segments
def compute_segments(work: Path, symbols=SYMBOLS) -> dict:
    """Per-symbol DEV / VAL / HOLDOUT from the existing sealed split plus the global seal."""
    out = {}
    for s in symbols:
        m5 = bd.load_klines(work, s, "5m")
        a = (m5["open_time"] + M5).astype(np.int64)
        tr, va = splits.dev_train_val(a)
        out[s] = {"first_open": int(m5["open_time"].min()), "last_close": int(a.max()),
                  "hold_start": int(splits.holdout_start_ms(a)), "dev_end": int(splits.dev_end_ms(a)),
                  "train_end": int(a[tr].max()), "val_start": int(a[va].min())}
    cut = min(v["hold_start"] for v in out.values())
    dev_end = cut - 1440 * MIN
    for v in out.values():
        v["val_end"] = min(v["dev_end"], dev_end)
    return {"global_cut": cut, "global_dev_end": dev_end, "symbols": out}


def windows(seg: dict, symbol: str) -> dict[str, tuple[int, int]]:
    s = seg["symbols"][symbol]
    return {"dev": (s["first_open"], s["train_end"]), "val": (s["val_start"], s["val_end"]),
            "hold": (s["hold_start"], s["last_close"])}


# ------------------------------------------------------------------------------------------ bars
class Bars:
    def __init__(self, tf: str, t0: int, o, h, l, c, v, tbv):
        self.tf, self.tf_min = tf, TF_MIN[tf]
        self.tf_ms = self.tf_min * MIN
        self.t0 = t0
        self.o, self.h, self.l, self.c, self.v, self.tbv = o, h, l, c, v, tbv
        self.n = len(o)
        self.open_time = t0 + np.arange(self.n, dtype=np.int64) * self.tf_ms
        self.close_time = self.open_time + self.tf_ms
        self.cache: dict = {}

    @property
    def atr(self) -> np.ndarray:
        if "atr" not in self.cache:
            self.cache["atr"] = v13.rma(v13.true_range(self.h, self.l, self.c), 14)
        return self.cache["atr"]


def _full_5m(k5: dict, t0: int, n5: int) -> dict:
    idx = ((k5["open_time"] - t0) // M5).astype(np.int64)
    ok = (idx >= 0) & (idx < n5)
    full = {}
    for key in ("open", "high", "low", "close", "volume", "taker_buy_volume"):
        x = np.full(n5, np.nan)
        x[idx[ok]] = np.asarray(k5[key], float)[ok]
        full[key] = x
    return full


def bars_from_full(full: dict, t0: int, n5: int, tf: str) -> Bars:
    k = TF_MIN[tf] // 5
    m = n5 // k
    r = lambda key: full[key][: m * k].reshape(m, k)
    o, c = r("open")[:, 0].copy(), r("close")[:, -1].copy()
    h, l, v, tb = r("high").max(axis=1), r("low").min(axis=1), r("volume").sum(axis=1), r("taker_buy_volume").sum(axis=1)
    # closed-candle rule: a bar exists only if EVERY 5m bar inside it exists (a missing 5m bar is NaN in all fields,
    # and NaN propagates through max / min / sum), so open and close are blanked too, otherwise close-only
    # indicators would read an incomplete bar
    incomplete = ~(np.isfinite(h) & np.isfinite(l) & np.isfinite(v))
    o[incomplete] = np.nan
    c[incomplete] = np.nan
    return Bars(tf, t0, o, h, l, c, v, tb)


class Coin:
    """One symbol: bars for every timeframe, actual funding, optional ctx / premium features."""

    def __init__(self, symbol: str, t0: int, n5: int, k5: dict, funding: dict, work: Path | None = None, tfs=TFS):
        self.symbol, self.work, self.t0, self.n5 = symbol, work, t0, n5
        full = _full_5m(k5, t0, n5)
        self.bars = {tf: bars_from_full(full, t0, n5, tf) for tf in tfs}
        cut = t0 + n5 * M5
        ft = np.asarray(funding["funding_time"], np.int64)
        keep = ft <= cut
        self.f_t = ft[keep]
        self.f_r = np.asarray(funding["funding_rate"], float)[keep] * 100.0        # % per event, long pays > 0
        self.f_cum = np.r_[0.0, np.cumsum(self.f_r)]
        self._ctx = None
        self._ctx_pos: dict = {}
        self._prem = None

    def funding(self, t_entry, t_exit) -> np.ndarray:
        """Sum of funding rates (%) with entry < event <= exit."""
        return (self.f_cum[np.searchsorted(self.f_t, t_exit, side="right")]
                - self.f_cum[np.searchsorted(self.f_t, t_entry, side="right")])

    def ctx(self, name: str, tf: str):
        """ctx.parquet column sampled at the close of each tf bar (NaN when absent). None if no file."""
        if self._ctx is None:
            p = (self.work or Path(".")) / "features" / self.symbol / "ctx.parquet"
            self._ctx = bd.read_parquet(p) if p.exists() else {}
        if not self._ctx or name not in self._ctx:
            return None
        if tf not in self._ctx_pos:
            a = np.asarray(self._ctx["anchor_time_ms"], np.int64)
            ct = self.bars[tf].close_time
            pos = np.minimum(np.searchsorted(a, ct), len(a) - 1)
            self._ctx_pos[tf] = (pos, a[pos] == ct)
        pos, ok = self._ctx_pos[tf]
        return np.where(ok, np.asarray(self._ctx[name], float)[pos], np.nan)

    def premium(self, tf: str):
        from v4 import premium
        if self._prem is None:
            self._prem = {}
        if tf not in self._prem:
            p = premium.load(self.work, self.symbol)
            self._prem[tf] = premium.features(self.bars[tf].close_time, p) if len(p["open_time"]) else None
        return self._prem[tf]


class World:
    def __init__(self, work: Path, seg: dict, include_hold: bool = False, symbols=SYMBOLS, tfs=TFS):
        self.work, self.seg = Path(work), seg
        firsts = [seg["symbols"][s]["first_open"] for s in symbols]
        self.t0 = (min(firsts) // DAY) * DAY
        if include_hold:
            cut = max(seg["symbols"][s]["last_close"] for s in symbols)
            n5 = int(math.ceil((cut - self.t0) / M5 / 288)) * 288
        else:
            cut = seg["global_dev_end"]
            n5 = int((cut - self.t0) // M5 // 288) * 288
        self.n5, self.include_hold = n5, include_hold
        self.coins = {}
        for s in symbols:
            m5 = bd.load_klines(self.work, s, "5m")
            self.coins[s] = Coin(s, self.t0, n5, m5, load_funding(self.work, s), self.work, tfs)


# ------------------------------------------------------------------------------------------ outcomes
@dataclass
class Outcomes:
    g_long: np.ndarray
    g_short: np.ndarray
    ex_long: np.ndarray
    ex_short: np.ndarray
    valid: np.ndarray


def nan_free_windows(b: Bars, H: int) -> np.ndarray:
    """valid[t] = bars t+1..t+H all complete (so entry at open t+1 and exit at close t+H are defined)."""
    bad = ~(np.isfinite(b.o) & np.isfinite(b.h) & np.isfinite(b.l) & np.isfinite(b.c))
    pref = np.r_[0, np.cumsum(bad)]
    ok = np.zeros(b.n, bool)
    if b.n > H:
        t = np.arange(b.n - H)
        ok[: b.n - H] = (pref[t + 1 + H] - pref[t + 1]) == 0
    return ok


def outcomes_time(b: Bars, H: int) -> Outcomes:
    n = b.n
    ok = nan_free_windows(b, H)
    g = np.full(n, np.nan)
    t = np.nonzero(ok)[0]
    g[t] = (b.c[t + H] / b.o[t + 1] - 1.0) * 100.0
    ex = np.minimum(np.arange(n) + H, n - 1)
    return Outcomes(g, -g, ex, ex, ok)


def outcomes_atr(b: Bars, H: int, k: float = 1.5) -> Outcomes:
    """ATR(14) stop at k x ATR of the signal bar from the entry price + time exit; stop wins within a bar,
    an opening gap through the stop exits at the open."""
    n = b.n
    atr = b.atr
    ok = nan_free_windows(b, H) & np.isfinite(atr) & (b.c > 0)
    idx = np.nonzero(ok)[0]
    g_long, g_short = np.full(n, np.nan), np.full(n, np.nan)
    ex_long = np.minimum(np.arange(n) + H, n - 1)
    ex_short = ex_long.copy()
    if len(idx) == 0:
        return Outcomes(g_long, g_short, ex_long, ex_short, ok)
    E = b.o[idx + 1]
    d = k * atr[idx] / b.c[idx]
    Wl, Wh, Wo = sw(b.l, H)[idx + 1], sw(b.h, H)[idx + 1], sw(b.o, H)[idx + 1]
    row = np.arange(len(idx))
    timepx = b.c[idx + H]
    # long
    S = E * (1.0 - d)
    hit = Wl <= S[:, None]
    any_hit = hit.any(axis=1)
    k0 = hit.argmax(axis=1)
    opn = Wo[row, k0]
    px = np.where(any_hit, np.where(opn <= S, opn, S), timepx)
    g_long[idx] = (px / E - 1.0) * 100.0
    ex_long[idx] = np.where(any_hit, idx + 1 + k0, idx + H)
    # short
    S = E * (1.0 + d)
    hit = Wh >= S[:, None]
    any_hit = hit.any(axis=1)
    k0 = hit.argmax(axis=1)
    opn = Wo[row, k0]
    px = np.where(any_hit, np.where(opn >= S, opn, S), timepx)
    g_short[idx] = -(px / E - 1.0) * 100.0
    ex_short[idx] = np.where(any_hit, idx + 1 + k0, idx + H)
    return Outcomes(g_long, g_short, ex_long, ex_short, ok)


def net_all(out: Outcomes, coin: Coin, b: Bars, cost: float):
    """Per-bar net % for a long / short entry made on every bar (used by the random-entry null and benchmarks)."""
    ct = b.close_time
    n = b.n
    fl = coin.funding(ct, ct[np.minimum(out.ex_long, n - 1)])
    fs = coin.funding(ct, ct[np.minimum(out.ex_short, n - 1)])
    return out.g_long - cost - fl, out.g_short - cost + fs


# ------------------------------------------------------------------------------------------ trades
@dataclass
class Trades:
    idx: np.ndarray
    side: np.ndarray
    gross: np.ndarray
    entry_t: np.ndarray
    exit_t: np.ndarray
    ex_idx: np.ndarray

    def __len__(self):
        return len(self.idx)

    def net(self, coin: Coin, cost: float) -> np.ndarray:
        if len(self.idx) == 0:
            return np.zeros(0)
        return self.gross - cost - self.side * coin.funding(self.entry_t, self.exit_t)


def select_trades(sig: np.ndarray, out: Outcomes, ct: np.ndarray, lo: int, hi: int) -> Trades:
    """Greedy non-overlapping trades: take the first signal bar, then the first signal bar >= its exit bar."""
    n = len(sig)
    side = sig.astype(np.int8)
    ex = np.where(side > 0, out.ex_long, out.ex_short)
    gross = np.where(side > 0, out.g_long, out.g_short)
    inwin = out.valid & (side != 0) & np.isfinite(gross) & (ct >= lo) & (ct[np.minimum(ex, n - 1)] <= hi)
    cand = np.nonzero(inwin)[0]
    picked = []
    i, m = 0, len(cand)
    exc = ex[cand]
    while i < m:
        picked.append(i)
        i = int(np.searchsorted(cand, exc[i], side="left"))
    p = np.array(picked, dtype=np.int64)
    idx = cand[p] if len(p) else np.zeros(0, np.int64)
    return Trades(idx, side[idx], gross[idx], ct[idx], ct[np.minimum(ex[idx], n - 1)] if len(idx) else np.zeros(0, np.int64),
                  ex[idx])


# ------------------------------------------------------------------------------------------ statistics
def describe(x) -> dict:
    x = np.asarray(x, float)
    n = len(x)
    if n < 2:
        return {"n": n, "mean": float(x.mean()) if n else None, "sd": None, "t": None, "p1": 1.0, "mde": None, "hit": None}
    mean, sd = float(x.mean()), float(x.std(ddof=1))
    se = sd / math.sqrt(n)
    t = mean / se if se > 0 else None
    return {"n": n, "mean": mean, "sd": sd, "t": t, "p1": float(sps.t.sf(t, n - 1)) if t is not None else 1.0,
            "mde": MDE_FACTOR * se, "hit": float((x > 0).mean())}


def block_ci(x, t_ms, level: float = 0.95, n_boot: int = 2000, seed: int = 11):
    """Moving-block bootstrap of the mean using whole UTC-day blocks of trades."""
    x = np.asarray(x, float)
    if len(x) < 30:
        return None
    day = np.asarray(t_ms, np.int64) // DAY
    uniq, inv = np.unique(day, return_inverse=True)
    sums, counts = np.bincount(inv, weights=x), np.bincount(inv)
    k = len(uniq)
    if k < 10:
        return None
    rng = np.random.default_rng(seed)
    idx = rng.integers(0, k, size=(n_boot, k))
    means = sums[idx].sum(axis=1) / np.maximum(counts[idx].sum(axis=1), 1)
    a = (1 - level) / 2 * 100
    return [float(np.percentile(means, a)), float(np.percentile(means, 100 - a))]


def bh_reject(p, q: float = 0.05):
    p = np.asarray(p, float)
    m = len(p)
    order = np.argsort(p)
    ok = p[order] <= q * np.arange(1, m + 1) / m
    out = np.zeros(m, bool)
    if ok.any():
        out[order[: np.nonzero(ok)[0].max() + 1]] = True
    return out


def bh_adjust(p):
    p = np.asarray(p, float)
    m = len(p)
    order = np.argsort(p)
    ranked = p[order] * m / np.arange(1, m + 1)
    adj = np.minimum.accumulate(ranked[::-1])[::-1]
    out = np.empty(m)
    out[order] = np.minimum(adj, 1.0)
    return out


def random_null(nl: np.ndarray, ns: np.ndarray, valid: np.ndarray, lo_idx: int, hi_idx: int, n_trades: int, long_frac: float,
                H: int, n_mc: int = 1000, seed: int = 7) -> np.ndarray:
    """Mean net of n_trades random entries (same long share, spacing H = non-overlapping) per MC run."""
    rng = np.random.default_rng(seed)
    pool = np.nonzero(valid & np.isfinite(nl) & np.isfinite(ns))[0]
    pool = pool[(pool >= lo_idx) & (pool <= hi_idx)]
    if len(pool) < n_trades or n_trades < 1:
        return np.zeros(0)
    by_off = [pool[(pool % H) == o] for o in range(H)]
    by_off = [a for a in by_off if len(a) >= n_trades]
    if not by_off:
        by_off = [pool]
        n_trades = min(n_trades, len(pool))
    n_long = int(round(long_frac * n_trades))
    out = np.empty(n_mc)
    for r in range(n_mc):
        cand = by_off[rng.integers(len(by_off))]
        pick = cand[rng.choice(len(cand), n_trades, replace=False)]
        out[r] = (nl[pick[:n_long]].sum() + ns[pick[n_long:]].sum()) / n_trades
    return out


def clean(o):
    """JSON-safe copy (numpy -> python, NaN/inf -> None)."""
    if isinstance(o, dict):
        return {str(k): clean(v) for k, v in o.items()}
    if isinstance(o, (list, tuple)):
        return [clean(v) for v in o]
    if isinstance(o, np.ndarray):
        return clean(o.tolist())
    if isinstance(o, (np.floating, float)):
        return None if not math.isfinite(float(o)) else float(o)
    if isinstance(o, (np.integer,)):
        return int(o)
    if isinstance(o, (np.bool_,)):
        return bool(o)
    return o


def atomic_write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(text, "utf-8")
    os.replace(tmp, path)


def write_json(path: Path, obj) -> None:
    atomic_write(path, json.dumps(clean(obj), indent=1))


def read_json_safe(path: Path):
    try:
        return json.loads(Path(path).read_text("utf-8"))
    except Exception:
        return None
