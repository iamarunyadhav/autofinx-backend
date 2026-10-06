"""Data integrity, coverage and 1m/5m alignment checks (SPEC §3, §7, §46; L3 G7 audit).

Nothing is repaired or interpolated here: problems are counted and reported (§97).
"""

from __future__ import annotations

import datetime as dt
import time
from pathlib import Path

import numpy as np

from . import binance_data as bd
from .download import load_funding


def kline_checks(data: dict[str, np.ndarray], interval_ms: int, now_ms: int) -> dict:
    t = data["open_time"]
    n = int(len(t))
    if n == 0:
        return {"rows": 0}
    diffs = np.diff(t)
    gap_idx = np.nonzero(diffs > interval_ms)[0]
    gap_sizes = (diffs[gap_idx] // interval_ms - 1).astype(np.int64)
    biggest = np.argsort(gap_sizes)[::-1][:10]
    o, h, l, c, v = data["open"], data["high"], data["low"], data["close"], data["volume"]
    bad_ohlc = (h < np.maximum(o, c)) | (l > np.minimum(o, c)) | (l <= 0) | (h < l) | ~np.isfinite(o + h + l + c)
    taker_over = data["taker_buy_volume"] > v * (1 + 1e-9) + 1e-12
    expected = int((t[-1] - t[0]) // interval_ms + 1)
    return {
        "rows": n,
        "first_open_utc": _utc(t[0]), "last_open_utc": _utc(t[-1]),
        "expected_rows_first_to_last": expected,
        "missing_candles": int(expected - n),
        "gap_count": int(len(gap_idx)),
        "largest_gaps": [{"after_utc": _utc(t[gap_idx[i]]), "missing_candles": int(gap_sizes[i])} for i in biggest],
        "not_ascending": int((diffs <= 0).sum()),
        "misaligned_open_time": int((t % interval_ms != 0).sum()),
        "bad_close_time": int((data["close_time"] != t + interval_ms - 1).sum()),
        "invalid_ohlc": int(bad_ohlc.sum()),
        "negative_volume": int((v < 0).sum()),
        "taker_volume_above_volume": int(taker_over.sum()),
        "not_closed_yet": int((data["close_time"] >= now_ms).sum()),
    }


def aggregate_1m_to_5m(m1: dict[str, np.ndarray]) -> dict[str, np.ndarray]:
    """5m candles built only from buckets that have all five 1m candles (no partial buckets)."""
    t = m1["open_time"]
    if len(t) == 0:
        return {k: np.zeros(0) for k in ("open_time", "open", "high", "low", "close", "volume")}
    bucket = t - (t % 300_000)
    starts = np.r_[0, np.nonzero(np.diff(bucket))[0] + 1]
    counts = np.diff(np.r_[starts, len(t)])
    full = counts == 5
    s = starts[full]
    e = s + 4
    return {
        "open_time": bucket[s],
        "open": m1["open"][s],
        "high": np.maximum.reduceat(m1["high"], starts)[full],
        "low": np.minimum.reduceat(m1["low"], starts)[full],
        "close": m1["close"][e],
        "volume": np.add.reduceat(m1["volume"], starts)[full],
    }


def conflict_buckets(m1: dict[str, np.ndarray], m5: dict[str, np.ndarray]) -> np.ndarray:
    """5m open_times where Binance's own 1m and 5m archives disagree (R-007). Never repaired;
    Layer A marks every anchor/path that touches one as SOURCE_CONFLICT (SPEC §46)."""
    agg = aggregate_1m_to_5m(m1)
    common, ia, ib = np.intersect1d(agg["open_time"], m5["open_time"], return_indices=True)
    bad = np.zeros(len(common), bool)
    for key in ("open", "high", "low", "close"):
        bad |= np.abs(agg[key][ia] - m5[key][ib]) / np.maximum(np.abs(m5[key][ib]), 1e-12) > 1e-9
    bad |= np.abs(agg["volume"][ia] - m5["volume"][ib]) / np.maximum(np.abs(m5["volume"][ib]), 1e-12) > 1e-6
    return common[bad].astype(np.int64)


def alignment_check(m1: dict[str, np.ndarray], m5: dict[str, np.ndarray]) -> dict:
    agg = aggregate_1m_to_5m(m1)
    common, ia, ib = np.intersect1d(agg["open_time"], m5["open_time"], return_indices=True)
    if len(common) == 0:
        return {"checked_5m_candles": 0}
    def rel(a, b):
        return np.abs(a - b) / np.maximum(np.abs(b), 1e-12)
    price_bad = np.zeros(len(common), bool)
    for key in ("open", "high", "low", "close"):
        price_bad |= rel(agg[key][ia], m5[key][ib]) > 1e-9
    volume_bad = rel(agg["volume"][ia], m5["volume"][ib]) > 1e-6
    examples = [_utc(x) for x in common[price_bad | volume_bad][:5]]
    return {
        "checked_5m_candles": int(len(common)),
        "price_mismatch": int(price_bad.sum()),
        "volume_mismatch": int(volume_bad.sum()),
        "mismatch_examples_utc": examples,
    }


def coverage(symbol: str, data: dict[str, np.ndarray], interval_ms: int, months: int, today: dt.date) -> dict:
    requested_start = dt.date.fromisoformat(bd.month_list(months, today)[0] + "-01")
    start_ms = int(dt.datetime(requested_start.year, requested_start.month, 1, tzinfo=dt.timezone.utc).timestamp() * 1000)
    rows = int(len(data["open_time"]))
    if rows == 0:
        return {"requested_months": months, "requested_start_utc": _utc(start_ms), "coverage_pct": 0.0, "reason_if_short": "no data"}
    last = int(data["open_time"][-1])
    expected = (last - start_ms) // interval_ms + 1
    first = int(data["open_time"][0])
    short_reason = None
    if first > start_ms + interval_ms:
        short_reason = f"first Binance candle {_utc(first)} is after the requested start (listing date or archive start)"
    return {
        "requested_months": months, "requested_start_utc": _utc(start_ms),
        "actual_start_utc": _utc(first), "actual_end_utc": _utc(last),
        "actual_history_days": round((last - first) / 86_400_000, 1),
        "coverage_pct": round(100.0 * rows / max(expected, 1), 3),
        "reason_if_short": short_reason,
    }


def funding_checks(funding: dict[str, np.ndarray]) -> dict:
    t = funding["funding_time"]
    if len(t) == 0:
        return {"rows": 0}
    hours = np.diff(t) / 3_600_000
    return {
        "rows": int(len(t)), "first_utc": _utc(t[0]), "last_utc": _utc(t[-1]),
        "interval_hours_seen": sorted({int(round(h)) for h in hours}) if len(hours) else [],
        "intervals_over_9h": int((hours > 9).sum()),
        "abs_rate_above_5pct": int((np.abs(funding["funding_rate"]) > 0.05).sum()),
    }


def metrics_audit(work: Path, symbol: str) -> dict:
    """G7 coverage (L3): earliest/latest timestamp, typical frequency, gaps, days present/missing."""
    base = work / "data" / symbol / "metrics" / "daily"
    present = sorted(base.glob("*.parquet"))
    missing = sorted(base.glob("*.missing"))
    if not present:
        return {"days_present": 0, "days_missing": len(missing)}
    data = bd.concat(bd.read_parquet(f) for f in present)
    t = np.sort(data["create_time"])
    diffs = np.diff(t)
    oi = data["sum_open_interest"]
    return {
        "days_present": len(present), "days_missing": len(missing),
        "missing_days_sample": [m.stem for m in missing[:10]],
        "first_utc": _utc(t[0]), "last_utc": _utc(t[-1]), "rows": int(len(t)),
        "median_step_minutes": float(np.median(diffs) / 60_000) if len(diffs) else None,
        "gaps_over_15min": int((diffs > 15 * 60_000).sum()),
        "open_interest_nan_or_zero": int((~np.isfinite(oi) | (oi <= 0)).sum()),
        "coverage_pct_of_days": round(100.0 * len(present) / max(len(present) + len(missing), 1), 2),
        "note": "values are snapshots at create_time; a feature may only use rows with create_time <= T0 (L3)",
    }


def verify_symbol(work: Path, symbol: str, months: int, today: dt.date, now_ms: int | None = None) -> dict:
    now_ms = now_ms if now_ms is not None else int(time.time() * 1000)
    started = time.monotonic()
    m1 = bd.load_klines(work, symbol, "1m")
    m5 = bd.load_klines(work, symbol, "5m")
    est_mb = (len(m1["open_time"]) + len(m5["open_time"])) * 12 * 8 / 1e6
    report = {
        "symbol": symbol, "generated_utc": _utc(now_ms), "ram_estimate_mb": round(est_mb, 1),
        "1m": {**kline_checks(m1, 60_000, now_ms), "coverage": coverage(symbol, m1, 60_000, months, today)},
        "5m": {**kline_checks(m5, 300_000, now_ms), "coverage": coverage(symbol, m5, 300_000, months, today)},
        "alignment_1m_vs_5m": alignment_check(m1, m5),
        "funding": funding_checks(load_funding(work, symbol)),
    }
    if (work / "data" / symbol / "metrics").exists():
        report["metrics_g7"] = metrics_audit(work, symbol)
    report["verdict"] = verdict(report)
    report["seconds"] = round(time.monotonic() - started, 1)
    return report


def verdict(report: dict) -> dict:
    problems, warnings = [], []
    for tf in ("1m", "5m"):
        r = report[tf]
        if not r.get("rows"):
            problems.append(f"{tf}: no data")
            continue
        for key in ("not_ascending", "misaligned_open_time", "bad_close_time", "invalid_ohlc", "negative_volume", "not_closed_yet"):
            if r.get(key):
                problems.append(f"{tf}: {key}={r[key]}")
        if r.get("missing_candles"):
            warnings.append(f"{tf}: {r['missing_candles']} missing candles in {r['gap_count']} gaps (never filled; paths crossing them become INVALID_DATA)")
        if r["coverage"].get("reason_if_short"):
            warnings.append(f"{tf}: {r['coverage']['reason_if_short']}")
    align = report["alignment_1m_vs_5m"]
    if align.get("checked_5m_candles", 0) == 0:
        problems.append("1m/5m alignment could not be checked")
    elif align["price_mismatch"] or align["volume_mismatch"]:
        warnings.append(f"1m->5m mismatch: price {align['price_mismatch']}, volume {align['volume_mismatch']} of {align['checked_5m_candles']}")
    if not report["funding"].get("rows"):
        problems.append("funding: no data")
    return {"status": "FAIL" if problems else ("WARN" if warnings else "OK"), "problems": problems, "warnings": warnings}


def _utc(ms) -> str:
    return dt.datetime.fromtimestamp(int(ms) / 1000, dt.timezone.utc).strftime("%Y-%m-%d %H:%M")
