"""Train one SHADOW-ONLY LightGBM model for a single symbol x horizon.

Input is produced by Laravel (``ml:train``): a JSONL file of causal feature
vectors with their MATURE future-return labels, plus a manifest describing the
feature schema and the Quick Trade cost model in force. Features are computed
once, in PHP, by the same code that serves live predictions, so there is no
train/serve skew.

Validation is chronological only:
  * a purge gap of ``horizon_steps`` rows separates every train slice from the
    slice evaluated after it (labels of the last train rows would otherwise
    overlap the evaluation window),
  * three expanding walk-forward folds, all strictly before the final holdout,
  * a final untouched holdout (last 15%) on which the candidate and - when one
    exists - the current champion are both scored, so promotion compares
    like with like.

This script never publishes or promotes anything. It writes artifacts and
metrics; Laravel's promotion policy decides.
"""

from __future__ import annotations

import argparse
import json
import math
import os
from pathlib import Path
from typing import Any

import shadow_sim

HOLDOUT_FRACTION = 0.15
EARLY_STOP_FRACTION = 0.15
WALK_FORWARD_STARTS = (0.55, 0.65, 0.75)
WALK_FORWARD_TEST_FRACTION = 0.10
MIN_ROWS = 500
QUANTILES = {"q10": 0.10, "q90": 0.90}


def load_dataset(input_path: Path, manifest: dict[str, Any], funding: list[float] | None = None) -> tuple[list[int], list[list[float]], list[float]]:
    features = manifest["features"]
    times: list[int] = []
    rows: list[list[float]] = []
    targets: list[float] = []
    with input_path.open(encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            record = json.loads(line)
            x = [float(v) for v in record["x"]]
            y = float(record["y"])
            if len(x) != len(features):
                raise ValueError("feature vector length does not match manifest schema")
            if not all(math.isfinite(v) for v in x) or not math.isfinite(y):
                raise ValueError("non-finite feature or label")
            times.append(int(record["t"]))
            rows.append(x)
            targets.append(y * 100.0)  # train in percent for numerical stability
            if funding is not None:
                funding.append(float(record.get("fb", 0.0)))  # BUY-side funding, percent
    if any(b <= a for a, b in zip(times, times[1:])):
        raise ValueError("anchor times must be strictly increasing (chronological, no duplicates)")
    if len(rows) < MIN_ROWS:
        raise ValueError(f"not enough mature rows: {len(rows)} < {MIN_ROWS}")
    return times, rows, targets


def make_regressor(lgb: Any, alpha: float | None = None) -> Any:
    params: dict[str, Any] = dict(
        n_estimators=600, learning_rate=0.03, num_leaves=31, min_child_samples=200,
        subsample=0.8, subsample_freq=1, colsample_bytree=0.8, reg_lambda=1.0,
        random_state=42, deterministic=True, force_row_wise=True, n_jobs=1, verbose=-1,
    )
    if alpha is not None:
        params.update(objective="quantile", alpha=alpha)
    return lgb.LGBMRegressor(**params)


def fit(lgb: Any, x: Any, y: Any, end: int, purge: int, alpha: float | None = None) -> Any:
    """Fit on rows [0, end) with a purged chronological early-stopping tail."""
    stop_start = int(end * (1 - EARLY_STOP_FRACTION))
    fit_end = stop_start - purge
    if fit_end < 100 or stop_start >= end:
        raise ValueError("not enough rows for a purged early-stopping split")
    model = make_regressor(lgb, alpha)
    model.fit(
        x[:fit_end], y[:fit_end],
        eval_set=[(x[stop_start:end], y[stop_start:end])],
        callbacks=[lgb.early_stopping(40, verbose=False)],
    )
    return model


def evaluate(np: Any, predicted: Any, actual: Any, cost_pct: float, required_move_pct: float) -> dict[str, Any]:
    """Forecast error and a fee-aware decision metric, reported separately.

    A BUY/SELL is only "taken" when |predicted move| clears Quick Trade's
    required_move_pct gate; its outcome is charged cost_pct exactly once.
    """
    errors = predicted - actual
    nonzero = actual != 0
    direction_hits = (np.sign(predicted[nonzero]) == np.sign(actual[nonzero]))
    selected = np.abs(predicted) >= required_move_pct
    trades = int(np.sum(selected))
    net = np.sign(predicted[selected]) * actual[selected] - cost_pct
    return {
        "rows": int(len(actual)),
        "mae_pct": float(np.mean(np.abs(errors))),
        "rmse_pct": float(np.sqrt(np.mean(errors ** 2))),
        "baseline_zero_mae_pct": float(np.mean(np.abs(actual))),
        "direction_hit_pct": float(np.mean(direction_hits) * 100) if direction_hits.size else None,
        "fee_aware": {
            "trade_count": trades,
            "trade_rate_pct": float(trades / len(actual) * 100),
            "net_win_rate_pct": float(np.mean(net > 0) * 100) if trades else None,
            "average_net_pct": float(np.mean(net)) if trades else None,
            "total_net_pct": float(np.sum(net)) if trades else 0.0,
        },
    }


def load_champion(lgb: Any, champion_dir: Path | None, manifest: dict[str, Any]) -> tuple[Any | None, list[int] | None, str | None]:
    """Load the champion and the column indices of ITS features in this dataset.

    Feature schemas are append-only (v2 = v1 + extra columns), so an older
    champion can be scored on the very same holdout rows by selecting its own
    columns by name. A champion needing a feature this dataset lacks is not
    comparable.
    """
    if champion_dir is None:
        return None, None, "no_champion"
    metadata_path = champion_dir / "metadata.json"
    model_path = champion_dir / "model.txt"
    if not metadata_path.is_file() or not model_path.is_file():
        return None, None, "champion_artifacts_missing"
    metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
    champion_features = metadata.get("features") or []
    if not champion_features or any(name not in manifest["features"] for name in champion_features):
        return None, None, "champion_feature_schema_mismatch"
    columns = [manifest["features"].index(name) for name in champion_features]
    return lgb.Booster(model_file=str(model_path)), columns, None


def write_atomic(path: Path, text: str) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(text, encoding="utf-8")
    os.replace(temporary, path)


def train(input_path: Path, manifest_path: Path, output_dir: Path, champion_dir: Path | None = None) -> dict[str, Any]:
    try:
        import lightgbm as lgb
        import numpy as np
    except ImportError as exc:  # pragma: no cover - deployment error
        raise RuntimeError("install external-predictive-engine requirements before training") from exc

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    funding_list: list[float] = []
    times, rows, targets = load_dataset(input_path, manifest, funding_list)
    funding = np.asarray(funding_list, dtype=np.float64)
    x = np.asarray(rows, dtype=np.float64)
    y = np.asarray(targets, dtype=np.float64)
    n = len(y)
    purge = max(1, int(manifest["horizon_steps"]))
    cost_pct = float(manifest["cost_pct"])
    required_move_pct = float(manifest["required_move_pct"])

    holdout_start = int(n * (1 - HOLDOUT_FRACTION))
    train_end = holdout_start - purge

    folds: list[dict[str, Any]] = []
    for start_fraction in WALK_FORWARD_STARTS:
        test_start = int(n * start_fraction)
        test_end = min(train_end, test_start + int(n * WALK_FORWARD_TEST_FRACTION))
        fold_train_end = test_start - purge
        if test_end - test_start < 50 or fold_train_end < 200:
            continue
        model = fit(lgb, x, y, fold_train_end, purge)
        fold = evaluate(np, model.predict(x[test_start:test_end]), y[test_start:test_end], cost_pct, required_move_pct)
        fold.update(train_rows=fold_train_end, test_from_ms=times[test_start], test_to_ms=times[test_end - 1])
        folds.append(fold)

    model = fit(lgb, x, y, train_end, purge)
    quantile_models = {name: fit(lgb, x, y, train_end, purge, alpha) for name, alpha in QUANTILES.items()}

    x_holdout, y_holdout = x[holdout_start:], y[holdout_start:]
    holdout = evaluate(np, model.predict(x_holdout), y_holdout, cost_pct, required_move_pct)
    q10 = quantile_models["q10"].predict(x_holdout)
    q90 = quantile_models["q90"].predict(x_holdout)
    holdout["interval_coverage_pct"] = float(np.mean((y_holdout >= np.minimum(q10, q90)) & (y_holdout <= np.maximum(q10, q90))) * 100)
    holdout["interval_target_coverage_pct"] = 80.0
    # Reported only (the regression gate is unchanged): the same NON-overlapping
    # fee+funding simulation the classifier gate uses, next to its baselines.
    horizon_ms = int(manifest["horizon_minutes"]) * 60_000
    predicted_holdout = model.predict(x_holdout)
    funding_holdout = funding[holdout_start:]
    signals = np.where(predicted_holdout - funding_holdout >= required_move_pct, 1,
                       np.where(-predicted_holdout + funding_holdout >= required_move_pct, -1, 0))
    times_holdout = times[holdout_start:]
    holdout["non_overlapping"] = shadow_sim.strip(shadow_sim.simulate(times_holdout, signals, y_holdout, funding_holdout, cost_pct, horizon_ms))
    holdout["baselines"] = shadow_sim.baselines(times_holdout, y_holdout, funding_holdout, cost_pct, horizon_ms)
    holdout["effective_samples"] = shadow_sim.effective_samples(times_holdout, int(manifest.get("step_ms", 300_000)), horizon_ms)

    champion, champion_columns, champion_reason = load_champion(lgb, champion_dir, manifest)
    champion_holdout = (
        evaluate(np, champion.predict(x_holdout[:, champion_columns]), y_holdout, cost_pct, required_move_pct)
        if champion is not None else None
    )

    output_dir.mkdir(parents=True, exist_ok=True)
    model.booster_.save_model(str(output_dir / "model.txt"))
    for name, quantile_model in quantile_models.items():
        quantile_model.booster_.save_model(str(output_dir / f"{name}.txt"))

    metadata = {
        "model": "lightgbm",
        "model_type": "regression",
        "objective": "regression_l2_future_return",
        "target_unit": "return_pct",
        "symbol": manifest["symbol"],
        "horizon_minutes": manifest["horizon_minutes"],
        "horizon_steps": purge,
        "timeframe": manifest["timeframe"],
        "feature_schema_version": manifest["feature_schema_version"],
        "features": manifest["features"],
        "rows": n,
        "train_rows": train_end,
        "purge_rows": purge,
        "holdout_rows": n - holdout_start,
        "training_window_from_ms": times[0],
        "training_window_to_ms": times[train_end - 1],
        "holdout_from_ms": times[holdout_start],
        "holdout_to_ms": times[-1],
        "split": "chronological_purged_walk_forward",
        "best_iteration": int(model.best_iteration_ or model.n_estimators),
        "metrics": {
            "holdout": holdout,
            "walk_forward": {"method": "expanding_window_purged", "fold_count": len(folds), "folds": folds},
            "champion_holdout": champion_holdout,
            "champion_comparable": champion_holdout is not None,
            "champion_not_comparable_reason": champion_reason,
        },
        "cost_assumptions": {"cost_pct": cost_pct, "required_move_pct": required_move_pct, "source": manifest.get("cost_source", "QuickTradeCostModel")},
        "execution": False,
        "influence": False,
    }
    write_atomic(output_dir / "metadata.json", json.dumps(metadata, indent=2))
    return metadata


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--champion-dir", type=Path, default=None)
    args = parser.parse_args()
    print(json.dumps(train(args.input, args.manifest, args.output_dir, args.champion_dir)))
