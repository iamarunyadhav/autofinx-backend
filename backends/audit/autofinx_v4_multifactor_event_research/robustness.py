from __future__ import annotations
import json, statistics
from pathlib import Path


def main(source: str, out: str) -> None:
    payload = json.loads(Path(source).read_text(encoding="utf-8"))
    result = {}
    for strategy in ("equal_weight", "correlation_aware"):
        for split in ("development", "validation", "test"):
            rows = [r for r in payload["rows"] if r["strategy"] == strategy and r["split"] == split and r.get("gross") is not None]
            by_cost = {}
            for multiplier in (1.0, 2.0):
                values = [r["gross"] + r["funding"] - multiplier * (12 / 10_000) for r in rows]
                positives = [x for x in values if x > 0]
                negatives = [-x for x in values if x < 0]
                equity = peak = drawdown = 0.0
                for x in values:
                    equity += x; peak = max(peak, equity); drawdown = min(drawdown, equity - peak)
                durations = [r["holding_ms"] / 3_600_000 for r in rows if r.get("holding_ms") is not None]
                by_cost[f"cost_x{multiplier:g}"] = {"trades": len(values), "net_mean": statistics.fmean(values) if values else None,
                    "profit_factor": sum(positives) / sum(negatives) if negatives else None,
                    "max_drawdown": drawdown, "hit_rate": sum(x > 0 for x in values) / len(values) if values else None,
                    "mean_holding_hours": statistics.fmean(durations) if durations else None,
                    "mean_mfe": statistics.fmean(r["mfe"] for r in rows) if rows else None,
                    "mean_mae": statistics.fmean(r["mae"] for r in rows) if rows else None,
                    "censored": sum(bool(r.get("censored")) for r in rows), "ambiguous": sum(bool(r.get("ambiguous")) for r in rows),
                    "tp": sum(r.get("event") == "TP" for r in rows), "sl": sum(r.get("event") == "SL" for r in rows),
                    "liquidation": sum(r.get("event") == "LIQUIDATION" for r in rows)}
            result[f"{strategy}:{split}"] = by_cost
    output = {"schema": "autofinx.robustness.v1", "source": source, "metrics": result, "execution_authorized": False}
    Path(out).write_text(json.dumps(output, indent=2), encoding="utf-8")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    import argparse
    p = argparse.ArgumentParser(); p.add_argument("--source", required=True); p.add_argument("--out", required=True); a = p.parse_args(); main(a.source, a.out)
