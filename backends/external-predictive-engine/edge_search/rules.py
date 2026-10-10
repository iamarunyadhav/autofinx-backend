"""Rule families F1–F6 with the frozen grids of EDGE_SEARCH_V1.md Part B3. Every signal is causal: it uses only bars
that are complete at the close of bar t (and ctx features sampled at that close). Output: int8 array, +1 long, -1 short."""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as sw

from v4 import strategy_v13 as v13

from .core import DAY, DISCOVERY, HOUR, HS, SYMBOLS

shift = v13.shift
PAIRS = [(8, 21), (12, 26), (20, 50)]
ORDERED = {"i", "n", "factor", "lo", "k", "r", "p", "thr", "hour", "day"}      # keys whose grid has an order


@dataclass(frozen=True)
class Spec:
    family: str
    name: str
    params: tuple                 # tuple of (key, value)
    label: str
    tfs: tuple
    coins: tuple = DISCOVERY
    hs: tuple | None = None
    need: tuple = ()

    @property
    def sid(self) -> str:
        return f"{self.family}.{self.name}[{self.label}]"

    def p(self) -> dict:
        return dict(self.params)


def build_specs() -> list[Spec]:
    S: list[Spec] = []
    add = lambda *a, **k: S.append(Spec(*a, **k))
    t1 = ("15m", "1h", "4h", "1d")
    for i, (f, s) in enumerate(PAIRS):
        add("F1", "ema_cross", (("i", i),), f"{f}/{s}", t1)
    for n in (20, 55, 100):
        add("F1", "donchian", (("n", n),), f"n{n}", t1)
    for f in (2, 3, 4):
        add("F1", "supertrend", (("factor", f),), f"x{f}", t1)
    for n in (10, 30, 90):
        add("F1", "tsmom", (("n", n),), f"n{n}", t1)
    for n in (20, 50, 200):
        add("F1", "price_vs_ma", (("n", n),), f"n{n}", t1)
    t2 = ("5m", "15m", "1h")
    for lo in (30, 25, 20):
        add("F2", "rsi_extreme", (("lo", lo),), f"{lo}/{100 - lo}", t2)
    for k in (2.0, 2.5, 3.0):
        add("F2", "bb_z", (("k", k),), f"k{k}", t2)
    for k in (1.5, 2.0, 3.0):
        add("F2", "vwap_dev", (("k", k),), f"k{k}", t2)
    for n in (24, 48, 96):
        add("F2", "range_fade", (("n", n),), f"n{n}", t2)
    t3 = ("15m", "1h", "4h")
    for p in (10, 20, 30):
        add("F3", "squeeze", (("p", p),), f"p{p}", t3)
    for k in (1.0, 1.5, 2.0):
        add("F3", "atr_break", (("k", k),), f"k{k}", t3)
    for r in (1, 2, 4):
        add("F3", "session_range", (("r", r),), f"r{r}h", ("15m", "1h"))
    for h in range(24):
        for side in (1, -1):
            add("F4", "hour_of_day", (("hour", h), ("side", side)), f"h{h:02d}{'L' if side > 0 else 'S'}", ("1h",), hs=(1, 3))
    for d in range(7):
        for side in (1, -1):
            add("F4", "day_of_week", (("day", d), ("side", side)), f"d{d}{'L' if side > 0 else 'S'}", ("1d",), hs=(1,))
    for kind in ("pre", "post"):
        for side in (1, -1):
            add("F4", "settle", (("kind", kind), ("side", side)), f"{kind}{'L' if side > 0 else 'S'}", ("1h",), hs=(1, 3))
    t5 = ("1h", "4h", "1d")
    for thr in (1.5, 2.0, 3.0):
        add("F5", "funding_extreme", (("thr", thr),), f"z{thr}", t5, need=("ctx",))
        add("F5", "funding_change", (("thr", thr),), f"z{thr}", t5, need=("ctx",))
        add("F5", "ls_global", (("thr", thr),), f"z{thr}", t5, need=("ctx",))
        add("F5", "ls_top", (("thr", thr),), f"z{thr}", t5, need=("ctx",))
        add("F5", "premium", (("thr", thr),), f"z{thr}", t5, need=("premium",))
        for mode in ("cont", "contra"):
            add("F5", "oi_shock", (("mode", mode), ("thr", thr)), f"{mode}-z{thr}", t5, need=("ctx",))
            add("F5", "taker_flow", (("mode", mode), ("thr", thr)), f"{mode}-z{thr}", t5)
    for n in (1, 3, 6):
        add("F6", "btc_leads", (("n", n),), f"n{n}", ("15m", "1h"), coins=("ETHUSDT",), need=("world",))
    for thr in (1.5, 2.0, 3.0):
        add("F6", "ratio_mr", (("thr", thr),), f"z{thr}", ("1h", "4h"), need=("world",))
    for n in (6, 24, 72):
        add("F6", "rs_rank", (("n", n),), f"n{n}", ("1h", "4h"), need=("world",))
    return S


def hs_for(spec: Spec, tf: str) -> tuple:
    return spec.hs if spec.hs is not None else HS[tf]


def neighbours(spec: Spec, specs: list[Spec]) -> list[Spec]:
    """Same family / rule, every other parameter equal, exactly one ordered parameter one grid step away."""
    same = [s for s in specs if s.family == spec.family and s.name == spec.name and s.sid != spec.sid]
    own = spec.p()
    out = []
    for s in same:
        q = s.p()
        diff = [k for k in own if own[k] != q.get(k)]
        if len(diff) != 1 or diff[0] not in ORDERED:
            continue
        k = diff[0]
        grid = sorted({x.p()[k] for x in same + [spec]})
        if abs(grid.index(own[k]) - grid.index(q[k])) == 1:
            out.append(s)
    return out


# ------------------------------------------------------------------------------------------ helpers
def _sig(pos_mask, neg_mask) -> np.ndarray:
    s = np.zeros(len(pos_mask), np.int8)
    s[np.asarray(neg_mask, bool)] = -1
    s[np.asarray(pos_mask, bool)] = 1
    return s


def _sign(x) -> np.ndarray:
    x = np.asarray(x, float)
    s = np.zeros(len(x), np.int8)
    f = np.isfinite(x)
    s[f] = np.sign(x[f]).astype(np.int8)
    return s


def _csum(x, n):
    cs = np.r_[0.0, np.cumsum(x)]
    out = np.full(len(x), np.nan)
    if len(x) >= n:
        out[n - 1:] = cs[n:] - cs[:-n]
    return out


def roll_z(x, n, min_frac: float = 0.8) -> np.ndarray:
    """Trailing z-score over n bars (current bar included); window needs >= min_frac finite values."""
    x = np.asarray(x, float)
    f = np.isfinite(x)
    xs = np.where(f, x, 0.0)
    cnt, s1, s2 = _csum(f.astype(float), n), _csum(xs, n), _csum(xs * xs, n)
    with np.errstate(invalid="ignore", divide="ignore"):
        m = s1 / cnt
        var = s2 / cnt - m * m
        z = (x - m) / np.sqrt(var)
    z[(cnt < n * min_frac) | ~(var > 1e-18) | ~f] = np.nan
    return z


def roll_std(x, n, chunk: int = 60000) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if len(x) < n:
        return out
    W = sw(x, n)
    for a in range(0, W.shape[0], chunk):
        out[n - 1 + a: n - 1 + a + len(W[a:a + chunk])] = W[a:a + chunk].std(axis=1)
    return out


def pct_rank(x, n: int = 100, chunk: int = 40000) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if len(x) < n:
        return out
    W = sw(x, n)
    for a in range(0, W.shape[0], chunk):
        w = W[a:a + chunk]
        cur = w[:, -1]
        out[n - 1 + a: n - 1 + a + len(w)] = np.where(np.isfinite(cur), (w <= cur[:, None]).mean(axis=1) * 100.0, np.nan)
    return out


def _days_30(tf_min: int) -> int:
    return max(30, int(30 * 1440 // tf_min))


# ------------------------------------------------------------------------------------------ F1
def sig_ema_cross(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    f, s = PAIRS[p["i"]]
    return _sign(v13.ema(b.c, f) - v13.ema(b.c, s))


def sig_donchian(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    hi, lo = shift(v13.highest(b.h, p["n"]), 1), shift(v13.lowest(b.l, p["n"]), 1)
    with np.errstate(invalid="ignore"):
        return _sig(b.c > hi, b.c < lo)


def sig_supertrend(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    _, d = v13.supertrend(b.h, b.l, b.c, float(p["factor"]), 10)
    s = (-d).astype(np.int8)
    s[~np.isfinite(b.c)] = 0
    return s


def sig_tsmom(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    with np.errstate(invalid="ignore", divide="ignore"):
        return _sign(b.c / shift(b.c, p["n"]) - 1.0)


def sig_price_vs_ma(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    return _sign(b.c - v13.sma(b.c, p["n"]))


# ------------------------------------------------------------------------------------------ F2
def sig_rsi_extreme(w, coin, tf, p):
    r = v13.rsi(w.coins[coin].bars[tf].c, 14)
    with np.errstate(invalid="ignore"):
        return _sig(r < p["lo"], r > 100 - p["lo"])


def sig_bb_z(w, coin, tf, p):
    c = w.coins[coin].bars[tf].c
    with np.errstate(invalid="ignore", divide="ignore"):
        z = (c - v13.sma(c, 20)) / roll_std(c, 20)
        return _sig(z < -p["k"], z > p["k"])


def sig_vwap_dev(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    ok = np.isfinite(b.c) & np.isfinite(b.v)
    vw = v13.session_vwap(b.open_time, np.where(ok, b.h, 0.0), np.where(ok, b.l, 0.0), np.where(ok, b.c, 0.0), np.where(ok, b.v, 0.0))
    with np.errstate(invalid="ignore", divide="ignore"):
        dev = (b.c - vw) / b.atr
        s = _sig(dev < -p["k"], dev > p["k"])
    s[~ok] = 0
    return s


def sig_range_fade(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    hi, lo = v13.highest(b.h, p["n"]), v13.lowest(b.l, p["n"])
    with np.errstate(invalid="ignore", divide="ignore"):
        pos = (b.c - lo) / (hi - lo)
        adx = v13.dmi(b.h, b.l, b.c, 14, 14)[2]
        quiet = adx < 20
        return _sig((pos <= 0.1) & quiet, (pos >= 0.9) & quiet)


# ------------------------------------------------------------------------------------------ F3
def sig_squeeze(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    with np.errstate(invalid="ignore", divide="ignore"):
        bbw = 4.0 * roll_std(b.c, 20) / v13.sma(b.c, 20)
        pr = pct_rank(bbw, 100)
        sq = np.nan_to_num(pr, nan=100.0) < p["p"]
        recent = (_csum(sq.astype(float), 3) > 0)
        hi, lo = shift(v13.highest(b.h, 20), 1), shift(v13.lowest(b.l, 20), 1)
        return _sig(recent & (b.c > hi), recent & (b.c < lo))


def sig_atr_break(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    with np.errstate(invalid="ignore"):
        d = b.c - shift(b.c, 1)
        a = shift(b.atr, 1)
        return _sig(d > p["k"] * a, d < -p["k"] * a)


def sig_session_range(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    R = p["r"] * HOUR
    intra = b.open_time % DAY
    day = b.open_time // DAY
    _, inv = np.unique(day, return_inverse=True)
    inr = (intra + b.tf_ms <= R) & np.isfinite(b.h)
    hi = np.full(inv.max() + 1, -np.inf)
    lo = np.full(inv.max() + 1, np.inf)
    np.maximum.at(hi, inv[inr], b.h[inr])
    np.minimum.at(lo, inv[inr], b.l[inr])
    after = intra >= R
    with np.errstate(invalid="ignore"):
        return _sig(after & (b.c > hi[inv]) & np.isfinite(hi[inv]), after & (b.c < lo[inv]) & np.isfinite(lo[inv]))


# ------------------------------------------------------------------------------------------ F4
def _hour(b):
    return (b.close_time % DAY) // HOUR


def sig_hour_of_day(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    s = np.zeros(b.n, np.int8)
    s[_hour(b) == p["hour"]] = p["side"]
    return s


def sig_day_of_week(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    wd = ((b.close_time // DAY) + 3) % 7                    # 1970-01-01 was a Thursday; Monday = 0
    s = np.zeros(b.n, np.int8)
    s[wd == p["day"]] = p["side"]
    return s


def sig_settle(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    hrs = (23, 7, 15) if p["kind"] == "pre" else (0, 8, 16)
    s = np.zeros(b.n, np.int8)
    s[np.isin(_hour(b), hrs)] = p["side"]
    return s


# ------------------------------------------------------------------------------------------ F5
def _z_ctx(w, coin, tf, name):
    c = w.coins[coin]
    x = c.ctx(name, tf)
    return None if x is None else x, c.bars[tf]


def _contra(z, thr):
    return _sig(z < -thr, z > thr)


def sig_funding_extreme(w, coin, tf, p):
    z, _ = _z_ctx(w, coin, tf, "ctx_funding_z90")
    return None if z is None else _contra(z, p["thr"])


def sig_funding_change(w, coin, tf, p):
    f, b = _z_ctx(w, coin, tf, "ctx_funding_last")
    if f is None:
        return None
    k = max(1, 1440 // b.tf_min)
    z = roll_z(f - shift(f, k), _days_30(b.tf_min))
    return _contra(z, p["thr"])


def sig_ls_global(w, coin, tf, p):
    x, b = _z_ctx(w, coin, tf, "ctx_global_ls")
    return None if x is None else _contra(roll_z(x, _days_30(b.tf_min)), p["thr"])


def sig_ls_top(w, coin, tf, p):
    x, b = _z_ctx(w, coin, tf, "ctx_top_ls_sum")
    return None if x is None else _contra(roll_z(x, _days_30(b.tf_min)), p["thr"])


def sig_oi_shock(w, coin, tf, p):
    x, b = _z_ctx(w, coin, tf, "ctx_oi_chg_4h")
    if x is None:
        return None
    z = roll_z(x, _days_30(b.tf_min))
    k = max(1, 240 // b.tf_min)
    with np.errstate(invalid="ignore", divide="ignore"):
        d = _sign(np.log(b.c / shift(b.c, k)))
    shock = np.nan_to_num(z, nan=0.0) > p["thr"]
    s = np.where(shock, d, 0).astype(np.int8)
    return s if p["mode"] == "cont" else (-s).astype(np.int8)


def sig_taker_flow(w, coin, tf, p):
    b = w.coins[coin].bars[tf]
    with np.errstate(invalid="ignore", divide="ignore"):
        z = roll_z(b.tbv / b.v - 0.5, _days_30(b.tf_min))
    s = _sig(z > p["thr"], z < -p["thr"])
    return s if p["mode"] == "cont" else (-s).astype(np.int8)


def sig_premium(w, coin, tf, p):
    f = w.coins[coin].premium(tf)
    return None if f is None else _contra(f["ctx_premium_z24h"], p["thr"])


# ------------------------------------------------------------------------------------------ F6
def sig_btc_leads(w, coin, tf, p):
    b = w.coins["BTCUSDT"].bars[tf]
    with np.errstate(invalid="ignore", divide="ignore"):
        return _sign(np.log(b.c / shift(b.c, p["n"])))


def sig_ratio_mr(w, coin, tf, p):
    e, bt = w.coins["ETHUSDT"].bars[tf], w.coins["BTCUSDT"].bars[tf]
    with np.errstate(invalid="ignore", divide="ignore"):
        z = roll_z(np.log(e.c / bt.c), _days_30(e.tf_min))
    s = _sig(z < -p["thr"], z > p["thr"])                     # ETH cheap -> long ETH, ETH rich -> short ETH
    return s if coin == "ETHUSDT" else (-s).astype(np.int8)


def sig_rs_rank(w, coin, tf, p):
    rets = []
    for s in SYMBOLS:
        c = w.coins[s].bars[tf].c
        with np.errstate(invalid="ignore", divide="ignore"):
            rets.append(np.log(c / shift(c, p["n"])))
    R = np.vstack(rets)
    fin = np.isfinite(R)
    me = R[SYMBOLS.index(coin)]
    mx = np.where(fin, R, -np.inf).max(axis=0)
    mn = np.where(fin, R, np.inf).min(axis=0)
    enough = fin.sum(axis=0) >= 4
    with np.errstate(invalid="ignore"):
        return _sig(enough & (me == mx), enough & (me == mn))


SIGNALS = {
    ("F1", "ema_cross"): sig_ema_cross, ("F1", "donchian"): sig_donchian, ("F1", "supertrend"): sig_supertrend,
    ("F1", "tsmom"): sig_tsmom, ("F1", "price_vs_ma"): sig_price_vs_ma,
    ("F2", "rsi_extreme"): sig_rsi_extreme, ("F2", "bb_z"): sig_bb_z, ("F2", "vwap_dev"): sig_vwap_dev,
    ("F2", "range_fade"): sig_range_fade,
    ("F3", "squeeze"): sig_squeeze, ("F3", "atr_break"): sig_atr_break, ("F3", "session_range"): sig_session_range,
    ("F4", "hour_of_day"): sig_hour_of_day, ("F4", "day_of_week"): sig_day_of_week, ("F4", "settle"): sig_settle,
    ("F5", "funding_extreme"): sig_funding_extreme, ("F5", "funding_change"): sig_funding_change,
    ("F5", "ls_global"): sig_ls_global, ("F5", "ls_top"): sig_ls_top, ("F5", "oi_shock"): sig_oi_shock,
    ("F5", "taker_flow"): sig_taker_flow, ("F5", "premium"): sig_premium,
    ("F6", "btc_leads"): sig_btc_leads, ("F6", "ratio_mr"): sig_ratio_mr, ("F6", "rs_rank"): sig_rs_rank,
}


def signal(spec: Spec, world, coin: str, tf: str):
    """int8 signal array, or None when the data this rule needs does not exist for the coin."""
    return SIGNALS[(spec.family, spec.name)](world, coin, tf, spec.p())
