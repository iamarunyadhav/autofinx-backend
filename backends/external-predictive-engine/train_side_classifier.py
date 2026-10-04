"""Train SHADOW-ONLY side classifiers (BUY / SELL) for one symbol x horizon.

Instead of predicting the average return (which, without an edge, collapses
to ~0 and therefore always says WAIT), each side asks the decision question
directly:

  label_buy  = return_pct >  required_move_pct + funding_cost_buy
  label_sell = return_pct < -(required_move_pct + funding_cost_sell)
  (funding_cost_sell = -funding_cost_buy: shorts receive what longs pay)

Probabilities are calibrated (isotonic) on a chronological slice the trees
never saw. A side is taken only when P >= floor AND its expected net after
cost + funding is > 0:

  expected_net = P * avg_win + (1 - P) * avg_other - cost - funding_side

Evaluation is a NON-overlapping trade simulation (one position at a time,
exit at T+h) on purged walk-forward folds and a final holdout, next to
never-trade / always-BUY / always-SELL baselines. This script only reports;
Laravel's ClassifierPromotionPolicy decides.
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
CALIBRATION_FRACTION = 0.20
WALK_FORWARD_STARTS = (0.55, 0.65, 0.75)
WALK_FORWARD_TEST_FRACTION = 0.10
MIN_ROWS = 500


def load(input_path: Path, manifest: dict[str, Any]):
    import numpy as np

    width = len(manifest["features"])
    # Preallocated arrays (grown if the manifest under-counts): years of 5m
    # rows as python lists of lists would need several times the memory.
    capacity = max(MIN_ROWS, int(manifest.get("rows") or 0))
    times = np.empty(capacity, dtype=np.int64)
    x = np.empty((capacity, width), dtype=np.float64)
    returns = np.empty(capacity, dtype=np.float64)
    funding = np.empty(capacity, dtype=np.float64)
    n = 0
    with input_path.open(encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            record = json.loads(line)
            row = [float(v) for v in record["x"]]
            if len(row) != width or not all(math.isfinite(v) for v in row):
                raise ValueError("feature vector does not match the manifest schema")
            if n == capacity:
                capacity *= 2
                times, returns, funding = (np.resize(a, capacity) for a in (times, returns, funding))
                x = np.resize(x, (capacity, width))
            times[n] = int(record["t"])
            x[n] = row
            returns[n] = float(record["y"]) * 100.0          # percent
            funding[n] = float(record.get("fb", 0.0))         # percent, BUY side
            n += 1
    times, x, returns, funding = times[:n], x[:n], returns[:n], funding[:n]
    if n > 1 and bool(np.any(np.diff(times) <= 0)):
        raise ValueError("anchor times must be strictly increasing (chronological, no duplicates)")
    if n < MIN_ROWS:
        raise ValueError(f"not enough mature rows: {n} < {MIN_ROWS}")
    return times, x, returns, funding


def side_labels(np, returns, funding, required):
    buy = returns > required + funding
    sell = -returns > required - funding
    return buy.astype(int), sell.astype(int)


class SideModel:
    """Booster + isotonic map + expected-value parameters for one side."""

    def __init__(self, booster: Any, iso_x: list[float], iso_y: list[float], avg_win: float, avg_other: float, base_rate: float):
        self.booster, self.iso_x, self.iso_y = booster, iso_x, iso_y
        self.avg_win, self.avg_other, self.base_rate = avg_win, avg_other, base_rate

    def probability(self, np, x):
        raw = self.booster.predict(x)
        if not self.iso_x:
            return np.full(len(raw), self.base_rate)
        return np.interp(raw, self.iso_x, self.iso_y, left=self.iso_y[0], right=self.iso_y[-1])

    def params(self) -> dict[str, Any]:
        return {"avg_win_pct": self.avg_win, "avg_other_pct": self.avg_other, "base_rate": self.base_rate,
                "isotonic_x": self.iso_x, "isotonic_y": self.iso_y}


def window_start(np, times, end: int, window_ms: int) -> int:
    """First row inside the training window ending at row `end` (0 = all history)."""
    if window_ms <= 0 or end <= 0:
        return 0
    return int(np.searchsorted(times, int(times[end - 1]) - window_ms, side="left"))


def recency_weights(np, times, half_life_ms: float):
    """0.5 ** (age / half-life), age measured from the newest row of the slice."""
    if half_life_ms <= 0 or len(times) == 0:
        return None
    return np.power(0.5, (float(times[-1]) - times.astype(np.float64)) / half_life_ms)


def fit_side(lgb, np, x, label, signed_returns, end: int, purge: int, times=None, window_ms: int = 0, half_life_ms: float = 0.0) -> SideModel:
    """Trees on [start, cal_start - purge) with recency weights, isotonic + EV
    params (unweighted, the most recent slice) on [cal_start, end)."""
    from sklearn.isotonic import IsotonicRegression

    start = window_start(np, times, end, window_ms) if times is not None else 0
    cal_start = start + int((end - start) * (1 - CALIBRATION_FRACTION))
    fit_end = cal_start - purge
    if fit_end - start < 200 or end - cal_start < 100:
        raise ValueError("not enough rows for a purged fit + calibration split")
    model = lgb.LGBMClassifier(
        n_estimators=300, learning_rate=0.03, num_leaves=31, min_child_samples=200,
        subsample=0.8, subsample_freq=1, colsample_bytree=0.8, reg_lambda=1.0,
        random_state=42, deterministic=True, force_row_wise=True, n_jobs=1, verbose=-1,
    )
    if label[start:fit_end].min() == label[start:fit_end].max():
        raise ValueError("training slice has a single class for this side")
    weights = recency_weights(np, times[start:fit_end], half_life_ms) if times is not None else None
    model.fit(x[start:fit_end], label[start:fit_end], sample_weight=weights)
    raw = model.booster_.predict(x[cal_start:end])
    iso = IsotonicRegression(y_min=0.0, y_max=1.0, out_of_bounds="clip")
    iso.fit(raw, label[cal_start:end])
    cal_label = label[cal_start:end].astype(bool)
    cal_returns = signed_returns[cal_start:end]
    avg_win = float(cal_returns[cal_label].mean()) if cal_label.any() else 0.0
    avg_other = float(cal_returns[~cal_label].mean()) if (~cal_label).any() else 0.0
    return SideModel(model.booster_, [float(v) for v in iso.X_thresholds_], [float(v) for v in iso.y_thresholds_],
                     avg_win, avg_other, float(cal_label.mean()))


def decide(np, p_buy, p_sell, buy: SideModel, sell: SideModel, funding, cost, floor):
    """+1 / -1 / 0 per row, plus each side's expected net (percent)."""
    net_buy = p_buy * buy.avg_win + (1 - p_buy) * buy.avg_other - cost - funding
    net_sell = p_sell * sell.avg_win + (1 - p_sell) * sell.avg_other - cost + funding
    take_buy = (p_buy >= floor) & (net_buy > 0)
    take_sell = (p_sell >= floor) & (net_sell > 0)
    signal = np.zeros(len(p_buy), dtype=int)
    signal[take_buy & ~take_sell] = 1
    signal[take_sell & ~take_buy] = -1
    both = take_buy & take_sell
    signal[both & (net_buy >= net_sell)] = 1
    signal[both & (net_buy < net_sell)] = -1
    return signal, net_buy, net_sell


def evaluate_slice(np, times, signals, returns, funding, cost, horizon_ms, step_ms) -> dict[str, Any]:
    sim = shadow_sim.simulate(times, signals, returns, funding, cost, horizon_ms)
    return {
        "non_overlapping": shadow_sim.strip(sim),
        "baselines": shadow_sim.baselines(times, returns, funding, cost, horizon_ms),
        "effective_samples": shadow_sim.effective_samples(times, step_ms, horizon_ms),
        "from_ms": int(times[0]), "to_ms": int(times[-1]), "rows": int(len(times)),
        "_trades": sim["trade_list"],
    }


def calibration_report(np, p, label) -> dict[str, Any]:
    if len(p) == 0:
        return {}
    brier = float(np.mean((p - label) ** 2))
    base = float(np.mean(label))
    report = {"brier": brier, "brier_climatology": float(np.mean((base - label) ** 2)), "observed_rate": base,
              "mean_predicted": float(np.mean(p))}
    high = p >= 0.5
    report["high_confidence_rows"] = int(high.sum())
    report["high_confidence_observed_rate"] = float(label[high].mean()) if high.any() else None
    report["high_confidence_mean_predicted"] = float(p[high].mean()) if high.any() else None
    return report


def champion_signals(lgb, np, champion_dir: Path | None, manifest, x, funding, cost, required):
    """Re-score the current champion (regression or classifier) on the same rows."""
    if champion_dir is None:
        return None, "no_champion"
    metadata_path = champion_dir / "metadata.json"
    if not metadata_path.is_file():
        return None, "champion_artifacts_missing"
    meta = json.loads(metadata_path.read_text(encoding="utf-8"))
    names = meta.get("features") or []
    if not names or any(name not in manifest["features"] for name in names):
        return None, "champion_feature_schema_mismatch"
    cols = [manifest["features"].index(name) for name in names]
    xc = x[:, cols]
    if meta.get("model_type") == "side_classifier":
        sides = {}
        for side in ("buy", "sell"):
            params = meta["sides"][side]
            sides[side] = SideModel(lgb.Booster(model_file=str(champion_dir / f"{side}.txt")), params["isotonic_x"], params["isotonic_y"],
                                    params["avg_win_pct"], params["avg_other_pct"], params["base_rate"])
        signal, _, _ = decide(np, sides["buy"].probability(np, xc), sides["sell"].probability(np, xc), sides["buy"], sides["sell"],
                              funding, cost, float(meta.get("probability_floor", 0.55)))
        return signal, None
    model_path = champion_dir / "model.txt"
    if not model_path.is_file():
        return None, "champion_artifacts_missing"
    predicted = lgb.Booster(model_file=str(model_path)).predict(xc)   # regression: percent
    signal = np.where(predicted - funding >= required, 1, np.where(-predicted + funding >= required, -1, 0))
    return signal, None


def write_atomic(path: Path, text: str) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(text, encoding="utf-8")
    os.replace(temporary, path)


def train(input_path: Path, manifest_path: Path, output_dir: Path, champion_dir: Path | None = None) -> dict[str, Any]:
    import lightgbm as lgb
    import numpy as np

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    times, x, returns, funding = load(input_path, manifest)
    n = len(returns)
    purge = max(1, int(manifest["horizon_steps"]))           # embargo >= horizon
    horizon_ms = int(manifest["horizon_minutes"]) * 60_000
    step_ms = int(manifest.get("step_ms", 300_000))
    cost = float(manifest["cost_pct"])
    required = float(manifest["required_move_pct"])
    floor = float(manifest.get("probability_floor", 0.55))
    label_buy, label_sell = side_labels(np, returns, funding, required)
    window_months = float(manifest.get("window_months") or 0)
    window_ms = int(window_months * 30.4375 * 86_400_000)
    half_life_days = float(manifest.get("recency_half_life_days") or 0.0)
    half_life_ms = half_life_days * 86_400_000

    def fit_pair(end: int):
        return (fit_side(lgb, np, x, label_buy, returns, end, purge, times, window_ms, half_life_ms),
                fit_side(lgb, np, x, label_sell, -returns, end, purge, times, window_ms, half_life_ms))

    holdout_start = int(n * (1 - HOLDOUT_FRACTION))
    train_end = holdout_start - purge

    folds = []
    for start_fraction in WALK_FORWARD_STARTS:
        test_start = int(n * start_fraction)
        test_end = min(train_end, test_start + int(n * WALK_FORWARD_TEST_FRACTION))
        fold_end = test_start - purge
        if test_end - test_start < 50 or fold_end < 400:
            continue
        buy, sell = fit_pair(fold_end)
        sl = slice(test_start, test_end)
        signal, _, _ = decide(np, buy.probability(np, x[sl]), sell.probability(np, x[sl]), buy, sell, funding[sl], cost, floor)
        fold = evaluate_slice(np, times[sl], signal, returns[sl], funding[sl], cost, horizon_ms, step_ms)
        fold.pop("_trades")
        fold["train_rows"] = fold_end
        folds.append(fold)

    buy, sell = fit_pair(train_end)
    hs = slice(holdout_start, n)
    p_buy, p_sell = buy.probability(np, x[hs]), sell.probability(np, x[hs])
    signal, net_buy, net_sell = decide(np, p_buy, p_sell, buy, sell, funding[hs], cost, floor)
    holdout = evaluate_slice(np, times[hs], signal, returns[hs], funding[hs], cost, horizon_ms, step_ms)
    holdout.pop("_trades")
    # Reported sanity metric only (not the gate): MAE of the implied expected return.
    implied = p_buy * buy.avg_win + (1 - p_buy) * buy.avg_other
    holdout["implied_return_mae_pct"] = float(np.mean(np.abs(implied - returns[hs])))
    holdout["baseline_zero_mae_pct"] = float(np.mean(np.abs(returns[hs])))
    holdout["calibration"] = {"buy": calibration_report(np, p_buy, label_buy[hs]), "sell": calibration_report(np, p_sell, label_sell[hs])}
    holdout["signal_rows"] = {"buy": int((signal > 0).sum()), "sell": int((signal < 0).sum())}

    champion_signal, champion_reason = champion_signals(lgb, np, champion_dir, manifest, x[hs], funding[hs], cost, required)
    champion_holdout = None
    if champion_signal is not None:
        champion_holdout = shadow_sim.strip(shadow_sim.simulate(times[hs], champion_signal, returns[hs], funding[hs], cost, horizon_ms))

    output_dir.mkdir(parents=True, exist_ok=True)
    buy.booster.save_model(str(output_dir / "buy.txt"))
    sell.booster.save_model(str(output_dir / "sell.txt"))
    metadata = {
        "model": "lightgbm", "model_type": "side_classifier", "objective": "binary_side_vs_required_move",
        "symbol": manifest["symbol"], "horizon_minutes": manifest["horizon_minutes"], "horizon_steps": purge,
        "timeframe": manifest["timeframe"], "feature_schema_version": manifest["feature_schema_version"],
        "features": manifest["features"], "rows": n, "train_rows": train_end, "purge_rows": purge,
        "holdout_rows": n - holdout_start,
        "training_window_months": window_months, "recency_half_life_days": half_life_days,
        "training_window_from_ms": int(times[window_start(np, times, train_end, window_ms)]), "training_window_to_ms": int(times[train_end - 1]),
        "holdout_from_ms": int(times[holdout_start]), "holdout_to_ms": int(times[-1]),
        "split": "chronological_purged_walk_forward_calibrated",
        "probability_floor": floor,
        "sides": {"buy": buy.params(), "sell": sell.params()},
        "metrics": {
            "holdout": holdout,
            "walk_forward": {"method": "expanding_window_purged_embargoed", "fold_count": len(folds), "folds": folds},
            "champion_holdout": champion_holdout,
            "champion_comparable": champion_holdout is not None,
            "champion_not_comparable_reason": champion_reason,
        },
        "cost_assumptions": {"cost_pct": cost, "required_move_pct": required, "funding": "actual historical, per side",
                             "source": manifest.get("cost_source", "QuickTradeCostModel")},
        "execution": False, "influence": False,
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
    result = train(args.input, args.manifest, args.output_dir, args.champion_dir)
    print(json.dumps({"model_type": result["model_type"], "holdout": result["metrics"]["holdout"]["non_overlapping"]}))
