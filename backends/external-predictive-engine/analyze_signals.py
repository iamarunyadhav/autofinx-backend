"""Findings-only analysis of an existing regression model's holdout signals.

Re-scores a trained (usually REJECTED) regression model on its own holdout
window, collapses the per-anchor signals into NON-overlapping episodes, and
reports how those episodes did after fees + funding and what the features
looked like at entry versus the holdout as a whole. It never builds or
suggests an inverted strategy.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

import shadow_sim


def analyze(input_path: Path, manifest_path: Path, model_dir: Path, top: int = 10) -> dict[str, Any]:
    import lightgbm as lgb
    import numpy as np

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    meta = json.loads((model_dir / "metadata.json").read_text(encoding="utf-8"))
    names = meta["features"]
    if any(name not in manifest["features"] for name in names):
        raise ValueError("export schema lacks features this model needs")
    cols = [manifest["features"].index(name) for name in names]

    times, rows, returns, funding = [], [], [], []
    with input_path.open(encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                record = json.loads(line)
                times.append(int(record["t"]))
                rows.append([float(v) for v in record["x"]])
                returns.append(float(record["y"]) * 100)
                funding.append(float(record.get("fb", 0.0)))
    times = np.asarray(times)
    x = np.asarray(rows)[:, cols]
    returns, funding = np.asarray(returns), np.asarray(funding)

    window = (times >= int(meta["holdout_from_ms"])) & (times <= int(meta["holdout_to_ms"]))
    if not window.any():
        raise ValueError("model holdout window is not covered by this export")
    t, xh, r, f = times[window], x[window], returns[window], funding[window]
    cost, required = float(manifest["cost_pct"]), float(manifest["required_move_pct"])
    horizon_ms = int(manifest["horizon_minutes"]) * 60_000
    predicted = lgb.Booster(model_file=str(model_dir / "model.txt")).predict(xh)
    signal = np.where(predicted - f >= required, 1, np.where(-predicted + f >= required, -1, 0))

    sim = shadow_sim.simulate(t, signal, r, f, cost, horizon_ms)
    trades = sim.pop("trade_list")
    entry_index = [trade["index"] for trade in trades]
    feature_report = []
    if entry_index:
        overall_mean, overall_std = xh.mean(axis=0), xh.std(axis=0) + 1e-12
        entry_mean = xh[entry_index].mean(axis=0)
        z = (entry_mean - overall_mean) / overall_std
        for i in np.argsort(-np.abs(z))[:top]:
            feature_report.append({"feature": names[i], "entry_mean": float(entry_mean[i]), "holdout_mean": float(overall_mean[i]), "z_shift": float(z[i])})

    return {
        "model_version_dir": str(model_dir),
        "horizon_minutes": int(manifest["horizon_minutes"]),
        "holdout_rows": int(window.sum()),
        "raw_signal_rows": int((signal != 0).sum()),
        "non_overlapping": sim,
        "distinct_episodes": len(trades),
        "episodes_by_side": {"buy": sum(1 for tr in trades if tr["side"] > 0), "sell": sum(1 for tr in trades if tr["side"] < 0)},
        "episodes": [{"t": tr["t"], "side": "BUY" if tr["side"] > 0 else "SELL", "net_pct": round(tr["net_pct"], 4)} for tr in trades[:50]],
        "features_at_entry_vs_holdout": feature_report,
        "baselines": shadow_sim.baselines(t, r, f, cost, horizon_ms),
        "note": "Findings only. No inverted strategy is derived from these signals.",
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--model-dir", type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(analyze(args.input, args.manifest, args.model_dir), indent=2))
