import hashlib
import json
import random
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).parents[1]))

import shadow_sim  # noqa: E402

lightgbm = pytest.importorskip("lightgbm")
pytest.importorskip("sklearn")

import train_side_classifier as classifier  # noqa: E402
from external_predictive_engine.shadow_models import ShadowModelStore, ShadowRequest  # noqa: E402

STEP = 300_000
HOUR = 3_600_000


def test_non_overlapping_simulation_never_holds_two_positions():
    times = [i * STEP for i in range(100)]
    signals = [1] * 100                     # a signal on every 5m anchor
    returns = [0.5] * 100
    sim = shadow_sim.simulate(times, signals, returns, [0.0] * 100, 0.1, HOUR)
    assert sim["raw_signals"] == 100
    assert sim["trades"] == 9               # 100 anchors * 5m / 60m holds
    entries = [trade["t"] for trade in sim["trade_list"]]
    assert all(b - a >= HOUR for a, b in zip(entries, entries[1:]))
    assert sim["total_net_pct"] == pytest.approx(9 * (0.5 - 0.1))


def test_t_stat_separates_a_consistent_edge_from_noise():
    times = [i * HOUR for i in range(40)]
    consistent = shadow_sim.simulate(times, [1] * 40, [0.40 + 0.05 * (i % 3) for i in range(40)], [0.0] * 40, 0.1, HOUR)
    noisy = shadow_sim.simulate(times, [1] * 40, [3.0 if i % 2 else -2.6 for i in range(40)], [0.0] * 40, 0.1, HOUR)
    assert consistent["t_stat"] > 10
    assert noisy["average_net_pct"] > 0 and noisy["t_stat"] < 1.65   # positive on average, but luck can't be ruled out
    single = shadow_sim.simulate([0], [1], [1.0], [0.0], 0.1, HOUR)
    assert single["t_stat"] is None and single["std_net_pct"] is None


def test_funding_sign_per_side_in_simulation():
    times, returns, funding = [0], [1.0], [0.02]
    buy = shadow_sim.simulate(times, [1], returns, funding, 0.1, HOUR)
    sell = shadow_sim.simulate(times, [-1], returns, funding, 0.1, HOUR)
    assert buy["total_net_pct"] == pytest.approx(1.0 - 0.1 - 0.02)   # long pays
    assert sell["total_net_pct"] == pytest.approx(-1.0 - 0.1 + 0.02)  # short receives


def test_baselines_and_effective_samples():
    times = [i * STEP for i in range(288)]
    base = shadow_sim.baselines(times, [0.3] * 288, [0.0] * 288, 0.1, 24 * HOUR)
    assert base["never_trade"]["trades"] == 0
    assert base["always_buy"]["trades"] == 1 and base["always_sell"]["trades"] == 1
    assert shadow_sim.effective_samples(times, STEP, 24 * HOUR) == pytest.approx(1.0)


def write_dataset(tmp_path: Path, rows: int = 4000, drift: float = 0.0, signal: float = 0.0) -> tuple[Path, Path]:
    rng = random.Random(3)
    data = tmp_path / "data.jsonl"
    with data.open("w", encoding="utf-8") as handle:
        for i in range(rows):
            x = [rng.gauss(0, 1) for _ in range(4)]
            ret = (drift + signal * x[0] + rng.gauss(0, 0.4)) / 100   # fraction
            handle.write(json.dumps({"t": i * STEP, "x": x, "y": ret, "fb": 0.0}) + "\n")
    manifest = tmp_path / "manifest.json"
    manifest.write_text(json.dumps({
        "symbol": "BTCUSDT", "horizon_minutes": 60, "timeframe": "5m", "horizon_steps": 12, "step_ms": STEP,
        "feature_schema_version": "test-v3", "features": ["a", "b", "c", "d"], "rows": rows,
        "cost_pct": 0.12, "required_move_pct": 0.18, "probability_floor": 0.55,
    }), encoding="utf-8")
    return data, manifest


def test_classifier_learns_a_real_signal_and_reports_the_gate_inputs(tmp_path):
    data, manifest = write_dataset(tmp_path, signal=0.6)
    meta = classifier.train(data, manifest, tmp_path / "out")
    holdout = meta["metrics"]["holdout"]
    assert meta["model_type"] == "side_classifier"
    assert meta["metrics"]["walk_forward"]["fold_count"] == 3
    for fold in meta["metrics"]["walk_forward"]["folds"]:
        assert fold["to_ms"] < meta["holdout_from_ms"]                      # folds strictly before holdout
    assert meta["holdout_from_ms"] - meta["training_window_to_ms"] == (12 + 1) * STEP  # purge/embargo = horizon
    assert holdout["non_overlapping"]["trades"] < holdout["non_overlapping"]["raw_signals"]
    assert holdout["non_overlapping"]["total_net_pct"] > 0                  # a learnable edge is found
    assert set(holdout["baselines"]) == {"never_trade", "always_buy", "always_sell"}
    assert "implied_return_mae_pct" in holdout                             # reported, not gated
    assert holdout["calibration"]["buy"]["brier"] <= holdout["calibration"]["buy"]["brier_climatology"] + 1e-9
    for name in ("buy.txt", "sell.txt", "metadata.json"):
        assert (tmp_path / "out" / name).is_file()


def test_trend_only_data_gives_the_always_buy_baseline_the_same_edge(tmp_path):
    # Pure drift, no feature signal: anything the model "earns" always-BUY earns too.
    data, manifest = write_dataset(tmp_path, drift=0.35, signal=0.0)
    meta = classifier.train(data, manifest, tmp_path / "out")
    holdout = meta["metrics"]["holdout"]
    assert holdout["baselines"]["always_buy"]["total_net_pct"] >= holdout["non_overlapping"]["total_net_pct"] - 1e-9


def test_serving_probabilities_match_training_and_schema_is_enforced(tmp_path):
    import numpy as np

    data, manifest = write_dataset(tmp_path, signal=0.6)
    meta = classifier.train(data, manifest, tmp_path / "out")
    shadow = tmp_path / "champions"
    shadow.mkdir()
    files = {}
    for side in ("buy", "sell"):
        content = (tmp_path / "out" / f"{side}.txt").read_bytes()
        (shadow / f"BTCUSDT-h60.v.{side}.txt").write_bytes(content)
        files[side] = {"file": f"BTCUSDT-h60.v.{side}.txt", "sha256": hashlib.sha256(content).hexdigest()}
    (shadow / "BTCUSDT-h60.json").write_text(json.dumps({
        "model_version": "v", "model_type": "side_classifier", "feature_schema_version": "test-v3",
        "features": ["a", "b", "c", "d"], "files": files, "sides": meta["sides"], "probability_floor": 0.55,
    }), encoding="utf-8")
    store = ShadowModelStore(str(shadow))
    vector = {"a": 1.2, "b": -0.3, "c": 0.1, "d": 0.0}

    served = store.predict(ShadowRequest("BTCUSDT", 60, "test-v3", vector, "v"))
    assert served["status"] == "ready" and served["model_type"] == "side_classifier"
    p = meta["sides"]["buy"]
    booster = lightgbm.Booster(model_file=str(tmp_path / "out" / "buy.txt"))
    expected = float(np.interp(booster.predict([[1.2, -0.3, 0.1, 0.0]])[0], p["isotonic_x"], p["isotonic_y"]))
    assert served["p_buy"] == pytest.approx(expected, abs=1e-9)
    assert 0.0 <= served["p_sell"] <= 1.0
    assert served["execution"] is False
    assert store.predict(ShadowRequest("BTCUSDT", 60, "test-v2", vector))["reason"] == "feature_schema_version_mismatch"
    assert store.predict(ShadowRequest("BTCUSDT", 120, "test-v3", vector))["status"] == "unavailable"  # no horizon borrowing
