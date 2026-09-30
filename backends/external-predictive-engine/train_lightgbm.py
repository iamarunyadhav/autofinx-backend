"""Train a shadow-only LightGBM model from an audited fresh candle snapshot."""

from __future__ import annotations

import argparse
import csv
import json
import math
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


INTERVAL_SECONDS = {"1m": 60, "5m": 300, "15m": 900, "45m": 2700, "1h": 3600, "4h": 14400}


def timestamp_seconds(value: Any) -> float:
    if isinstance(value, (int, float)):
        return float(value) / 1000 if value > 10_000_000_000 else float(value)
    try:
        return float(str(value).strip())
    except ValueError:
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        return (parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)).astimezone(timezone.utc).timestamp()


def load_rows(path: Path, timeframe: str) -> list[dict[str, float]]:
    if path.suffix.lower() == ".jsonl":
        with path.open(encoding="utf-8") as handle:
            raw_rows = [json.loads(line) for line in handle if line.strip()]
    else:
        with path.open(newline="", encoding="utf-8") as handle:
            raw_rows = list(csv.DictReader(handle))
    required = {"open", "high", "low", "close", "volume"}
    timestamp_key = "ts" if path.suffix.lower() == ".jsonl" else "timestamp"
    if not raw_rows or not required.issubset(raw_rows[0]) or timestamp_key not in raw_rows[0]:
        raise ValueError("input must be a canonical JSONL/CSV candle export with timestamp and OHLCV fields")
    rows = [{"timestamp": timestamp_seconds(row[timestamp_key]), **{key: float(row[key]) for key in required}} for row in raw_rows]
    timestamps = [row["timestamp"] for row in rows]
    if timestamps != sorted(timestamps) or len(set(timestamps)) != len(timestamps):
        raise ValueError("timestamps must be strictly increasing and unique")
    interval = INTERVAL_SECONDS.get(timeframe)
    if interval is None:
        raise ValueError("unsupported timeframe")
    if any(int(row["timestamp"]) % interval != 0 for row in rows):
        raise ValueError("timestamp is not on the exact requested candle boundary")
    if any(not math.isclose(right - left, interval, abs_tol=0.001) for left, right in zip(timestamps, timestamps[1:])):
        raise ValueError("input contains a candle gap; repair and re-audit before training")
    if any(row["close"] <= 0 or row["low"] <= 0 or row["volume"] < 0 for row in rows):
        raise ValueError("prices must be positive and volume cannot be negative")
    return rows


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
        features.append([(close / previous) - 1, (close / low20) - 1, (high20 / close) - 1, rows[index]["volume"] / volume20 if volume20 else 0])
        target.append((rows[index + horizon]["close"] / close) - 1)
    return features, target


def estimated_round_trip_cost_pct(
    fee_bps_per_leg: float, slippage_bps: float, spread_bps: float, funding_bps_per_interval: float,
    holding_seconds: int,
) -> float:
    """Conservative cost estimate. Unknown execution costs are never silently zero."""
    funding_intervals = holding_seconds // (8 * 60 * 60)
    return ((max(0.0, fee_bps_per_leg) * 2) + max(0.0, slippage_bps) + max(0.0, spread_bps)
            + (max(0.0, funding_bps_per_interval) * funding_intervals)) / 10_000


def evaluate_predictions(
    predictions: Any, actual: Any, cost_pct: float, minimum_edge_bps: float,
) -> dict[str, Any]:
    """Evaluate only model-selected trades against unseen outcomes, net of costs."""
    import numpy as np

    raw_direction_accuracy = float(np.mean((predictions >= 0) == (actual >= 0)) * 100)
    threshold = cost_pct + (max(0.0, minimum_edge_bps) / 10_000)
    selected = np.abs(predictions) >= threshold
    trade_count = int(np.sum(selected))
    if trade_count == 0:
        return {
            "direction_accuracy_pct": raw_direction_accuracy,
            "trade_count": 0,
            "trade_rate_pct": 0.0,
            "net_win_rate_pct": None,
            "average_net_return_pct": None,
            "total_net_return_pct": None,
        }

    directional_return = np.sign(predictions[selected]) * actual[selected]
    net_returns = directional_return - cost_pct
    return {
        "direction_accuracy_pct": raw_direction_accuracy,
        "trade_count": trade_count,
        "trade_rate_pct": float(trade_count / len(actual) * 100),
        "net_win_rate_pct": float(np.mean(net_returns > 0) * 100),
        "average_net_return_pct": float(np.mean(net_returns) * 100),
        "total_net_return_pct": float(np.sum(net_returns) * 100),
    }


def fit_model(lgb: Any, data: Any, labels: Any, train_end: int, validation_end: int) -> Any:
    model = lgb.LGBMRegressor(n_estimators=300, learning_rate=0.03, num_leaves=31, random_state=42)
    model.fit(
        data[:train_end], labels[:train_end],
        eval_set=[(data[train_end:validation_end], labels[train_end:validation_end])],
        callbacks=[lgb.early_stopping(30, verbose=False)],
    )
    return model


def walk_forward_metrics(lgb: Any, data: Any, labels: Any, cost_pct: float, minimum_edge_bps: float) -> dict[str, Any]:
    """Expanding-window tests: every tested slice occurs strictly after its training data."""
    total = len(data)
    folds: list[dict[str, Any]] = []
    for train_fraction in (0.55, 0.65, 0.75):
        train_end = int(total * train_fraction)
        validation_start = int(train_end * 0.85)
        test_end = min(total, train_end + max(50, int(total * 0.10)))
        if validation_start < 30 or train_end <= validation_start or test_end <= train_end:
            continue
        model = fit_model(lgb, data, labels, validation_start, train_end)
        fold = evaluate_predictions(model.predict(data[train_end:test_end]), labels[train_end:test_end], cost_pct, minimum_edge_bps)
        fold["train_rows"] = train_end
        fold["test_rows"] = test_end - train_end
        folds.append(fold)

    trade_folds = [fold for fold in folds if fold["trade_count"] > 0]
    return {
        "method": "expanding_window_3_fold",
        "folds": folds,
        "fold_count": len(folds),
        "trade_fold_count": len(trade_folds),
        "mean_direction_accuracy_pct": (float(sum(fold["direction_accuracy_pct"] for fold in folds) / len(folds)) if folds else None),
        "mean_net_win_rate_pct": (float(sum(fold["net_win_rate_pct"] for fold in trade_folds) / len(trade_folds)) if trade_folds else None),
        "mean_average_net_return_pct": (float(sum(fold["average_net_return_pct"] for fold in trade_folds) / len(trade_folds)) if trade_folds else None),
    }


def train(
    input_path: Path, output_path: Path, horizon: int, timeframe: str,
    fee_bps_per_leg: float = 5.0, slippage_bps: float = 4.0, spread_bps: float = 2.0,
    funding_bps_per_interval: float = 0.0, minimum_edge_bps: float = 2.0,
) -> dict[str, Any]:
    rows = load_rows(input_path, timeframe)
    features, target = features_and_target(rows, horizon)
    total = len(features)
    train_end, validation_end = int(total * 0.70), int(total * 0.85)
    if train_end < 30 or validation_end <= train_end or validation_end >= total:
        raise ValueError("not enough data for chronological train/validation/holdout evaluation")
    try:
        import lightgbm as lgb
        import numpy as np
    except ImportError as exc:
        raise RuntimeError("install external-predictive-engine requirements before training") from exc
    data, labels = np.asarray(features, dtype=np.float64), np.asarray(target, dtype=np.float64)
    model = fit_model(lgb, data, labels, train_end, validation_end)
    predictions, holdout = model.predict(data[validation_end:]), labels[validation_end:]
    mae = float(np.mean(np.abs(predictions - holdout)))
    rmse = float(np.sqrt(np.mean((predictions - holdout) ** 2)))
    baseline_mae = float(np.mean(np.abs(holdout)))
    holding_seconds = INTERVAL_SECONDS[timeframe] * horizon
    cost_pct = estimated_round_trip_cost_pct(fee_bps_per_leg, slippage_bps, spread_bps, funding_bps_per_interval, holding_seconds)
    net_holdout = evaluate_predictions(predictions, holdout, cost_pct, minimum_edge_bps)
    walk_forward = walk_forward_metrics(lgb, data, labels, cost_pct, minimum_edge_bps)
    direction_accuracy = net_holdout["direction_accuracy_pct"]
    quality_pass = (
        len(holdout) >= 200
        and direction_accuracy >= 52.0
        and mae <= baseline_mae
        and net_holdout["trade_count"] >= 50
        and (net_holdout["average_net_return_pct"] or 0.0) > 0.0
        and walk_forward["fold_count"] >= 3
        and walk_forward["trade_fold_count"] >= 2
        and (walk_forward["mean_direction_accuracy_pct"] or 0.0) >= 52.0
        and (walk_forward["mean_average_net_return_pct"] or 0.0) > 0.0
    )
    output_path.parent.mkdir(parents=True, exist_ok=True)
    model.booster_.save_model(str(output_path))
    metadata = {
        "model": "lightgbm", "horizon_steps": horizon, "timeframe": timeframe, "feature_version": "ohlcv-v1",
        "features": ["return_1", "distance_from_low_20", "distance_from_high_20", "volume_ratio_20"],
        "rows": len(rows), "train_rows": train_end, "validation_rows": validation_end - train_end, "holdout_rows": len(holdout), "split": "chronological_70_15_15",
        "metrics": {"mae": mae, "rmse": rmse, "baseline_mae": baseline_mae, "direction_accuracy_pct": direction_accuracy, "net_holdout": net_holdout, "walk_forward": walk_forward},
        "cost_assumptions": {"fee_bps_per_leg": fee_bps_per_leg, "slippage_bps_round_trip": slippage_bps, "spread_bps_round_trip": spread_bps, "funding_bps_per_8h": funding_bps_per_interval, "holding_seconds": holding_seconds, "estimated_round_trip_cost_pct": cost_pct * 100, "minimum_edge_bps": minimum_edge_bps, "source": "configured_conservative_estimate"},
        "quality_gate": {"passed": quality_pass, "minimum_holdout_rows": 200, "minimum_direction_accuracy_pct": 52.0, "minimum_net_holdout_trades": 50, "minimum_walk_forward_folds": 3, "minimum_walk_forward_trade_folds": 2, "mae_must_not_exceed_zero_return_baseline": True, "net_edge_must_be_positive": True},
        "execution": False, "influence": False,
    }
    output_path.with_suffix(".json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    return metadata


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--timeframe", choices=sorted(INTERVAL_SECONDS), required=True)
    parser.add_argument("--horizon", type=int, default=12)
    parser.add_argument("--fee-bps-per-leg", type=float, default=5.0)
    parser.add_argument("--slippage-bps", type=float, default=4.0)
    parser.add_argument("--spread-bps", type=float, default=2.0)
    parser.add_argument("--funding-bps-per-interval", type=float, default=0.0)
    parser.add_argument("--minimum-edge-bps", type=float, default=2.0)
    args = parser.parse_args()
    print(json.dumps(train(args.input, args.output, args.horizon, args.timeframe, args.fee_bps_per_leg, args.slippage_bps, args.spread_bps, args.funding_bps_per_interval, args.minimum_edge_bps)))
