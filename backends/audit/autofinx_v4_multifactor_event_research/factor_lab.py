"""Offline individual-factor lab over an already verified Binance Vision manifest.

Research-only: fixed rules are preregistered in code, no fitting or threshold tuning, and output
contains no executable order fields.
"""
from __future__ import annotations

import csv, io, json, statistics, zipfile
from pathlib import Path
from .contracts import EventTrade, ExitPolicy
from .event_simulator import simulate_event_trade
from .features import causal_features

SYMBOLS = ("BTCUSDT", "ETHUSDT", "SOLUSDT")
POLICY = ExitPolicy(.02, .01, .005, .003, .03)
FACTORS = ("price_action", "volume", "volatility", "rsi", "candle_body", "candle_range", "trend_slope")


def load(path: Path) -> list[dict]:
    with zipfile.ZipFile(path) as z:
        name = [n for n in z.namelist() if not n.endswith("/")][0]
        rows = []
        with z.open(name) as raw:
            for r in csv.reader(io.TextIOWrapper(raw)):
                if r and r[0].isdigit():
                    rows.append({"open_ms": int(r[0]), "close_ms": int(r[6]), "open": float(r[1]), "high": float(r[2]), "low": float(r[3]), "close": float(r[4]), "volume": float(r[5])})
        return rows


def funding(path: Path) -> list[tuple[int, float]]:
    with zipfile.ZipFile(path) as z:
        name = [n for n in z.namelist() if not n.endswith("/")][0]
        with z.open(name) as raw:
            return [(int(r[0]), float(r[2])) for r in csv.reader(io.TextIOWrapper(raw)) if r and r[0].isdigit() and len(r) >= 3]


def signal(features: dict, factor: str) -> str:
    # Fixed, deliberately simple research rules. These are not production algorithms.
    value = {"price_action": features["return_4"], "volume": features["relative_volume_20"],
             "volatility": features["atr_pct_20"], "rsi": features["rsi_14"],
             "candle_body": features["body_pct"], "candle_range": features["range_pct"],
             "trend_slope": features["trend_slope_20"]}[factor]
    if value is None:
        return "WAIT"
    if factor == "price_action":
        return "LONG" if value > .002 else "SHORT" if value < -.002 else "WAIT"
    if factor == "volume":
        return "WAIT" if value < 1.5 else ("LONG" if features["return_4"] > 0 else "SHORT")
    if factor == "volatility":
        return "WAIT" if value < .002 else ("LONG" if features["return_4"] > 0 else "SHORT")
    if factor == "rsi":
        return "LONG" if value > .58 else "SHORT" if value < .42 else "WAIT"
    if factor == "candle_body":
        return "LONG" if value > .001 else "SHORT" if value < -.001 else "WAIT"
    if factor == "candle_range":
        return "WAIT" if value < .002 else ("LONG" if features["return_4"] > 0 else "SHORT")
    return "LONG" if value > .0002 else "SHORT" if value < -.0002 else "WAIT"


def regime(features: dict) -> str:
    """Simple causal diagnostic regime, not a trading signal."""
    vol = features["atr_pct_20"] or 0.0
    ret = features["return_4"] or 0.0
    return "HIGH_VOL_UP" if vol >= .01 and ret > 0 else "HIGH_VOL_DOWN" if vol >= .01 else "LOW_VOL_UP" if ret > 0 else "LOW_VOL_DOWN"


def main(root: str, out: str) -> None:
    base, results = Path(root), []
    for symbol in SYMBOLS:
        bars = load(base / f"{symbol}-1h-2025-10.zip") + load(base / f"{symbol}-1h-2025-11.zip") + load(base / f"{symbol}-1h-2025-12.zip")
        bars.sort(key=lambda x: x["open_ms"])
        rates = funding(base / f"{symbol}-fundingRate-2025-10.zip") + funding(base / f"{symbol}-fundingRate-2025-11.zip") + funding(base / f"{symbol}-fundingRate-2025-12.zip")
        for i in range(256, len(bars) - 25, 24):
            decision_ms = bars[i]["close_ms"] + 1
            if decision_ms < 1761955200000 or decision_ms > 1767225599999:  # Nov 1 through Dec 31
                continue
            context = bars[i-255:i+1]
            fs = causal_features(context)
            entry = bars[i+1]
            future = bars[i+1:i+25]
            split = "development" if decision_ms < 1763251200000 else "validation" if decision_ms < 1764547200000 else "test"
            for factor in FACTORS:
                side = signal(fs, factor)
                if side == "WAIT":
                    results.append({"symbol": symbol, "decision_ms": decision_ms, "factor": factor, "split": split, "regime": regime(fs), "features": fs, "side": side, "net": None})
                    continue
                trade = EventTrade(symbol, decision_ms, entry["open_ms"], entry["open"], side, POLICY, "factor-lab-v1", f"{symbol}:{decision_ms}:{factor}")
                outcome = simulate_event_trade(trade, [{**row, "ts_ms": row["open_ms"]} for row in future], rates)
                results.append({"symbol": symbol, "decision_ms": decision_ms, "factor": factor, "split": split, "regime": regime(fs), "features": fs, "side": side, "event": outcome.event, "net": outcome.net_return, "mfe": outcome.mfe_fraction, "mae": outcome.mae_fraction})
    summary = {}
    for factor in FACTORS:
        for split in ("development", "validation", "test"):
            rows = [r for r in results if r["factor"] == factor and r["split"] == split]
            trades = [r for r in rows if r["net"] is not None]
            values = [r["net"] for r in trades if r["net"] is not None]
            summary[f"{factor}:{split}"] = {"samples": len(rows), "trades": len(trades), "waits": len(rows)-len(trades), "net_mean": statistics.fmean(values) if values else None, "net_sum": sum(values), "hit_rate": sum(x > 0 for x in values)/len(values) if values else None, "open_censored": sum(r.get("event") == "OPEN_CENSORED" for r in trades), "ambiguous": sum(r.get("event") == "SL" and False for r in trades)}
    for factor in FACTORS:
        for reg in ("HIGH_VOL_UP", "HIGH_VOL_DOWN", "LOW_VOL_UP", "LOW_VOL_DOWN"):
            rows = [r for r in results if r["factor"] == factor and r["split"] == "test" and r["regime"] == reg and r["net"] is not None]
            values = [r["net"] for r in rows]
            summary[f"{factor}:test:{reg}"] = {"trades": len(values), "net_mean": statistics.fmean(values) if values else None, "hit_rate": sum(x > 0 for x in values)/len(values) if values else None}
    pairs = [("return_4", "relative_volume_20"), ("return_4", "atr_pct_20"), ("return_4", "rsi_14"), ("relative_volume_20", "atr_pct_20")]
    correlations = {}
    for a, b in pairs:
        xs = [r["features"][a] for r in results if r["split"] == "test" and r["features"][a] is not None and r["features"][b] is not None]
        ys = [r["features"][b] for r in results if r["split"] == "test" and r["features"][a] is not None and r["features"][b] is not None]
        mx, my = statistics.fmean(xs), statistics.fmean(ys)
        den = (sum((x-mx)**2 for x in xs) * sum((y-my)**2 for y in ys)) ** .5
        correlations[f"{a}:{b}"] = sum((x-mx)*(y-my) for x,y in zip(xs,ys))/den if den else None
    payload = {"schema": "autofinx.factor-lab.v1", "policy": {"tp": .02, "sl": .01, "latency_bars": 1, "fee_bps_per_side": 5, "slippage_bps_per_side": 1}, "factors": FACTORS, "summary": summary, "test_feature_correlations": correlations, "rows": results, "execution_authorized": False}
    Path(out).write_text(json.dumps(payload, indent=2), encoding="utf-8")
    print(json.dumps({"summary": summary, "execution_authorized": False}, indent=2))


if __name__ == "__main__":
    import argparse
    p = argparse.ArgumentParser(); p.add_argument("--root", required=True); p.add_argument("--out", required=True)
    a = p.parse_args(); main(a.root, a.out)
