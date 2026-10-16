from __future__ import annotations
import json, statistics
from pathlib import Path
from collections import defaultdict
from .factor_lab import FACTORS, POLICY, load, funding
from .contracts import EventTrade
from .event_simulator import simulate_event_trade


def main(factor_json: str, raw: str, out: str) -> None:
    source = json.loads(Path(factor_json).read_text(encoding="utf-8"))
    grouped = defaultdict(dict)
    for row in source["rows"]:
        grouped[(row["symbol"], row["decision_ms"])][row["factor"]] = row
    results = []
    for (symbol, decision_ms), rows in grouped.items():
        bars = sorted(load(Path(raw) / f"{symbol}-1h-2025-10.zip") + load(Path(raw) / f"{symbol}-1h-2025-11.zip") + load(Path(raw) / f"{symbol}-1h-2025-12.zip"), key=lambda x: x["open_ms"])
        index = next((i for i, b in enumerate(bars) if b["close_ms"] + 1 == decision_ms), None)
        if index is None or index + 25 >= len(bars):
            continue
        votes = {f: rows[f]["side"] for f in FACTORS}
        def vote(weights):
            score = sum(weights[f] * (1 if votes[f] == "LONG" else -1 if votes[f] == "SHORT" else 0) for f in FACTORS)
            return "LONG" if score > 0 else "SHORT" if score < 0 else "WAIT"
        decisions = {"equal_weight": vote({f: 1 for f in FACTORS}),
                     "correlation_aware": vote({"price_action": .5, "volume": 1, "volatility": 1, "rsi": .5})}
        rates = funding(Path(raw) / f"{symbol}-fundingRate-2025-10.zip") + funding(Path(raw) / f"{symbol}-fundingRate-2025-11.zip") + funding(Path(raw) / f"{symbol}-fundingRate-2025-12.zip")
        entry = bars[index + 1]
        future = [{**b, "ts_ms": b["open_ms"]} for b in bars[index + 1:index + 25]]
        for name, side in decisions.items():
            row = {"symbol": symbol, "decision_ms": decision_ms, "split": rows["price_action"]["split"], "strategy": name, "side": side}
            if side == "WAIT":
                row.update({"event": "WAIT", "net": None})
            else:
                outcome = simulate_event_trade(EventTrade(symbol, decision_ms, entry["open_ms"], entry["open"], side, POLICY, "combo-lab-v1", f"{symbol}:{decision_ms}:{name}"), future, rates)
                row.update({"event": outcome.event, "net": outcome.net_return, "gross": outcome.gross_return,
                            "fee": outcome.fee_return, "slippage": outcome.slippage_return,
                            "funding": outcome.funding_return, "holding_ms": outcome.holding_ms,
                            "mfe": outcome.mfe_fraction, "mae": outcome.mae_fraction,
                            "ambiguous": outcome.ambiguous, "censored": outcome.open_censored})
            results.append(row)
    summary = {}
    for name in ("equal_weight", "correlation_aware"):
        for split in ("development", "validation", "test"):
            rows = [r for r in results if r["strategy"] == name and r["split"] == split]
            values = [r["net"] for r in rows if r["net"] is not None]
            summary[f"{name}:{split}"] = {"samples": len(rows), "trades": len(values), "waits": len(rows)-len(values), "net_mean": statistics.fmean(values) if values else None, "net_sum": sum(values), "hit_rate": sum(v > 0 for v in values)/len(values) if values else None}
    payload = {"schema": "autofinx.factor-combination-lab.v1", "weights_frozen_before_test": True, "summary": summary, "rows": results, "execution_authorized": False}
    Path(out).write_text(json.dumps(payload, indent=2), encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    import argparse
    p = argparse.ArgumentParser(); p.add_argument("--factor-json", required=True); p.add_argument("--raw", required=True); p.add_argument("--out", required=True); a = p.parse_args(); main(a.factor_json, a.raw, a.out)
