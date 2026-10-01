"""Champion-model store for the Quick Trade shadow learning loop (Phase 1).

Laravel publishes each champion as ``{SYMBOL}-h{MINUTES}.json`` plus the model
files it lists, in ``AUTOFINX_PREDICTOR_SHADOW_DIR``. The JSON is renamed into
place LAST, and it carries the sha256 of every model file, so a half-written
publish is detected and refused rather than served.

Shadow only: the result says so explicitly and is never an order instruction.
"""

from __future__ import annotations

import hashlib
import json
import math
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Any


@dataclass(frozen=True)
class ShadowRequest:
    symbol: str
    horizon_minutes: int
    feature_schema_version: str
    features: dict[str, float]
    expected_model_version: str | None = None


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


class ShadowModelStore:
    def __init__(self, directory: str | None) -> None:
        self.directory = Path(directory) if directory else None
        self._cache: dict[str, tuple[float, dict[str, Any], dict[str, Any]]] = {}
        self._lock = threading.Lock()

    def _load(self, key: str) -> tuple[dict[str, Any], dict[str, Any]] | str:
        if self.directory is None:
            return "shadow_model_dir_not_configured"
        manifest_path = self.directory / f"{key}.json"
        if not manifest_path.is_file():
            return f"no_champion:{key}"
        mtime = manifest_path.stat().st_mtime
        with self._lock:
            cached = self._cache.get(key)
            if cached and cached[0] == mtime:
                return cached[1], cached[2]
        try:
            import lightgbm as lgb

            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            boosters: dict[str, Any] = {}
            for role, entry in manifest["files"].items():
                path = self.directory / entry["file"]
                if not path.is_file() or _sha256(path) != entry["sha256"]:
                    return f"artifact_integrity_failed:{role}"
                boosters[role] = lgb.Booster(model_file=str(path))
            if "model" not in boosters:
                return "artifact_missing_main_model"
        except Exception as exc:  # loading must never take the service down
            return f"model_load_failed:{exc.__class__.__name__}"
        with self._lock:
            self._cache[key] = (mtime, manifest, boosters)
        return manifest, boosters

    def health(self) -> dict[str, Any]:
        if self.directory is None or not self.directory.is_dir():
            return {"status": "unavailable", "shadow_dir": str(self.directory) if self.directory else None}
        return {"status": "ready", "champions": sorted(p.stem for p in self.directory.glob("*-h*.json"))}

    def predict(self, request: ShadowRequest) -> dict[str, Any]:
        key = f"{request.symbol.upper()}-h{int(request.horizon_minutes)}"
        base = {"provider": "lightgbm_shadow", "key": key, "influence": False, "execution": False}
        loaded = self._load(key)
        if isinstance(loaded, str):
            return {**base, "status": "unavailable", "reason": loaded}
        manifest, boosters = loaded
        if manifest.get("feature_schema_version") != request.feature_schema_version:
            return {**base, "status": "rejected", "reason": "feature_schema_version_mismatch",
                    "model_feature_schema_version": manifest.get("feature_schema_version")}
        if request.expected_model_version and manifest.get("model_version") != request.expected_model_version:
            return {**base, "status": "rejected", "reason": "model_version_mismatch", "model_version": manifest.get("model_version")}
        names = manifest.get("features") or []
        if set(names) != set(request.features):
            return {**base, "status": "rejected", "reason": "feature_names_mismatch"}
        vector = [float(request.features[name]) for name in names]
        if not all(math.isfinite(v) for v in vector):
            return {**base, "status": "rejected", "reason": "non_finite_feature"}

        # Models are trained on returns in percent (see train_shadow_horizon.py).
        to_fraction = 0.01 if manifest.get("target_unit") == "return_pct" else 1.0
        predicted = float(boosters["model"].predict([vector])[0]) * to_fraction
        q10 = float(boosters["q10"].predict([vector])[0]) * to_fraction if "q10" in boosters else None
        q90 = float(boosters["q90"].predict([vector])[0]) * to_fraction if "q90" in boosters else None
        if q10 is not None and q90 is not None and q10 > q90:
            q10, q90 = q90, q10
        return {
            **base,
            "status": "ready",
            "reason": None,
            "model_version": manifest.get("model_version"),
            "feature_schema_version": manifest.get("feature_schema_version"),
            "predicted_return": predicted,
            "predicted_return_q10": q10,
            "predicted_return_q90": q90,
        }
