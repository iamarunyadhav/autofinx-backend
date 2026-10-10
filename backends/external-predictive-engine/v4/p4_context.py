"""P4 (D-036 roadmap, D-038) — genuinely different information at T0: cross-market, funding, Binance metrics.

Every value is known at the anchor T0 (5m close):
  cross-market  BTC and ETH 1h / 4h log returns, own return minus BTC return (relative strength) 1h / 4h,
                rolling beta and correlation of own 5m returns to BTC over 288 and 864 bars, cross-coin
                dispersion = std of the six coins' 1h returns (coins without data at T0 are skipped)
  funding       last PUBLISHED funding rate (funding_time <= T0), minutes until the next funding event
                (last event + its interval), funding z-score vs the previous 90 events
  metrics       Binance daily-archive 5-minute snapshots, rows with create_time <= T0 - 5 min only (one
                snapshot of publication slack; never a future snapshot): open-interest change 1h / 4h / 24h,
                open-interest z-score (30 days), top-trader long/short ratio (count and sum), global
                long/short ratio, log taker buy/sell volume ratio and its 1h mean. Missing -> NaN (never 0).
Output: features/<SYM>/ctx.parquet keyed by anchor_time_ms (all anchors; P1/P4 evaluation reads dev_train only).
"""

from __future__ import annotations

from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import strategy_v13 as v13
from .download import load_funding
from .targets import anchors_from_5m

SYMBOLS = ("BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT", "SUIUSDT")
METRIC_LAG_MS = 300_000


def _close_at(work: Path, symbol: str, anchors: np.ndarray) -> np.ndarray:
    """5m close of the bar ending exactly at each anchor (NaN when missing)."""
    m5 = bd.load_klines(work, symbol, "5m")
    if len(m5["open_time"]) == 0:
        return np.full(len(anchors), np.nan)
    a = anchors_from_5m(m5)
    pos = np.searchsorted(a, anchors)
    ok = (pos < len(a)) & (a[np.minimum(pos, len(a) - 1)] == anchors)
    out = np.full(len(anchors), np.nan)
    out[ok] = m5["close"][pos[ok]]
    return out


def _logret(close: np.ndarray, bars: int) -> np.ndarray:
    with np.errstate(invalid="ignore", divide="ignore"):
        return np.log(close / v13.shift(close, bars))


def _rolling_beta_corr(y: np.ndarray, x: np.ndarray, n: int) -> tuple[np.ndarray, np.ndarray]:
    ok = np.isfinite(x) & np.isfinite(y)
    xs, ys = np.where(ok, x, 0.0), np.where(ok, y, 0.0)
    cnt = _rsum(ok.astype(float), n)
    sx, sy = _rsum(xs, n), _rsum(ys, n)
    sxx, syy, sxy = _rsum(xs * xs, n), _rsum(ys * ys, n), _rsum(xs * ys, n)
    with np.errstate(invalid="ignore", divide="ignore"):
        cov = sxy / cnt - (sx / cnt) * (sy / cnt)
        vx = sxx / cnt - (sx / cnt) ** 2
        vy = syy / cnt - (sy / cnt) ** 2
        beta = np.where(cnt >= n * 0.8, cov / vx, np.nan)
        corr = np.where(cnt >= n * 0.8, cov / np.sqrt(vx * vy), np.nan)
    return beta, corr


def _rsum(x: np.ndarray, n: int) -> np.ndarray:
    cs = np.r_[0.0, np.cumsum(x)]
    out = np.full(len(x), np.nan)
    if len(x) >= n:
        out[n - 1:] = cs[n:] - cs[:-n]
    return out


def funding_features(anchors: np.ndarray, funding: dict) -> dict[str, np.ndarray]:
    f_t, f_r = funding["funding_time"], funding["funding_rate"] * 100.0
    n = len(anchors)
    out = {"ctx_funding_last": np.full(n, np.nan), "ctx_funding_min_to_next": np.full(n, np.nan),
           "ctx_funding_z90": np.full(n, np.nan)}
    if len(f_t) == 0:
        return out
    idx = np.searchsorted(f_t, anchors, side="right") - 1           # last event with time <= T0
    ok = idx >= 0
    i = np.maximum(idx, 0)
    out["ctx_funding_last"] = np.where(ok, f_r[i], np.nan)
    hours = funding.get("interval_hours")
    interval_ms = (np.where(hours > 0, hours, 8).astype(np.int64) * 3_600_000) if hours is not None and len(hours) else np.full(len(f_t), 8 * 3_600_000)
    nxt = f_t[i] + interval_ms[i]
    out["ctx_funding_min_to_next"] = np.where(ok, (nxt - anchors) / 60_000.0, np.nan)
    cs, cs2 = np.r_[0.0, np.cumsum(f_r)], np.r_[0.0, np.cumsum(f_r ** 2)]
    lo = np.maximum(i - 89, 0)
    cnt = i - lo + 1
    with np.errstate(invalid="ignore", divide="ignore"):
        mean = (cs[i + 1] - cs[lo]) / cnt
        var = (cs2[i + 1] - cs2[lo]) / cnt - mean ** 2
        z = (f_r[i] - mean) / np.sqrt(var)
    out["ctx_funding_z90"] = np.where(ok & (cnt >= 30) & (var > 0), z, np.nan)
    return out


def load_metrics(work: Path, symbol: str) -> dict[str, np.ndarray] | None:
    base = work / "data" / symbol / "metrics" / "daily"
    files = sorted(base.glob("*.parquet"))
    if not files:
        return None
    data = bd.concat(bd.read_parquet(f) for f in files)
    order = np.argsort(data["create_time"], kind="stable")
    data = {k: v[order] for k, v in data.items()}
    keep = np.r_[True, np.diff(data["create_time"]) > 0]
    return {k: v[keep] for k, v in data.items()}


def metrics_features(anchors: np.ndarray, m: dict | None) -> dict[str, np.ndarray]:
    names = ("ctx_oi_chg_1h", "ctx_oi_chg_4h", "ctx_oi_chg_24h", "ctx_oi_z30d", "ctx_top_ls_count", "ctx_top_ls_sum",
             "ctx_global_ls", "ctx_taker_ls_log", "ctx_taker_ls_log_1h")
    n = len(anchors)
    out = {k: np.full(n, np.nan) for k in names}
    if m is None or len(m["create_time"]) == 0:
        return out
    t = m["create_time"]
    idx = np.searchsorted(t, anchors - METRIC_LAG_MS, side="right") - 1
    ok = idx >= 0
    i = np.maximum(idx, 0)
    oi = np.where(m["sum_open_interest_value"] > 0, m["sum_open_interest_value"], np.nan)

    def at_offset(minutes: int) -> np.ndarray:
        j = np.searchsorted(t, anchors - METRIC_LAG_MS - minutes * 60_000, side="right") - 1
        return np.where(j >= 0, oi[np.maximum(j, 0)], np.nan)
    now = np.where(ok, oi[i], np.nan)
    with np.errstate(invalid="ignore", divide="ignore"):
        out["ctx_oi_chg_1h"] = np.log(now / at_offset(60))
        out["ctx_oi_chg_4h"] = np.log(now / at_offset(240))
        out["ctx_oi_chg_24h"] = np.log(now / at_offset(1440))
        # 30-day z of OI on the snapshot grid (288 snapshots/day), computed causally on the snapshots
        lo = np.log(oi)
        finite = np.isfinite(lo)
        w = 288 * 30
        s1, s2, c = _rsum(np.where(finite, lo, 0.0), w), _rsum(np.where(finite, lo * lo, 0.0), w), _rsum(finite.astype(float), w)
        mean, var = s1 / c, s2 / c - (s1 / c) ** 2
        z = np.where(c >= w * 0.8, (lo - mean) / np.sqrt(var), np.nan)
        out["ctx_oi_z30d"] = np.where(ok, z[i], np.nan)
        taker = np.log(np.where(m["sum_taker_long_short_vol_ratio"] > 0, m["sum_taker_long_short_vol_ratio"], np.nan))
        tk = np.where(np.isfinite(taker), taker, 0.0)
        tmean = _rsum(tk, 12) / _rsum(np.isfinite(taker).astype(float), 12)
    out["ctx_top_ls_count"] = np.where(ok, m["count_toptrader_long_short_ratio"][i], np.nan)
    out["ctx_top_ls_sum"] = np.where(ok, m["sum_toptrader_long_short_ratio"][i], np.nan)
    out["ctx_global_ls"] = np.where(ok, m["count_long_short_ratio"][i], np.nan)
    out["ctx_taker_ls_log"] = np.where(ok, taker[i], np.nan)
    out["ctx_taker_ls_log_1h"] = np.where(ok, tmean[i], np.nan)
    stale = ok & ((anchors - METRIC_LAG_MS - t[i]) > 15 * 60_000)    # gap in the archive: no stale carry-forward
    for k in out:
        out[k] = np.where(stale, np.nan, out[k])
    return out


def build(work: Path, symbol: str) -> Path:
    m5 = bd.load_klines(work, symbol, "5m")
    anchors = anchors_from_5m(m5)
    own = np.asarray(m5["close"], float)
    cols: dict[str, np.ndarray] = {"anchor_time_ms": anchors}
    btc = own if symbol == "BTCUSDT" else _close_at(work, "BTCUSDT", anchors)
    eth = own if symbol == "ETHUSDT" else _close_at(work, "ETHUSDT", anchors)
    for name, close in (("btc", btc), ("eth", eth)):
        cols[f"ctx_{name}_ret_1h"], cols[f"ctx_{name}_ret_4h"] = _logret(close, 12), _logret(close, 48)
    cols["ctx_rel_btc_1h"] = _logret(own, 12) - cols["ctx_btc_ret_1h"]
    cols["ctx_rel_btc_4h"] = _logret(own, 48) - cols["ctx_btc_ret_4h"]
    r_own, r_btc = _logret(own, 1), _logret(btc, 1)
    for n in (288, 864):
        beta, corr = _rolling_beta_corr(r_own, r_btc, n)
        cols[f"ctx_beta_btc_{n}"], cols[f"ctx_corr_btc_{n}"] = beta, corr
    rets = np.vstack([_logret(own if s == symbol else _close_at(work, s, anchors), 12) for s in SYMBOLS])
    with np.errstate(invalid="ignore"):
        cnt = np.isfinite(rets).sum(axis=0)
        disp = np.nanstd(np.where(np.isfinite(rets), rets, np.nan), axis=0) if rets.size else np.full(len(anchors), np.nan)
    cols["ctx_dispersion_1h"] = np.where(cnt >= 3, disp, np.nan)
    cols.update(funding_features(anchors, load_funding(work, symbol)))
    cols.update(metrics_features(anchors, load_metrics(work, symbol)))
    path = work / "features" / symbol / "ctx.parquet"
    bd.write_parquet(path, cols)
    return path
