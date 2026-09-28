from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Protocol


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

    def __init__(self, model_path: str | None = None, model_dir: str | None = None) -> None:
        self.model_path = model_path
        self.model_dir = model_dir
        self._models: dict[str, Any] = {}
        self._errors: dict[str, str] = {}
        if model_path:
            self._load("default", model_path)

    def _load(self, key: str, model_path: str) -> None:
        try:
            import lightgbm as lgb

            self._models[key] = lgb.Booster(model_file=model_path)
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
            return PredictionResult(self.name, None, "unavailable", f"model_not_configured:{self._key(request)}")
        try:
            values = [request.features[key] for key in sorted(request.features)]
            raw = model.predict([values])
            predicted = float(raw[0])
        except Exception as exc:
            return PredictionResult(self.name, self._key(request), "error", f"inference_failed: {exc.__class__.__name__}")
        direction = "BUY" if predicted > 0 else "SELL" if predicted < 0 else "WAIT"
        return PredictionResult(self.name, self._key(request), "ready", direction=direction, predicted_return_pct=predicted)
