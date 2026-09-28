"""Train a leakage-safe LightGBM return model from closed-candle CSV data.

Expected columns: timestamp, open, high, low, close, volume.
This script deliberately requires real input data and never downloads exchange
data or places orders.
"""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path


def load_rows(path: Path) -> list[dict[str, float]]:
    with path.open(newline="", encoding="utf-8") as handle:
        rows = list(csv.DictReader(handle))
    required = {"timestamp", "open", "high", "low", "close", "volume"}
    if not rows or not required.issubset(rows[0]):
        raise ValueError(f"input must contain columns: {', '.join(sorted(required))}")
    parsed = [{key: float(row[key]) for key in required} for row in rows]
    timestamps = [row["timestamp"] for row in parsed]
    if timestamps != sorted(timestamps) or len(set(timestamps)) != len(timestamps):
        raise ValueError("timestamps must be strictly increasing")
    if any(row["close"] <= 0 or row["volume"] < 0 for row in parsed):
        raise ValueError("prices must be positive and volume cannot be negative")
    return parsed


def features_and_target(rows: list[dict[str, float]], horizon: int) -> tuple[list[list[float]], list[float]]:
    if horizon < 1 or len(rows) < horizon + 30:
        raise ValueError("not enough rows for the requested horizon")
    features: list[list[float]] = []
    target: list[float] = []
    for index in range(20, len(rows) - horizon):
        close = rows[index]["close"]
        previous = rows[index - 1]["close"]
        high20 = max(row["high"] for row in rows[index - 20 : index])
        low20 = min(row["low"] for row in rows[index - 20 : index])
        volume20 = sum(row["volume"] for row in rows[index - 20 : index]) / 20
        features.append([
            (close / previous) - 1,
            (close / low20) - 1,
            (high20 / close) - 1,
            rows[index]["volume"] / volume20 if volume20 else 0,
        ])
        target.append((rows[index + horizon]["close"] / close) - 1)
    return features, target


def train(input_path: Path, output_path: Path, horizon: int) -> None:
    rows = load_rows(input_path)
    features, target = features_and_target(rows, horizon)
    split = int(len(features) * 0.8)
    if split < 1 or split >= len(features):
        raise ValueError("time-series split produced an empty train or validation set")
    try:
        import lightgbm as lgb
    except ImportError as exc:
        raise RuntimeError("install lightgbm before training") from exc
    model = lgb.LGBMRegressor(n_estimators=200, learning_rate=0.03, num_leaves=31, random_state=42)
    model.fit(features[:split], target[:split], eval_set=[(features[split:], target[split:])], callbacks=[lgb.early_stopping(20, verbose=False)])
    output_path.parent.mkdir(parents=True, exist_ok=True)
    model.booster_.save_model(str(output_path))
    metadata = {
        "model": "lightgbm",
        "horizon_steps": horizon,
        "feature_version": "ohlcv-v1",
        "features": ["return_1", "distance_from_low_20", "distance_from_high_20", "volume_ratio_20"],
        "rows": len(rows), "train_rows": split, "validation_rows": len(features) - split,
        "split": "chronological_80_20", "execution": False,
    }
    output_path.with_suffix(".json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--horizon", type=int, default=12)
    args = parser.parse_args()
    train(args.input, args.output, args.horizon)
