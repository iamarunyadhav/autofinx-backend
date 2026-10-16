"""Independent, research-only AutoFinX V4 audit experiment.

This program is deliberately outside every runtime namespace.  It fetches new public Binance
Vision archives, verifies Binance-published SHA-256 checksums, loads the locally cached exact
Chronos-2 snapshot, and evaluates fixed causal rules on a post-Chronos-release window that ends
before AutoFinX's protected 2026 holdout.  It never reads a database, API key, cached experiment
dataset/result, or trading service, and it has no order/execution capability.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import math
import os
import platform
import random
import statistics
import sys
import time
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path

PLAN = {
    "experiment_id": "independent-v4-audit-20261015-v1",
    "symbols": ["BTCUSDT", "ETHUSDT", "SOLUSDT"],
    "timeframe": "1h",
    "archive_months": ["2025-10", "2025-11", "2025-12"],
    "test_start_utc": "2025-11-01T00:00:00Z",
    "test_end_utc": "2025-12-31T19:59:59Z",
    "protected_holdout_starts_utc": "2026-01-04T00:00:00Z",
    "context_bars": 256,
    "horizon_bars": 4,
    "step_bars": 24,
    "decision_threshold_fraction": 0.0012,
    "fixed_cost": {"taker_fee_bps_per_side": 5.0, "slippage_bps_per_side": 1.0},
    "funding": "actual Binance Vision funding-rate events inside each holding window",
    "strategies": {
        "buy_hold": "always LONG (sanity baseline)",
        "momentum_4": "sign of causal trailing 4-bar return",
        "drift_24": "mean of last 24 one-bar price differences projected four bars; thresholded",
        "autofinx_existing": "exact MultiWindowTrendScoringService defaults: 12/24/48 bars, ATR normalization, 0.5/0.3/0.2 weights, +/-0.15 composite",
        "chronos2": "final-step p50 relative to entry; +/-0.0012 threshold",
        "naive_last": "last-value forecast metric baseline; WAIT under the same threshold",
    },
    "no_tuning": True,
    "chronos_repo": "amazon/chronos-2",
    "chronos_snapshot": "29ec3766d36d6f73f0696f85560a422f50e8498c",
    "seed": 20261015,
    "execution_authorized": False,
}


def canonical_hash(value: object) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def download_verified(url: str, output: Path) -> dict:
    checksum_url = url + ".CHECKSUM"
    with urllib.request.urlopen(checksum_url, timeout=60) as response:
        checksum_text = response.read().decode("ascii").strip()
    expected = checksum_text.split()[0].lower()
    if len(expected) != 64:
        raise RuntimeError(f"Malformed Binance checksum for {url}: {checksum_text!r}")
    digest = hashlib.sha256()
    tmp = output.with_suffix(output.suffix + ".part")
    with urllib.request.urlopen(url, timeout=120) as response, tmp.open("wb") as fh:
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
            fh.write(chunk)
    actual = digest.hexdigest()
    if actual != expected:
        tmp.unlink(missing_ok=True)
        raise RuntimeError(f"SHA-256 mismatch for {url}: expected {expected}, got {actual}")
    tmp.replace(output)
    return {"url": url, "sha256": actual, "bytes": output.stat().st_size, "fetched_at_utc": datetime.now(timezone.utc).isoformat()}


def archive_rows(path: Path) -> list[list[str]]:
    with zipfile.ZipFile(path) as zf:
        names = [n for n in zf.namelist() if not n.endswith("/")]
        if len(names) != 1:
            raise RuntimeError(f"Expected one CSV in {path}, found {names}")
        with zf.open(names[0]) as raw:
            return list(csv.reader(io.TextIOWrapper(raw, encoding="utf-8")))


def load_klines(paths: list[Path], symbol: str) -> tuple[list[dict], dict]:
    by_open = {}
    malformed = 0
    for path in paths:
        for row in archive_rows(path):
            if not row or not row[0].isdigit():
                continue
            if len(row) < 12:
                malformed += 1
                continue
            try:
                bar = {"open_ms": int(row[0]), "open": float(row[1]), "high": float(row[2]), "low": float(row[3]),
                       "close": float(row[4]), "volume": float(row[5]), "close_ms": int(row[6]), "quote_volume": float(row[7]),
                       "trades": int(row[8])}
            except (ValueError, IndexError):
                malformed += 1
                continue
            if min(bar["open"], bar["high"], bar["low"], bar["close"]) <= 0 or bar["volume"] < 0:
                malformed += 1
                continue
            if bar["high"] < max(bar["open"], bar["close"]) or bar["low"] > min(bar["open"], bar["close"]):
                malformed += 1
                continue
            if bar["close_ms"] - bar["open_ms"] != 3_599_999:
                malformed += 1
                continue
            by_open[bar["open_ms"]] = bar
    bars = [by_open[k] for k in sorted(by_open)]
    gaps = []
    for left, right in zip(bars, bars[1:]):
        if right["open_ms"] - left["open_ms"] != 3_600_000:
            gaps.append([left["open_ms"], right["open_ms"]])
    if malformed or gaps:
        raise RuntimeError(f"Data validation failed for {symbol}: malformed={malformed}, gaps={len(gaps)}")
    return bars, {"rows": len(bars), "duplicates_removed": sum(len(archive_rows(p)) - 1 for p in paths) - len(bars), "malformed": malformed, "gaps": gaps}


def load_funding(paths: list[Path]) -> list[tuple[int, float]]:
    values = {}
    for path in paths:
        for row in archive_rows(path):
            if not row or not row[0].isdigit():
                continue
            # Binance Vision schema: calc_time, funding_interval_hours, last_funding_rate
            if len(row) >= 3:
                values[int(row[0])] = float(row[2])
    return sorted(values.items())


def true_range_pct(window: list[dict]) -> float:
    # Exact TradeForecastService::computeAtrAvgPct() default behavior: latest 21 candles,
    # 20 true ranges (starting at index 1), divided by the latest close.
    if len(window) > 21:
        window = window[-21:]
    trs = []
    for i in range(1, min(len(window), 21)):
        bar = window[i]
        prev = window[i - 1]["close"]
        trs.append(max(bar["high"] - bar["low"], abs(bar["high"] - prev), abs(bar["low"] - prev)))
    latest_close = window[-1]["close"]
    return 100.0 * (sum(trs) / len(trs)) / latest_close if trs and latest_close > 0 else 0.0


def autofinx_side(context: list[dict]) -> str:
    scores = []
    for count, weight in ((12, 0.5), (24, 0.3), (48, 0.2)):
        w = context[-count:]
        move_pct = 100.0 * (w[-1]["close"] / w[0]["open"] - 1.0)
        reference = max(0.05, true_range_pct(w))
        scores.append(max(-1.0, min(1.0, move_pct / reference)) * weight)
    composite = round(sum(scores), 4)
    return "LONG" if composite >= 0.15 else ("SHORT" if composite <= -0.15 else "WAIT")


def side_return(side: str, entry: float, exit_: float, entry_ms: int, exit_ms: int, funding: list[tuple[int, float]], cost_multiplier: float = 1.0) -> tuple[float, float, float]:
    if side == "WAIT":
        return 0.0, 0.0, 0.0
    sign = 1.0 if side == "LONG" else -1.0
    gross = sign * (exit_ / entry - 1.0)
    funding_cost = sign * sum(rate for ts, rate in funding if entry_ms < ts <= exit_ms)
    fixed = cost_multiplier * 2.0 * (5.0 + 1.0) / 10_000.0
    return gross, gross - fixed - funding_cost, funding_cost


def percentile(values: list[float], p: float) -> float:
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, int(math.floor(p * len(ordered)))))
    return ordered[index]


def bootstrap_ci(values: list[float], seed: int, iterations: int = 5000) -> list[float] | None:
    if not values:
        return None
    rng = random.Random(seed)
    means = [sum(rng.choice(values) for _ in values) / len(values) for _ in range(iterations)]
    return [percentile(means, 0.025), percentile(means, 0.975)]


def strategy_metrics(rows: list[dict], name: str, cost_multiplier: float) -> dict:
    trades = [r for r in rows if r["sides"][name] != "WAIT"]
    net = [r["returns"][name][f"net_x{cost_multiplier:g}"] for r in trades]
    gross = [r["returns"][name]["gross"] for r in trades]
    return {"samples": len(rows), "trades": len(trades), "waits": len(rows) - len(trades),
            "gross_mean": statistics.fmean(gross) if gross else None,
            "net_mean": statistics.fmean(net) if net else None,
            "net_sum": sum(net), "net_hit_rate": sum(v > 0 for v in net) / len(net) if net else None,
            "net_median": statistics.median(net) if net else None,
            "net_ci95": bootstrap_ci(net, PLAN["seed"] + int(cost_multiplier * 10)),
            "actual_funding_cost_sum": sum(r["returns"][name]["funding_cost"] for r in trades)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--model-snapshot", required=True)
    parser.add_argument("--batch-size", type=int, default=16)
    args = parser.parse_args()
    out = Path(args.out).resolve()
    raw = out / "raw"
    raw.mkdir(parents=True, exist_ok=False)
    (out / "plan.json").write_text(json.dumps(PLAN, indent=2), encoding="utf-8")
    (out / "PLAN_SHA256.txt").write_text(canonical_hash(PLAN) + "\n", encoding="ascii")

    snapshot = Path(args.model_snapshot).resolve()
    if snapshot.name != PLAN["chronos_snapshot"] or not (snapshot / "model.safetensors").is_file():
        raise RuntimeError(f"Exact Chronos snapshot unavailable or wrong: {snapshot}")

    provenance = []
    klines_by_symbol, funding_by_symbol, validations = {}, {}, {}
    for symbol in PLAN["symbols"]:
        kpaths, fpaths = [], []
        for month in PLAN["archive_months"]:
            for kind, suffix in (("klines", f"klines/{symbol}/1h/{symbol}-1h-{month}.zip"),
                                 ("funding", f"fundingRate/{symbol}/{symbol}-fundingRate-{month}.zip")):
                url = "https://data.binance.vision/data/futures/um/monthly/" + suffix
                path = raw / Path(suffix).name
                provenance.append(download_verified(url, path))
                (kpaths if kind == "klines" else fpaths).append(path)
        klines_by_symbol[symbol], validations[symbol] = load_klines(kpaths, symbol)
        funding_by_symbol[symbol] = load_funding(fpaths)
        validations[symbol]["funding_events"] = len(funding_by_symbol[symbol])
    (out / "data_provenance.json").write_text(json.dumps(provenance, indent=2), encoding="utf-8")
    (out / "data_validation.json").write_text(json.dumps(validations, indent=2), encoding="utf-8")

    start_ms = int(datetime.fromisoformat(PLAN["test_start_utc"].replace("Z", "+00:00")).timestamp() * 1000)
    end_ms = int(datetime.fromisoformat(PLAN["test_end_utc"].replace("Z", "+00:00")).timestamp() * 1000)
    contexts, rows = [], []
    for symbol in PLAN["symbols"]:
        bars = klines_by_symbol[symbol]
        for i in range(PLAN["context_bars"] - 1, len(bars) - PLAN["horizon_bars"], PLAN["step_bars"]):
            as_of_ms = bars[i]["close_ms"] + 1
            if not (start_ms <= as_of_ms <= end_ms):
                continue
            h = PLAN["horizon_bars"]
            context = bars[i - PLAN["context_bars"] + 1:i + 1]
            target = bars[i + h]
            if target["close_ms"] + 1 >= int(datetime(2026, 1, 4, tzinfo=timezone.utc).timestamp() * 1000):
                raise RuntimeError("Protected holdout boundary reached")
            key = f"{symbol}|{as_of_ms}"
            contexts.append({"key": key, "closes": [x["close"] for x in context]})
            entry = context[-1]["close"]
            drift = sum(context[j]["close"] - context[j - 1]["close"] for j in range(len(context) - 23, len(context))) / 23.0
            drift_forecast = entry + drift * h
            threshold = PLAN["decision_threshold_fraction"]
            momentum = context[-1]["close"] / context[-1 - h]["close"] - 1.0
            sides = {"buy_hold": "LONG", "momentum_4": "LONG" if momentum > 0 else ("SHORT" if momentum < 0 else "WAIT"),
                     "drift_24": "LONG" if drift_forecast / entry - 1 > threshold else ("SHORT" if drift_forecast / entry - 1 < -threshold else "WAIT"),
                     "autofinx_existing": autofinx_side(context), "chronos2": None, "naive_last": "WAIT"}
            rows.append({"key": key, "symbol": symbol, "as_of_ms": as_of_ms, "label_at_ms": target["close_ms"] + 1,
                         "entry": entry, "exit": target["close"], "naive_p50": entry, "drift_p50": drift_forecast,
                         "sides": sides, "returns": {}})

    from chronos import Chronos2Pipeline
    import torch
    pipeline = Chronos2Pipeline.from_pretrained(str(snapshot), device_map="cpu", local_files_only=True)
    forecasts, inference_batches = {}, []
    for offset in range(0, len(contexts), args.batch_size):
        chunk = contexts[offset:offset + args.batch_size]
        t0 = time.perf_counter()
        q, _ = pipeline.predict_quantiles([torch.tensor(w["closes"], dtype=torch.float32) for w in chunk],
                                          prediction_length=PLAN["horizon_bars"], quantile_levels=[0.1, 0.5, 0.9])
        inference_batches.append({"offset": offset, "size": len(chunk), "seconds": time.perf_counter() - t0})
        for window, tensor in zip(chunk, q):
            final = tensor[0][-1].tolist()
            vals = [float(x) for x in final]
            if not all(math.isfinite(x) for x in vals) or not vals[0] <= vals[1] <= vals[2]:
                raise RuntimeError(f"Invalid Chronos quantiles for {window['key']}: {vals}")
            forecasts[window["key"]] = vals

    # Independent repeatability check through the public API on the first untouched context.
    q_repeat, _ = pipeline.predict_quantiles([torch.tensor(contexts[0]["closes"], dtype=torch.float32)],
                                              prediction_length=PLAN["horizon_bars"], quantile_levels=[0.1, 0.5, 0.9])
    repeat = [float(x) for x in q_repeat[0][0][-1].tolist()]
    repeat_delta = max(abs(a - b) for a, b in zip(repeat, forecasts[contexts[0]["key"]]))

    for row in rows:
        p10, p50, p90 = forecasts[row["key"]]
        row["chronos"] = {"p10": p10, "p50": p50, "p90": p90}
        rel = p50 / row["entry"] - 1.0
        threshold = PLAN["decision_threshold_fraction"]
        row["sides"]["chronos2"] = "LONG" if rel > threshold else ("SHORT" if rel < -threshold else "WAIT")
        funding = funding_by_symbol[row["symbol"]]
        for name, side in row["sides"].items():
            gross, net1, funding_cost = side_return(side, row["entry"], row["exit"], row["as_of_ms"], row["label_at_ms"], funding, 1.0)
            _, net2, _ = side_return(side, row["entry"], row["exit"], row["as_of_ms"], row["label_at_ms"], funding, 2.0)
            row["returns"][name] = {"gross": gross, "net_x1": net1, "net_x2": net2, "funding_cost": funding_cost}

    actual = [r["exit"] for r in rows]
    forecast_metrics = {}
    for name, getter in (("naive_last", lambda r: r["naive_p50"]), ("drift_24", lambda r: r["drift_p50"]), ("chronos2", lambda r: r["chronos"]["p50"])):
        errors = [(getter(r) - r["exit"]) / r["entry"] for r in rows]
        dirs = [(getter(r) - r["entry"], r["exit"] - r["entry"]) for r in rows]
        forecast_metrics[name] = {"relative_mae": statistics.fmean(abs(x) for x in errors),
                                  "relative_rmse": math.sqrt(statistics.fmean(x * x for x in errors)),
                                  "direction_accuracy": sum((a > 0) == (b > 0) for a, b in dirs if a != 0 and b != 0) / max(1, sum(a != 0 and b != 0 for a, b in dirs))}
    forecast_metrics["chronos2"]["p10_p90_coverage"] = sum(r["chronos"]["p10"] <= r["exit"] <= r["chronos"]["p90"] for r in rows) / len(rows)

    strategy = {f"cost_x{m:g}": {name: strategy_metrics(rows, name, m) for name in PLAN["strategies"]}
                for m in (1.0, 2.0)}
    evidence = {"plan_sha256": canonical_hash(PLAN), "dataset_manifest_sha256": canonical_hash(provenance),
                "environment": {"python": sys.version, "platform": platform.platform(), "torch": torch.__version__},
                "model": {"repo": PLAN["chronos_repo"], "snapshot": snapshot.name, "class": type(pipeline).__name__,
                          "repeatability_max_abs_delta": repeat_delta},
                "sample_count": len(rows), "symbols": {s: sum(r["symbol"] == s for r in rows) for s in PLAN["symbols"]},
                "inference": {"batches": len(inference_batches), "seconds": sum(x["seconds"] for x in inference_batches),
                              "median_batch_seconds": statistics.median(x["seconds"] for x in inference_batches)},
                "forecast_metrics": forecast_metrics, "strategy_metrics": strategy, "execution_authorized": False}
    (out / "result.json").write_text(json.dumps(evidence, indent=2), encoding="utf-8")
    (out / "rows.jsonl").write_text("".join(json.dumps(r, separators=(",", ":")) + "\n" for r in rows), encoding="utf-8")
    print(json.dumps(evidence, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
