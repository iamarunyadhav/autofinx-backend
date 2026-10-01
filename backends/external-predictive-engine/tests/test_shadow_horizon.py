import hashlib
import json
import math
import random
import sys
import threading
from http.server import ThreadingHTTPServer
from pathlib import Path
from urllib.request import Request, urlopen

import pytest

sys.path.insert(0, str(Path(__file__).parents[1]))

lightgbm = pytest.importorskip("lightgbm")

from external_predictive_engine.config import Settings  # noqa: E402
from external_predictive_engine.http_service import make_handler  # noqa: E402
from external_predictive_engine.providers import LightGBMProvider  # noqa: E402
from external_predictive_engine.shadow_models import ShadowModelStore, ShadowRequest  # noqa: E402
import train_shadow_horizon as trainer  # noqa: E402

FEATURES = ["f1", "f2", "f3"]


def write_dataset(tmp_path: Path, rows: int = 3000, signal: float = 0.8) -> tuple[Path, Path]:
    rng = random.Random(7)
    data = tmp_path / "data.jsonl"
    with data.open("w", encoding="utf-8") as handle:
        for i in range(rows):
            x = [rng.gauss(0, 1) for _ in FEATURES]
            y = (signal * x[0] + rng.gauss(0, 1)) / 1000  # fractional return
            handle.write(json.dumps({"t": 1_700_000_000_000 + i * 300_000, "x": x, "y": y}) + "\n")
    manifest = tmp_path / "manifest.json"
    manifest.write_text(json.dumps({
        "symbol": "BTCUSDT", "horizon_minutes": 60, "timeframe": "5m", "horizon_steps": 12,
        "feature_schema_version": "test-v1", "features": FEATURES, "rows": rows,
        "cost_pct": 0.14, "required_move_pct": 0.28,
    }), encoding="utf-8")
    return data, manifest


def test_training_is_chronological_purged_and_reports_separate_metrics(tmp_path):
    data, manifest = write_dataset(tmp_path)
    meta = trainer.train(data, manifest, tmp_path / "out")
    assert meta["split"] == "chronological_purged_walk_forward"
    assert meta["purge_rows"] == 12
    # The holdout begins strictly after the training window plus the purge gap.
    assert meta["holdout_from_ms"] - meta["training_window_to_ms"] == (12 + 1) * 300_000
    assert meta["metrics"]["walk_forward"]["fold_count"] == 3
    for fold in meta["metrics"]["walk_forward"]["folds"]:
        assert fold["test_to_ms"] < meta["holdout_from_ms"]
    holdout = meta["metrics"]["holdout"]
    assert holdout["mae_pct"] < holdout["baseline_zero_mae_pct"]  # real signal is learnable
    assert "fee_aware" in holdout and "interval_coverage_pct" in holdout
    assert meta["metrics"]["champion_comparable"] is False
    for name in ("model.txt", "q10.txt", "q90.txt", "metadata.json"):
        assert (tmp_path / "out" / name).is_file()


def test_unsorted_or_duplicate_anchors_are_refused(tmp_path):
    data, manifest = write_dataset(tmp_path, rows=600)
    lines = data.read_text(encoding="utf-8").splitlines()
    lines[10], lines[11] = lines[11], lines[10]
    data.write_text("\n".join(lines), encoding="utf-8")
    with pytest.raises(ValueError, match="chronological"):
        trainer.train(data, manifest, tmp_path / "out")


def test_champion_is_scored_on_the_same_holdout(tmp_path):
    data, manifest = write_dataset(tmp_path)
    trainer.train(data, manifest, tmp_path / "champion")
    meta = trainer.train(data, manifest, tmp_path / "candidate", champion_dir=tmp_path / "champion")
    assert meta["metrics"]["champion_comparable"] is True
    assert meta["metrics"]["champion_holdout"]["rows"] == meta["metrics"]["holdout"]["rows"]


def publish(tmp_path: Path, model_dir: Path, version: str = "qtml-test") -> Path:
    shadow = tmp_path / "champions"
    shadow.mkdir()
    files = {}
    for role in ("model", "q10", "q90"):
        content = (model_dir / f"{role}.txt").read_bytes()
        name = f"BTCUSDT-h60.{version}.{role}.txt"
        (shadow / name).write_bytes(content)
        files[role] = {"file": name, "sha256": hashlib.sha256(content).hexdigest()}
    (shadow / "BTCUSDT-h60.json").write_text(json.dumps({
        "model_version": version, "feature_schema_version": "test-v1", "features": FEATURES,
        "target_unit": "return_pct", "files": files,
    }), encoding="utf-8")
    return shadow


def test_shadow_store_predicts_and_rejects_schema_or_integrity_problems(tmp_path):
    data, manifest = write_dataset(tmp_path)
    trainer.train(data, manifest, tmp_path / "out")
    shadow = publish(tmp_path, tmp_path / "out")
    store = ShadowModelStore(str(shadow))
    features = {"f1": 1.5, "f2": 0.0, "f3": 0.0}

    ready = store.predict(ShadowRequest("BTCUSDT", 60, "test-v1", features, "qtml-test"))
    assert ready["status"] == "ready" and ready["execution"] is False
    assert ready["predicted_return_q10"] <= ready["predicted_return_q90"]
    assert abs(ready["predicted_return"]) < 0.05  # fraction, not percent

    assert store.predict(ShadowRequest("BTCUSDT", 60, "other-schema", features))["reason"] == "feature_schema_version_mismatch"
    assert store.predict(ShadowRequest("BTCUSDT", 60, "test-v1", features, "qtml-other"))["reason"] == "model_version_mismatch"
    assert store.predict(ShadowRequest("BTCUSDT", 15, "test-v1", features))["status"] == "unavailable"  # no horizon borrowing
    assert store.predict(ShadowRequest("BTCUSDT", 60, "test-v1", {"f1": 1.0}))["reason"] == "feature_names_mismatch"

    # Tamper with a published file: the next manifest reload must refuse it.
    target = shadow / "BTCUSDT-h60.qtml-test.model.txt"
    target.write_text(target.read_text(encoding="utf-8") + "\n", encoding="utf-8")
    fresh = ShadowModelStore(str(shadow))
    assert fresh.predict(ShadowRequest("BTCUSDT", 60, "test-v1", features))["reason"] == "artifact_integrity_failed:model"


def test_http_shadow_endpoint_contract(tmp_path):
    data, manifest = write_dataset(tmp_path)
    trainer.train(data, manifest, tmp_path / "out")
    shadow = publish(tmp_path, tmp_path / "out")
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(Settings(port=0, shadow_dir=str(shadow)), LightGBMProvider()))
    threading.Thread(target=server.handle_request, daemon=True).start()
    body = json.dumps({"symbol": "BTCUSDT", "horizon_minutes": 60, "feature_schema_version": "test-v1",
                       "features": {"f1": 0.2, "f2": 0.1, "f3": -0.3}, "expected_model_version": "qtml-test"}).encode()
    request = Request(f"http://127.0.0.1:{server.server_port}/v2/shadow/predict", data=body, headers={"Content-Type": "application/json"})
    with urlopen(request) as response:
        payload = json.load(response)["prediction"]
    server.server_close()
    assert payload["status"] == "ready"
    assert payload["model_version"] == "qtml-test"
    assert payload["influence"] is False and payload["execution"] is False
    assert math.isfinite(payload["predicted_return"])
