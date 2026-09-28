from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Protocol


@dataclass(frozen=True)
class PredictionRequest:
    symbol: str
    horizon_seconds: int
    market_timestamp: str
    features: dict[str, float]


@dataclass(frozen=True)
class PredictionResult:
    provider: str
    model_version: str | None
    status: str
    reason: str | None = None
    direction: str | None = None
    confidence: float | None = None
    predicted_return_pct: float | None = None

    def as_dict(self) -> dict[str, Any]:
        return {
            "provider": self.provider,
            "model_version": self.model_version,
            "status": self.status,
            "reason": self.reason,
            "direction": self.direction,
            "confidence": self.confidence,
            "predicted_return_pct": self.predicted_return_pct,
            "influence": False,
            "execution": False,
        }


class PredictionProvider(Protocol):
    name: str

    def health(self) -> dict[str, Any]: ...

    def predict(self, request: PredictionRequest) -> PredictionResult: ...


class LightGBMProvider:
    name = "lightgbm"

    def __init__(self, model_path: str | None = None) -> None:
        self.model_path = model_path
        self._model: Any = None
        self._load_error: str | None = None
        if model_path:
            self._load(model_path)

    def _load(self, model_path: str) -> None:
        try:
            import lightgbm as lgb

            self._model = lgb.Booster(model_file=model_path)
        except Exception as exc:  # model loading must not bring down the service
            self._load_error = f"model_load_failed: {exc.__class__.__name__}"

    def health(self) -> dict[str, Any]:
        if self._model is not None:
            return {"name": self.name, "status": "ready", "model_version": self.model_path}
        reason = self._load_error or "model_not_configured"
        return {"name": self.name, "status": "unavailable", "reason": reason}

    def predict(self, request: PredictionRequest) -> PredictionResult:
        if self._model is None:
            health = self.health()
            return PredictionResult(self.name, None, "unavailable", health.get("reason"))
        try:
            values = [request.features[key] for key in sorted(request.features)]
            raw = self._model.predict([values])
            predicted = float(raw[0])
        except Exception as exc:
            return PredictionResult(self.name, self.model_path, "error", f"inference_failed: {exc.__class__.__name__}")
        direction = "BUY" if predicted > 0 else "SELL" if predicted < 0 else "WAIT"
        return PredictionResult(self.name, self.model_path, "ready", direction=direction, predicted_return_pct=predicted)
