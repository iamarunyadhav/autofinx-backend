"""Strategy Lab candidate #1 — owner's Pine script "AutoFinX v13 (Final)" (uploaded 2026-10-07), translated.

Research only. The Pine source is stored verbatim in docs/ml-engine-v1/strategy-lab/candidates/autofinx-v13/
and is never executed. This module re-implements its supported logic with Pine semantics:
  ta.ema / ta.rma seeded with the SMA of the first `length` values, Wilder ATR / DMI / RSI, ta.supertrend,
  session VWAP (UTC day), choppiness index, the Donchian break latch, and
  request.security(f()[1], lookahead_off) read as "the last COMPLETE higher-timeframe bar that ended at or
  before the chart bar's open" (TradingView realtime semantics; see AUDIT.md for the historical difference).
Default inputs only (dirMode Both, regime/HTF gates off as in the script), NO parameter search.

Roles (owner 2026-10-07: no hard filter, optional evidence only, use it only if proven):
  S0 standalone   the strategy itself under AutoFinX execution — a diagnostic, never a filter
  S1 features     causal per-anchor columns (v13_*) for the fold-consistent ablation after P4B
Normalized execution (AUDIT.md): entry at the 1m open at the signal bar close; SL / TP1 (50%) / ATR trail
(50%) live from entry; 1m path; stop before target in the same minute; gaps fill at the open;
0.12% round-trip cost + actual funding (TradingView setting: 0.08%, no funding — reported alongside).
Data is cut at the holdout start before anything is computed for S0: the holdout stays sealed.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import splits
from .download import load_funding
from .policy import trade_stats
from .targets import MINUTE, anchors_from_5m, dense_minutes

P = {"base_threshold": 70.0, "cooldown": 8, "regime_adx_min": 22.0, "regime_chop_max": 55.0,
     "ema_fast": 9, "ema_slow": 21, "ema_trend": 50, "ema_major": 200, "slope_back": 5,
     "chop_len": 14, "chop_max": 62.0, "rsi_len": 14, "adx_len": 14, "adx_smooth": 14, "vol_len": 20,
     "st_factor": 3.0, "st_period": 7, "macd": (12, 26, 9), "don_len": 20, "body_min": 0.35,
     "tp1_rr": 1.5, "trail_mult": 1.0, "flip_threshold": 80.0}
HTF_MS = (3_600_000, 14_400_000, 86_400_000)          # input htf1 "60", htf2 "240", htf3 "D"
CHART_MS = {"5m": 300_000, "15m": 900_000}
COST_AUTOFINX, COST_TRADINGVIEW = 0.12, 0.08
FEATURES = ("v13_long_score", "v13_short_score", "v13_score_diff", "v13_threshold", "v13_long_margin",
            "v13_short_margin", "v13_hard_long", "v13_hard_short", "v13_setup_long", "v13_setup_short",
            "v13_chop", "v13_adx", "v13_di_diff", "v13_st_dir", "v13_don_net", "v13_htf1", "v13_htf2",
            "v13_htf3", "v13_vwap_gap", "v13_relvol", "v13_regime", "v13_vol_regime")


def asset_params(symbol: str) -> tuple[float, float]:
    """(adxMin, slMult) exactly as the script's asset detection."""
    s = symbol.upper()
    if "BTC" in s:
        return 20.0, 1.8
    if "ETH" in s:
        return 20.0, 2.0
    if any(k in s for k in ("DOGE", "XRP", "ADA")):
        return 18.0, 2.5
    return 18.0, 2.0


# ---------------------------------------------------------------- Pine-semantics indicators
def shift(x, k: int = 1) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if k < len(x):
        out[k:] = x[:len(x) - k]
    return out


def sma(x, n: int) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if len(x) < n:
        return out
    finite = np.isfinite(x)
    cs = np.r_[0.0, np.cumsum(np.where(finite, x, 0.0))]
    bad = np.r_[0, np.cumsum(~finite)]
    out[n - 1:] = np.where(bad[n:] - bad[:-n] == 0, (cs[n:] - cs[:-n]) / n, np.nan)
    return out


def _recursive(x, alpha: float, n: int) -> np.ndarray:
    """Pine ta.ema / ta.rma: na until `n` finite values, seeded with their SMA, then recursive."""
    x = np.asarray(x, float)
    seed = sma(x, n)
    out = np.full(len(x), np.nan)
    prev = np.nan
    for i in range(len(x)):
        xi = x[i]
        if xi != xi:
            prev = np.nan
            continue
        prev = seed[i] if prev != prev else alpha * xi + (1.0 - alpha) * prev
        out[i] = prev
    return out


def ema(x, n: int) -> np.ndarray:
    return _recursive(x, 2.0 / (n + 1), n)


def rma(x, n: int) -> np.ndarray:
    return _recursive(x, 1.0 / n, n)


def highest(x, n: int) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if len(x) >= n:
        out[n - 1:] = np.lib.stride_tricks.sliding_window_view(x, n).max(axis=1)
    return out


def lowest(x, n: int) -> np.ndarray:
    x = np.asarray(x, float)
    out = np.full(len(x), np.nan)
    if len(x) >= n:
        out[n - 1:] = np.lib.stride_tricks.sliding_window_view(x, n).min(axis=1)
    return out


def true_range(h, l, c, handle_na: bool = True) -> np.ndarray:
    pc = shift(c)
    tr = np.maximum(h - l, np.maximum(np.abs(h - pc), np.abs(l - pc)))
    if len(tr):
        tr[0] = h[0] - l[0] if handle_na else np.nan
    return tr


def rsi(c, n: int) -> np.ndarray:
    ch = np.r_[np.nan, np.diff(np.asarray(c, float))]
    up, dn = rma(np.where(np.isfinite(ch), np.maximum(ch, 0), np.nan), n), rma(np.where(np.isfinite(ch), np.maximum(-ch, 0), np.nan), n)
    with np.errstate(divide="ignore", invalid="ignore"):
        out = 100.0 - 100.0 / (1.0 + up / dn)
    out = np.where(dn == 0, 100.0, np.where(up == 0, 0.0, out))
    return np.where(np.isfinite(up) & np.isfinite(dn), out, np.nan)


def dmi(h, l, c, n: int, smooth: int) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    up, dn = h - shift(h), shift(l) - l
    plus_dm = np.where(np.isfinite(up), np.where((up > dn) & (up > 0), up, 0.0), np.nan)
    minus_dm = np.where(np.isfinite(dn), np.where((dn > up) & (dn > 0), dn, 0.0), np.nan)
    trur = rma(true_range(h, l, c, handle_na=False), n)
    with np.errstate(divide="ignore", invalid="ignore"):
        plus = 100.0 * rma(plus_dm, n) / trur
        minus = 100.0 * rma(minus_dm, n) / trur
        total = plus + minus
        adx = 100.0 * rma(np.abs(plus - minus) / np.where(total == 0, 1.0, total), smooth)
    return plus, minus, adx


def supertrend(h, l, c, factor: float, period: int) -> tuple[np.ndarray, np.ndarray]:
    """Pine ta.supertrend: direction -1 = up trend (bull), +1 = down trend (bear)."""
    atr_ = rma(true_range(h, l, c), period)
    src = (h + l) / 2.0
    up_raw, lo_raw = src + factor * atr_, src - factor * atr_
    n = len(c)
    st, direction = np.full(n, np.nan), np.full(n, np.nan)
    prev_lo = prev_up = prev_st = np.nan
    for i in range(n):
        pl = prev_lo if prev_lo == prev_lo else 0.0
        pu = prev_up if prev_up == prev_up else 0.0
        cp = c[i - 1] if i else np.nan
        lo = lo_raw[i] if (lo_raw[i] > pl or cp < pl) else pl
        up = up_raw[i] if (up_raw[i] < pu or cp > pu) else pu
        if i == 0 or atr_[i - 1] != atr_[i - 1]:
            d = 1
        elif prev_st == prev_up:
            d = -1 if c[i] > up else 1
        else:
            d = 1 if c[i] < lo else -1
        st[i], direction[i] = (lo if d == -1 else up), d
        prev_lo, prev_up, prev_st = lo, up, st[i]
    return st, direction


def donchian_latch(h, l, c, n: int) -> np.ndarray:
    hh, ll = shift(highest(h, n)), shift(lowest(l, n))
    out = np.zeros(len(c))
    prev = 0.0
    for i in range(len(c)):
        prev = 1.0 if c[i] > hh[i] else (-1.0 if c[i] < ll[i] else prev)
        out[i] = prev
    return out


def session_vwap(open_time, h, l, c, v) -> np.ndarray:
    day = np.asarray(open_time, np.int64) // 86_400_000
    pv = (h + l + c) / 3.0 * v
    start = np.r_[True, day[1:] != day[:-1]]
    group = np.cumsum(start) - 1
    cpv, cv = np.cumsum(pv), np.cumsum(v)
    num = cpv - (cpv - pv)[start][group]
    den = cv - (cv - v)[start][group]
    with np.errstate(divide="ignore", invalid="ignore"):
        return np.where(den > 0, num / den, np.nan)


def resample(bars: dict, tf_ms: int) -> dict:
    """OHLCV buckets aligned to UTC multiples of tf_ms (open_time = bucket start)."""
    t = np.asarray(bars["open_time"], np.int64)
    bucket = t // tf_ms * tf_ms
    starts = np.r_[0, np.nonzero(np.diff(bucket))[0] + 1]
    return {"open_time": bucket[starts], "open": bars["open"][starts],
            "high": np.maximum.reduceat(bars["high"], starts), "low": np.minimum.reduceat(bars["low"], starts),
            "close": bars["close"][np.r_[starts[1:] - 1, len(t) - 1]], "volume": np.add.reduceat(bars["volume"], starts),
            "count": np.diff(np.r_[starts, len(t)])}


def htf_trend(chart_open_time, base: dict, tf_ms: int) -> np.ndarray:
    """f_htf()[1] via request.security(lookahead_off): the value of the last higher-timeframe bar that is
    COMPLETE at the chart bar's open (end <= open). Only bars strictly in the past are read."""
    hb = resample(base, tf_ms)
    e, m = ema(hb["close"], P["ema_trend"]), ema(hb["close"], P["ema_major"])
    hc = hb["close"]
    val = np.where((hc > m) & (e > m), 1.0, np.where((hc < m) & (e < m), -1.0, 0.0))
    idx = np.searchsorted(hb["open_time"] + tf_ms, np.asarray(chart_open_time, np.int64), side="right") - 1
    return np.where(idx >= 0, val[np.maximum(idx, 0)], 0.0)


# ---------------------------------------------------------------- the script's score and gates
def compute(bars: dict, base5: dict, symbol: str) -> dict[str, np.ndarray]:
    """Every per-bar quantity of the script on chart bars `bars` (HTF read from the 5m base bars)."""
    o, h, l, c, v = (np.asarray(bars[k], float) for k in ("open", "high", "low", "close", "volume"))
    t = np.asarray(bars["open_time"], np.int64)
    adx_min, _ = asset_params(symbol)
    with np.errstate(invalid="ignore", divide="ignore"):
        ema_f, ema_s, ema_t, ema_m = (ema(c, P[k]) for k in ("ema_fast", "ema_slow", "ema_trend", "ema_major"))
        vw = session_vwap(t, h, l, c, v)
        rsi_ = rsi(c, P["rsi_len"])
        plus, minus, adx = dmi(h, l, c, P["adx_len"], P["adx_smooth"])
        tr = true_range(h, l, c)
        atr_ = rma(tr, 14)
        atr_avg = sma(atr_, 20)
        atr_pct = np.where(c > 0, atr_ / c * 100.0, 0.0)
        vol_avg = sma(v, P["vol_len"])
        relvol = np.where(np.isfinite(vol_avg) & (vol_avg > 0), v / vol_avg, 1.0)
        _, st_dir = supertrend(h, l, c, P["st_factor"], P["st_period"])
        f, s_, g = P["macd"]
        macd_line = ema(c, f) - ema(c, s_)
        macd_sig = ema(macd_line, g)
        hist = macd_line - macd_sig
        n = P["chop_len"]
        tr_sum = sma(tr, n) * n
        rng = highest(h, n) - lowest(l, n)
        chop = np.where((rng > 0) & (tr_sum > 0), 100.0 * np.log10(tr_sum / rng) / np.log10(n), 100.0)
        chop = np.clip(np.nan_to_num(chop, nan=100.0), 0.0, 100.0)
        d = [donchian_latch(h, l, c, max(P["don_len"] - k, 2)) for k in (0, 5, 10)]
        htf = [htf_trend(t, base5, ms) for ms in HTF_MS]

        st_bull, st_bear = st_dir < 0, st_dir > 0
        macd_bull = (macd_line > macd_sig) & (hist > 0)
        macd_bear = (macd_line < macd_sig) & (hist < 0)
        cross_up = (macd_line > macd_sig) & (shift(macd_line) <= shift(macd_sig))
        cross_dn = (macd_line < macd_sig) & (shift(macd_line) >= shift(macd_sig))
        bull_cnt = sum((x == 1).astype(int) for x in d)
        bear_cnt = sum((x == -1).astype(int) for x in d)
        don_bull, don_bear = bull_cnt >= 2, bear_cnt >= 2
        don_mixed = ~don_bull & ~don_bear
        sb, sbe = (htf[0] == 1) & (htf[1] == 1), (htf[0] == -1) & (htf[1] == -1)
        hb_, hbe = (htf[0] >= 0) & (htf[1] >= 0), (htf[0] <= 0) & (htf[1] <= 0)
        hmix = ~sb & ~sbe
        ema_bull, ema_bear = ema_f > ema_s, ema_f < ema_s
        slope = ema_t - shift(ema_t, P["slope_back"])
        slope_str = np.abs(slope) > atr_ * 0.08
        rsi_bull, rsi_bear = (rsi_ >= 32) & (rsi_ <= 82), (rsi_ >= 18) & (rsi_ <= 68)
        rsi_ob, rsi_os = rsi_ > 70, rsi_ < 30
        vol_ok, vol_strong = relvol >= 0.75, relvol >= 1.5
        adx_bull = (adx >= adx_min) & (plus > minus)
        adx_bear = (adx >= adx_min) & (minus > plus)
        adx_str, adx_any = adx >= 25, adx >= adx_min
        vwap_bull = ~np.isfinite(vw) | (c > vw)
        vwap_bear = ~np.isfinite(vw) | (c < vw)
        atr_exp = atr_ > atr_avg * 1.05
        body, crange = np.abs(c - o), h - l
        body_ratio = np.where(crange > 0, body / crange, 0.0)
        pc, po, ph, pl = shift(c), shift(o), shift(h), shift(l)
        bull_engulf = (c > o) & (c > ph) & (o < np.minimum(pc, po))
        bear_engulf = (c < o) & (c < pl) & (o > np.maximum(pc, po))
        bull_burst = (c > o) & (body > atr_ * 0.7) & (body_ratio >= 0.55)
        bear_burst = (c < o) & (body > atr_ * 0.7) & (body_ratio >= 0.55)
        doji = body_ratio < 0.10
        atr_pct_avg = sma(atr_pct, 50)
        vol_regime = np.where((atr_pct_avg > 0) & (atr_pct > atr_pct_avg * 1.30), 1.0,
                              np.where((atr_pct_avg > 0) & (atr_pct < atr_pct_avg * 0.70), -1.0, 0.0))
        regime = np.where((adx >= P["regime_adx_min"]) & (chop < P["regime_chop_max"]), 1.0,
                          np.where((adx < 18) | (chop > 60), -1.0, 0.0))

    def pick(*pairs, default=0.0):
        out = np.full(len(c), default)
        for cond, value in reversed(pairs):
            out = np.where(cond, value, out)
        return out
    vol_pts = pick((vol_strong, 7), (vol_ok, 5), (relvol >= 0.6, 2))
    common = vol_pts - 5.0 * doji + 4.0 * atr_exp
    ls = (pick((sb, 25), (hb_, 13), (hmix, 3)) + pick((don_bull, 14), (don_mixed, 4))
          + pick((adx_bull & adx_str, 15), (adx_bull, 12), (adx_any, 4)) + 10.0 * ema_bull + 8.0 * (c > ema_t)
          + 8.0 * (c > ema_m) + 8.0 * st_bull + 7.0 * vwap_bull + pick((rsi_bull, 8), (rsi_os, 5), (rsi_ob, -4))
          + pick((macd_bull, 6), (cross_up, 8)) + pick(((slope > 0) & slope_str, 6), (slope > 0, 3))
          + 5.0 * ((c > o) & (body_ratio >= P["body_min"])) + 10.0 * bull_engulf + 8.0 * bull_burst + common)
    ss = (pick((sbe, 25), (hbe, 13), (hmix, 3)) + pick((don_bear, 14), (don_mixed, 4))
          + pick((adx_bear & adx_str, 15), (adx_bear, 12), (adx_any, 4)) + 10.0 * ema_bear + 8.0 * (c < ema_t)
          + 8.0 * (c < ema_m) + 8.0 * st_bear + 7.0 * vwap_bear + pick((rsi_bear, 8), (rsi_ob, 5), (rsi_os, -4))
          + pick((macd_bear, 6), (cross_dn, 8)) + pick(((slope < 0) & slope_str, 6), (slope < 0, 3))
          + 5.0 * ((c < o) & (body_ratio >= P["body_min"])) + 10.0 * bear_engulf + 8.0 * bear_burst + common)
    ls, ss = np.maximum(ls, 0.0), np.maximum(ss, 0.0)
    thr = P["base_threshold"] + np.where(regime == -1, 8.0, np.where(regime == 0, 3.0, 0.0)) + np.where(vol_regime == 1, 3.0, 0.0)
    thr = np.clip(thr, P["base_threshold"], 95.0)
    chop_ok = chop <= P["chop_max"]
    hard_long = ema_bull & adx_bull & chop_ok & st_bull & rsi_bull & vol_ok
    hard_short = ema_bear & adx_bear & chop_ok & st_bear & rsi_bear & vol_ok
    return {"long_score": ls, "short_score": ss, "threshold": thr, "hard_long": hard_long, "hard_short": hard_short,
            "setup_long": hard_long & (ls >= thr) & (ls > ss), "setup_short": hard_short & (ss >= thr) & (ss > ls),
            "atr": atr_, "chop": chop, "adx": adx, "di_diff": plus - minus, "st_dir": st_dir,
            "don_net": (bull_cnt - bear_cnt).astype(float), "htf1": htf[0], "htf2": htf[1], "htf3": htf[2],
            "vwap_gap": np.where(np.isfinite(vw) & (vw > 0), c / vw - 1.0, 0.0), "relvol": relvol,
            "regime": regime, "vol_regime": vol_regime}


# ---------------------------------------------------------------- S0: the strategy under AutoFinX execution
def simulate(bars: dict, ind: dict, grid: dict, funding: dict, chart_ms: int, symbol: str) -> list[dict]:
    """Chart-bar decisions at bar close, 1m path in between. One position at a time (pyramiding 0);
    an opposite signal closes and reverses at the bar close; open trades at the data end are dropped."""
    _, sl_mult = asset_params(symbol)
    t = np.asarray(bars["open_time"], np.int64)
    close = np.asarray(bars["close"], float)
    start, size = int(grid["start"]), len(grid["open"])
    go, gh, gl = grid["open"], grid["high"], grid["low"]
    f_time = funding["funding_time"]
    f_csum = np.r_[0.0, np.cumsum(funding["funding_rate"] * 100.0)]
    trades: list[dict] = []
    cur: dict | None = None
    last_entry = None

    def funding_pct(a: int, b: int) -> float:
        if len(f_time) == 0:
            return 0.0
        return float(f_csum[np.searchsorted(f_time, b, side="right")] - f_csum[np.searchsorted(f_time, a, side="right")])

    def close_half(half: str, price_n: float, when: int, reason: str) -> None:
        cur["exit"][half] = (price_n, when, reason)

    def finish() -> None:
        side, entry_n = cur["side"], cur["entry_n"]
        rets, fund = [], 0.0
        for half in ("A", "B"):
            price_n, when, _ = cur["exit"][half]
            rets.append((price_n - entry_n) / abs(entry_n) * 100.0)
            fund += 0.5 * side * funding_pct(cur["time"], when)      # longs pay positive funding
        gross = 0.5 * (rets[0] + rets[1])
        trades.append({"entry_time": cur["time"], "side": side, "exit_time": max(cur["exit"]["A"][1], cur["exit"]["B"][1]),
                       "gross_pct": gross, "funding_pct": fund, "net_pct": gross - COST_AUTOFINX - fund,
                       "net_tv_cost_pct": gross - COST_TRADINGVIEW,
                       "reason_a": cur["exit"]["A"][2], "reason_b": cur["exit"]["B"][2]})

    for i in range(len(t)):
        bar_end = int(t[i] + chart_ms)
        if (bar_end - start) // MINUTE > size:
            break
        if cur is not None:                                          # 1m path inside this chart bar
            side = cur["side"]
            for m in range(max(cur["next_min"], (int(t[i]) - start) // MINUTE), (bar_end - start) // MINUTE):
                o = go[m]
                if o != o:
                    continue
                on, hn, ln = (o, gh[m], gl[m]) if side > 0 else (-o, -gl[m], -gh[m])
                when = start + m * MINUTE
                for half in ("A", "B"):
                    if half in cur["exit"]:
                        continue
                    stop = cur["stop_n"]
                    if half == "B" and cur["active"]:
                        stop = max(stop, cur["peak_n"] - cur["trail"])
                    reason = "STOP" if stop == cur["stop_n"] else "TRAIL"
                    if on <= stop:
                        close_half(half, on, when, reason)
                    elif ln <= stop:
                        close_half(half, stop, when + MINUTE, reason)
                    elif half == "A" and hn >= cur["tp_n"]:
                        close_half(half, max(on, cur["tp_n"]), when + MINUTE, "TP1")
                cur["peak_n"] = max(cur["peak_n"], hn)
                if cur["peak_n"] >= cur["entry_n"] + cur["trail"]:
                    cur["active"] = True
                if len(cur["exit"]) == 2:
                    finish()
                    cur = None
                    break
            if cur is not None:
                cur["next_min"] = (bar_end - start) // MINUTE
        # ---- bar close: trail update, flip exit, entries (script order)
        if cur is not None:
            cur["trail"] = ind["atr"][i] * P["trail_mult"]
            side = cur["side"]
            own, other = (ind["long_score"][i], ind["short_score"][i]) if side > 0 else (ind["short_score"][i], ind["long_score"][i])
            if other >= P["flip_threshold"] and other > own:
                price_n = close[i] * side
                for half in ("A", "B"):
                    if half not in cur["exit"]:
                        close_half(half, price_n, bar_end, "FLIP")
                finish()
                cur = None
        can_enter = last_entry is None or i - last_entry >= P["cooldown"]
        want = 1 if (can_enter and ind["setup_long"][i]) else (-1 if (can_enter and ind["setup_short"][i]) else 0)
        if want and (cur is None or cur["side"] == -want):
            entry_min = (bar_end - start) // MINUTE
            if entry_min >= size or go[entry_min] != go[entry_min] or not np.isfinite(ind["atr"][i]):
                continue
            if cur is not None:                                      # reversal closes at this bar's close
                price_n = close[i] * cur["side"]
                for half in ("A", "B"):
                    if half not in cur["exit"]:
                        close_half(half, price_n, bar_end, "REVERSE")
                finish()
            entry_n = go[entry_min] * want
            risk = ind["atr"][i] * sl_mult
            cur = {"side": want, "time": bar_end, "entry_n": entry_n, "stop_n": entry_n - risk,
                   "tp_n": entry_n + risk * P["tp1_rr"], "trail": ind["atr"][i] * P["trail_mult"],
                   "peak_n": entry_n, "active": False, "exit": {}, "next_min": entry_min}
            last_entry = i
    return trades


def _summary(trades: list[dict], lo: int, hi: int) -> dict:
    sel = [x for x in trades if lo <= x["entry_time"] < hi]
    if not sel:
        return {"trades": 0}
    net = np.array([x["net_pct"] for x in sel])
    out = trade_stats(net)
    side = np.array([x["side"] for x in sel])
    out.update({
        "long_trades": int((side > 0).sum()), "short_trades": int((side < 0).sum()),
        "long_avg_net_pct": round(float(net[side > 0].mean()), 5) if (side > 0).any() else None,
        "short_avg_net_pct": round(float(net[side < 0].mean()), 5) if (side < 0).any() else None,
        "avg_gross_pct": round(float(np.mean([x["gross_pct"] for x in sel])), 5),
        "avg_funding_pct": round(float(np.mean([x["funding_pct"] for x in sel])), 5),
        "avg_net_tradingview_cost_pct": round(float(np.mean([x["net_tv_cost_pct"] for x in sel])), 5),
        "avg_hold_minutes": round(float(np.mean([(x["exit_time"] - x["entry_time"]) / MINUTE for x in sel])), 1),
        "exit_reasons": {k: sum(1 for x in sel if f"{x['reason_a']}/{x['reason_b']}" == k)
                         for k in sorted({f"{x['reason_a']}/{x['reason_b']}" for x in sel})},
    })
    years: dict[str, list[float]] = {}
    for x in sel:
        years.setdefault(time.strftime("%Y", time.gmtime(x["entry_time"] / 1000)), []).append(x["net_pct"])
    out["by_year"] = {y: {"trades": len(v), "avg_net_pct": round(float(np.mean(v)), 4)} for y, v in sorted(years.items())}
    return out


def run(work: Path, symbol: str, chart_tfs=("5m", "15m")) -> dict:
    started = time.monotonic()
    m5 = bd.load_klines(work, symbol, "5m")
    anchors = anchors_from_5m(m5)
    hold = splits.holdout_start_ms(anchors)
    dev_end = splits.dev_end_ms(anchors)
    train_m, val_m = splits.dev_train_val(anchors)
    train_end, val_start = int(anchors[train_m].max()), int(anchors[val_m].min())

    # S1 feature export: causal per-anchor columns on the full series (labels are never read here).
    base_all = {k: m5[k] for k in ("open_time", "open", "high", "low", "close", "volume")}
    ind_all = compute(base_all, base_all, symbol)
    cols = {"anchor_time_ms": anchors}
    names = {"v13_long_score": "long_score", "v13_short_score": "short_score", "v13_threshold": "threshold",
             "v13_hard_long": "hard_long", "v13_hard_short": "hard_short", "v13_setup_long": "setup_long",
             "v13_setup_short": "setup_short", "v13_chop": "chop", "v13_adx": "adx", "v13_di_diff": "di_diff",
             "v13_st_dir": "st_dir", "v13_don_net": "don_net", "v13_htf1": "htf1", "v13_htf2": "htf2",
             "v13_htf3": "htf3", "v13_vwap_gap": "vwap_gap", "v13_relvol": "relvol", "v13_regime": "regime",
             "v13_vol_regime": "vol_regime"}
    for name, key in names.items():
        cols[name] = np.asarray(ind_all[key], float)
    cols["v13_score_diff"] = cols["v13_long_score"] - cols["v13_short_score"]
    cols["v13_long_margin"] = cols["v13_long_score"] - cols["v13_threshold"]
    cols["v13_short_margin"] = cols["v13_short_score"] - cols["v13_threshold"]
    out_dir = work / "features" / symbol
    bd.write_parquet(out_dir / "v13.parquet", {k: cols[k] for k in ("anchor_time_ms",) + FEATURES})

    # S0: everything from the holdout start on is removed BEFORE any simulation (sealed).
    keep = anchors <= hold
    base = {k: v[keep] for k, v in base_all.items()}
    m1 = bd.load_klines(work, symbol, "1m")
    m1 = {k: v[m1["open_time"] < hold] for k, v in m1.items()}
    grid = dense_minutes(m1)
    funding = load_funding(work, symbol)
    funding = {k: v[funding["funding_time"] < hold] for k, v in funding.items()}
    report = {"candidate": "autofinx-v13", "symbol": symbol, "parameters": "script defaults (no tuning)",
              "execution": "AutoFinX normalized (AUDIT.md)", "cost_round_trip_pct": COST_AUTOFINX,
              "windows_utc": {"dev_train_entries_until": train_end, "dev_val_entries_from": val_start,
                              "dev_end_exclusive": dev_end, "holdout_start_sealed": hold},
              "charts": {}}
    for tf in chart_tfs:
        ms = CHART_MS[tf]
        bars = base if tf == "5m" else resample(base, ms)
        if tf != "5m":
            whole = bars["count"] == ms // 300_000
            bars = {k: v[whole] for k, v in bars.items()}
        ind = compute(bars, base, symbol)
        trades = simulate(bars, ind, grid, funding, ms, symbol)
        assert all(x["exit_time"] <= hold for x in trades), "holdout leaked into S0"
        report["charts"][tf] = {
            "setup_bars_long": int(np.sum(ind["setup_long"])), "setup_bars_short": int(np.sum(ind["setup_short"])),
            "dev_train": _summary(trades, 0, train_end + 1), "dev_val": _summary(trades, val_start, dev_end)}
    report["seconds"] = round(time.monotonic() - started, 1)
    path = work / "reports" / "strategy-lab" / "autofinx-v13" / f"{symbol}.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2), "utf-8")
    return report
