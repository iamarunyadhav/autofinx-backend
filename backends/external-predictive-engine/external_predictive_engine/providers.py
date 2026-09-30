from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Protocol


FEATURE_ORDER = (
    "return_1",
    "distance_from_low_20",
    "distance_from_high_20",
    "volume_ratio_20",
)


@dataclass(frozen=True)
class PredictionRequest:
    symbol: str
    horizon_seconds: int
    market_timestamp: str
    features: dict[str, float]
    timeframe: str = ""


@dataclass(frozen=True)
class PredictionResult:
    provider: str
    model_version: str | None
    status: str
    reason: str | None = None
    direction: str | None = None
    confidence: float | None = None
    predicted_return_pct: float | None = None
    holdout_direction_accuracy_pct: float | None = None
    holdout_rows: int | None = None
    model_evaluation: dict[str, Any] | None = None

    def as_dict(self) -> dict[str, Any]:
        return {
            "provider": self.provider,
            "model_version": self.model_version,
            "status": self.status,
            "reason": self.reason,
            "direction": self.direction,
            "confidence": self.confidence,
            "predicted_return_pct": self.predicted_return_pct,
            "holdout_direction_accuracy_pct": self.holdout_direction_accuracy_pct,
            "holdout_rows": self.holdout_rows,
            "model_evaluation": self.model_evaluation,
            "influence": False,
            "execution": False,
        }


class PredictionProvider(Protocol):
    name: str

    def health(self) -> dict[str, Any]: ...

    def predict(self, request: PredictionRequest) -> PredictionResult: ...


class LightGBMProvider:
    name = "lightgbm"

    def __init__(self, model_path: str | None = None, model_dir: str | None = None) -> None:
        self.model_path = model_path
        self.model_dir = model_dir
        self._models: dict[str, Any] = {}
        self._metadata: dict[str, dict[str, Any]] = {}
        self._errors: dict[str, str] = {}
        if model_path:
            self._load("default", model_path)

    def _load(self, key: str, model_path: str) -> None:
        try:
            import lightgbm as lgb

            metadata_path = Path(model_path).with_suffix(".json")
            metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
            if metadata.get("quality_gate", {}).get("passed") is not True:
                self._errors[key] = "model_metadata_not_quality_passed"
                return
            if metadata.get("feature_version") != "ohlcv-v1" or metadata.get("features") != list(FEATURE_ORDER):
                self._errors[key] = "model_feature_schema_mismatch"
                return
            self._models[key] = lgb.Booster(model_file=model_path)
            self._metadata[key] = metadata
        except Exception as exc:  # model loading must not bring down the service
            self._errors[key] = f"model_load_failed: {exc.__class__.__name__}"

    def _key(self, request: PredictionRequest) -> str:
        return f"{request.symbol.upper()}-{request.timeframe.lower()}"

    def _path_for(self, request: PredictionRequest) -> str | None:
        if self.model_dir and request.timeframe:
            from pathlib import Path
            path = Path(self.model_dir) / f"{request.symbol.upper()}-{request.timeframe.lower()}.txt"
            return str(path) if path.is_file() else None
        return self.model_path

    def _model_for(self, request: PredictionRequest) -> Any:
        key = self._key(request)
        if key in self._models:
            return self._models[key]
        path = self._path_for(request)
        if path is None:
            return None
        self._load(key, path)
        return self._models.get(key)

    def health(self) -> dict[str, Any]:
        if self._models:
            return {"name": self.name, "status": "ready", "models_loaded": len(self._models), "model_dir": self.model_dir}
        if self.model_dir:
            from pathlib import Path
            available = len(list(Path(self.model_dir).glob("*.txt"))) if Path(self.model_dir).is_dir() else 0
            if available:
                return {"name": self.name, "status": "ready", "models_available": available, "model_dir": self.model_dir}
        return {"name": self.name, "status": "unavailable", "model_dir": self.model_dir, "reason": "model_not_configured"}

    def predict(self, request: PredictionRequest) -> PredictionResult:
        model = self._model_for(request)
        if model is None:
            return PredictionResult(self.name, None, "unavailable", self._errors.get(self._key(request), f"model_not_configured:{self._key(request)}"))
        try:
            values = [request.features[key] for key in FEATURE_ORDER]
            raw = model.predict([values])
            predicted = float(raw[0])
        except Exception as exc:
            return PredictionResult(self.name, self._key(request), "error", f"inference_failed: {exc.__class__.__name__}")
        metadata = self._metadata.get(self._key(request), {})
        metrics = metadata.get("metrics", {})
        costs = metadata.get("cost_assumptions", {})
        estimated_cost_pct = float(costs.get("estimated_round_trip_cost_pct") or 0.0)
        minimum_edge_pct = float(costs.get("minimum_edge_bps") or 0.0) / 100.0
        predicted_return_pct = predicted * 100.0
        expected_net_return_pct = abs(predicted_return_pct) - estimated_cost_pct
        direction = "BUY" if predicted > 0 else "SELL" if predicted < 0 else "WAIT"
        reason = None
        if direction != "WAIT" and abs(predicted_return_pct) < estimated_cost_pct + minimum_edge_pct:
            direction = "WAIT"
            reason = "predicted_edge_below_estimated_costs"
        evaluation = {
            "holdout": {"direction_accuracy_pct": metrics.get("direction_accuracy_pct"), "rows": metadata.get("holdout_rows"), "net": metrics.get("net_holdout")},
            "walk_forward": {"fold_count": metrics.get("walk_forward", {}).get("fold_count"), "trade_fold_count": metrics.get("walk_forward", {}).get("trade_fold_count"), "mean_direction_accuracy_pct": metrics.get("walk_forward", {}).get("mean_direction_accuracy_pct"), "mean_average_net_return_pct": metrics.get("walk_forward", {}).get("mean_average_net_return_pct")},
            "cost_assumptions": costs,
            "expected_net_return_pct": expected_net_return_pct,
        }
        return PredictionResult(
            self.name,
            metadata.get("model_version") or self._key(request),
            "ready",
            reason=reason,
            direction=direction,
            predicted_return_pct=predicted_return_pct,
            # This is an out-of-sample model metric, never a claim that the
            # individual live prediction is this likely to be correct.
            holdout_direction_accuracy_pct=metrics.get("direction_accuracy_pct"),
            holdout_rows=metadata.get("holdout_rows"),
            model_evaluation=evaluation,
        )
