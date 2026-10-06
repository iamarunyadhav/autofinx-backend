"""Sampled aggTrade resolver for same-1m-bar ambiguity (SPEC §26, D-019, D-020).

Binance REST only serves aggTrades for the last 2 days, and daily bulk files are large, so this
module resolves a SAMPLE of development days: it streams each day's official aggTrades zip, keeps
only the minutes that were ambiguous for the simulated trades, re-simulates those trades with the
exact trade order inside the ambiguous minutes, and reports how often (and by how much) the
conservative 1m rule differed from reality. Nothing here changes Layer A or the P3 numbers.
"""

from __future__ import annotations

import datetime as dt
import io
import json
import random
import time
import zipfile
from pathlib import Path

import numpy as np

from . import binance_data as bd
from . import policy as pol
from . import splits
from .download import load_funding
from .targets import MINUTE, VALID, dense_minutes
from .verify import conflict_buckets


def day_minutes_trades(symbol: str, day: str, minutes: set[int], get=bd.http_get) -> dict[int, np.ndarray]:
    """{minute_open_ms: trade prices in exact order} for the requested minutes of one UTC day."""
    url = f"{bd.BULK}/daily/aggTrades/{symbol}/{symbol}-aggTrades-{day}.zip"
    payload, _ = bd.fetch_verified_zip(url, get)
    out: dict[int, list] = {m: [] for m in minutes}
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        with archive.open(archive.namelist()[0]) as raw:
            for line in io.TextIOWrapper(raw, encoding="utf-8"):
                parts = line.split(",")
                if len(parts) < 6 or not parts[0][:1].isdigit():
                    continue                                  # header row
                t = int(parts[5])
                if t > 10**14:
                    t //= 1000                                # microsecond files
                minute = t - t % MINUTE
                bucket = out.get(minute)
                if bucket is not None:
                    bucket.append((int(parts[0]), float(parts[1])))
    return {m: np.array([p for _, p in sorted(v)]) for m, v in out.items() if v}


def resimulate(window: dict[str, np.ndarray], ticks: dict[int, np.ndarray], side: str,
               sl: float, act: float, trail: float, cost: float) -> tuple[str, float, int]:
    """Exact re-simulation: bars with tick data are walked trade by trade, other bars use the
    same conservative 1m rules as policy.simulate. Returns (reason, gross move %, exit bar)."""
    s = 1.0 if side == "long" else -1.0
    e = window["open"][0]
    def pct(x):
        return (x / e - 1.0) * 100.0 * s
    floor, armed, peak = -sl, False, None

    def stop() -> float:
        return max(floor, peak - trail)

    for j in range(len(window["open"])):
        if j in ticks:
            for price in ticks[j]:
                m = pct(price)
                if not armed:
                    if m <= floor:
                        return "HARD_STOP", m, j          # the trade that crossed the stop (gap aware)
                    if m >= act:
                        armed, peak = True, m
                else:
                    if m <= stop():
                        level = stop()
                        return ("TRAILING_PROFIT" if level > floor + 1e-12 else "HARD_STOP"), m, j
                    peak = max(peak, m)
            continue
        o, h, l = pct(window["open"][j]), window["high"][j], window["low"][j]
        fav, adv = (pct(h), pct(l)) if s > 0 else (pct(l), pct(h))
        if not armed:
            if adv <= floor:
                return ("AMBIGUOUS_INTRABAR" if fav >= act else "HARD_STOP"), min(o, floor), j
            if fav >= act:
                armed, peak = True, fav
                if adv <= stop():
                    level = stop()
                    return ("TRAILING_PROFIT" if level > floor + 1e-12 else "HARD_STOP"), level, j
            continue
        prev = stop()
        if adv <= prev:
            return ("TRAILING_PROFIT" if prev > floor + 1e-12 else "HARD_STOP"), min(o, prev), j
        peak = max(peak, fav)
        if adv <= stop():
            level = stop()
            return ("TRAILING_PROFIT" if level > floor + 1e-12 else "HARD_STOP"), level, j
    close = pct(window["close"][-1])
    net = close - cost
    return ("TIME_EXIT_PROFIT" if net > pol.FLAT_PCT else "TIME_EXIT_LOSS" if net < -pol.FLAT_PCT else "TIME_EXIT_FLAT"), close, len(window["open"]) - 1


def stratify_days(grid: dict[str, np.ndarray], days: list[str]) -> dict[str, str]:
    """day -> stratum "YYYY|low_vol|mid_vol|high_vol" (daily realized vol of 1m log returns, terciles
    over the candidate days). Spreads the sample across years and volatility regimes (review D-024)."""
    start = int(grid["start"])
    vols = {}
    for day in days:
        t0 = int(dt.datetime.fromisoformat(day).replace(tzinfo=dt.timezone.utc).timestamp() * 1000)
        i = (t0 - start) // MINUTE
        closes = grid["close"][max(i, 0):max(i, 0) + 1440]
        closes = closes[np.isfinite(closes)]
        vols[day] = float(np.std(np.diff(np.log(closes)))) if len(closes) > 10 else 0.0
    q1, q2 = np.percentile(list(vols.values()), [33.3, 66.7]) if vols else (0.0, 0.0)
    return {d: f"{d[:4]}|{'low_vol' if v < q1 else 'mid_vol' if v < q2 else 'high_vol'}" for d, v in vols.items()}


def stratified_sample(strata: dict[str, str], days: int, seed: int) -> list[str]:
    """Round-robin over strata (random order inside each), so every year x regime is represented."""
    rng = random.Random(seed)
    groups: dict[str, list[str]] = {}
    for day, stratum in strata.items():
        groups.setdefault(stratum, []).append(day)
    for members in groups.values():
        rng.shuffle(members)
    keys = sorted(groups)
    picked = []
    while len(picked) < min(days, len(strata)):
        for key in keys:
            if groups[key] and len(picked) < days:
                picked.append(groups[key].pop())
    return sorted(picked)


def run_sample(work: Path, symbol: str, horizons: list[int], days: int = 30, seed: int = 7, cost: float = 0.12, get=bd.http_get) -> dict:
    """Resolve the ambiguous minutes of executed REFERENCE / VOLATILITY trades on `days` random dev days."""
    import pyarrow.parquet as pq
    started = time.monotonic()
    m1 = bd.load_klines(work, symbol, "1m")
    m5 = bd.load_klines(work, symbol, "5m")
    grid = dense_minutes(m1)
    funding = load_funding(work, symbol)
    f_t = funding["funding_time"] if len(funding["funding_time"]) else None
    f_cs = np.r_[0.0, np.cumsum(funding["funding_rate"] * 100.0)] if f_t is not None else None
    conflicts = conflict_buckets(m1, m5)
    feat = pq.read_table(work / "features" / symbol / "v3.parquet", columns=["anchor_time_ms", "vol_48"]).to_pydict()
    f_anchor, f_vol = np.asarray(feat["anchor_time_ms"], np.int64), np.asarray(feat["vol_48"], float)

    cases = []   # (day, minute_ms, payload)
    for h in horizons:
        report = json.loads((work / "reports" / "p3" / f"{symbol}_h{h}.json").read_text("utf-8"))
        t = pq.read_table(work / "targets" / symbol / f"h{h}.parquet", columns=["anchor_time_ms", "status"]).to_pydict()
        anchors = np.asarray(t["anchor_time_ms"], np.int64)
        dev = splits.dev_mask(anchors) & (np.asarray(t["status"]) == VALID)
        pos = np.searchsorted(f_anchor, anchors)
        ok = (pos < len(f_anchor)) & (f_anchor[np.minimum(pos, len(f_anchor) - 1)] == anchors)
        vol = np.where(ok, f_vol[np.minimum(pos, len(f_anchor) - 1)], np.nan)
        rows = np.nonzero(dev & np.isfinite(vol))[0]
        sigma = vol * np.sqrt(h / 5.0) * 100.0
        for side in ("long", "short"):
            ref = report["policies"][side]["reference_levels"]
            for name, (sl, act, trail) in {
                "REFERENCE": (ref["sl"], ref["act"], ref["trail"]),
                "VOLATILITY": (np.maximum(1.5 * sigma, 0.05), np.maximum(cost + 0.06, sigma), np.maximum(0.5 * sigma, 0.02)),
            }.items():
                slr, actr, trailr = (np.full(len(rows), float(v)) if np.ndim(v) == 0 else v[rows] for v in (sl, act, trail))
                sim = pol.simulate(None, anchors[rows], h, side, slr, actr, trailr, cost, funding, conflicts, grid=grid)
                sim = pol.apply_worst_case(sim, grid, anchors[rows], h, side, slr, actr, trailr, cost, funding)
                chosen = pol.non_overlapping(anchors[rows], sim["status"] == VALID, sim["exit_time_ms"])
                flagged = chosen[(sim["reason"][chosen] == pol.AMBIGUOUS_INTRABAR) | sim["same_bar_trail"][chosen]]
                for i in flagged:
                    minute = int(anchors[rows][i] + sim["exit_bar"][i] * MINUTE)
                    day = dt.datetime.fromtimestamp(minute / 1000, dt.timezone.utc).date().isoformat()
                    cases.append((day, minute, {"h": h, "side": side, "policy": name, "anchor": int(anchors[rows][i]),
                                                "sl": float(slr[i]), "act": float(actr[i]), "trail": float(trailr[i]),
                                                "net": float(sim["net_pct"][i]), "fund": float(sim["funding_pct"][i]),
                                                "reason": pol.REASONS[sim["reason"][i]], "move": float(sim["gross_pct"][i])}))
    all_days = sorted({c[0] for c in cases})
    strata = stratify_days(grid, all_days)
    sample = stratified_sample(strata, days, seed)
    results, failed_days = [], []
    for n, day in enumerate(sample, 1):
        day_cases = [c for c in cases if c[0] == day]
        try:
            ticks = day_minutes_trades(symbol, day, {c[1] for c in day_cases}, get)
        except Exception as error:                       # one bad day never stops the sample
            failed_days.append({"day": day, "error": str(error)[:200]})
            print(f"  {symbol} aggTrades day {n}/{len(sample)} {day}: FAILED ({error})", flush=True)
            continue
        for _, minute, c in day_cases:
            n_bars = c["h"]
            first = int((c["anchor"] - int(grid["start"])) // MINUTE)
            window = {k: grid[k][first:first + n_bars] for k in ("open", "high", "low", "close")}
            bar = int((minute - c["anchor"]) // MINUTE)
            if bar not in range(n_bars) or minute not in ticks:
                continue
            why, move, exit_bar = resimulate(window, {bar: ticks[minute]}, c["side"], c["sl"], c["act"], c["trail"], cost)
            sign = 1.0 if c["side"] == "long" else -1.0
            # Funding recomputed up to the RESOLVED exit time (the exact order can move the exit).
            exit_ms = c["anchor"] + (exit_bar + 1) * MINUTE
            fund = float(f_cs[np.searchsorted(f_t, exit_ms, side="right")] - f_cs[np.searchsorted(f_t, c["anchor"], side="right")]) if f_t is not None else 0.0
            net = move - cost - sign * fund
            results.append({**c, "day": day, "exact_reason": why, "exact_exit_bar": int(exit_bar), "exact_funding": fund,
                            "exact_net": net, "delta_net": net - c["net"]})
        print(f"  {symbol} aggTrades day {n}/{len(sample)} {day}: {len(day_cases)} flagged trades", flush=True)

    summary = {"symbol": symbol, "days_sampled": len(sample), "days_with_flagged_trades": len(all_days),
               "flagged_trades_total": len(cases), "resolved": len(results), "failed_days": failed_days, "seconds": round(time.monotonic() - started, 1), "groups": {}}
    for key in sorted({(r["h"], r["policy"], r["side"]) for r in results}):
        g = [r for r in results if (r["h"], r["policy"], r["side"]) == key]
        d = np.array([r["delta_net"] for r in g])
        summary["groups"][f"h{key[0]}_{key[1]}_{key[2]}"] = {
            "resolved": len(g), "outcome_changed_pct": round(float(np.mean([r["exact_reason"] != r["reason"] for r in g]) * 100), 2),
            "avg_delta_net_pct": round(float(d.mean()), 4), "conservative_understates_pct": round(float((d > 1e-9).mean() * 100), 2),
            "conservative_overstates_pct": round(float((d < -1e-9).mean() * 100), 2)}
    summary["strata_sampled"] = {}
    for day in sample:
        summary["strata_sampled"][strata[day]] = summary["strata_sampled"].get(strata[day], 0) + 1
    summary["by_stratum"] = {}
    for stratum in sorted({strata[r["day"]] for r in results}):
        d = np.array([r["delta_net"] for r in results if strata[r["day"]] == stratum])
        summary["by_stratum"][stratum] = {"resolved": int(len(d)), "avg_delta_net_pct": round(float(d.mean()), 4),
                                          "changed_pct": round(float(np.mean(np.abs(d) > 1e-9) * 100), 2)}
    out = work / "reports" / "resolver"
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{symbol}.json").write_text(json.dumps({"summary": summary, "cases": results}, indent=1), "utf-8")
    return summary
