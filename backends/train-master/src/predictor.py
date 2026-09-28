from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

import joblib
import pandas as pd

from .config import settings
from .utils import load_json


@dataclass
class PredictionResult:
    recommendation: str
    quality_score: float
    win_probability: float
    risk_level: str
    position_size_multiplier: float
    reasons: list[str]


class TradeQualityPredictor:
    def __init__(self, model_path: Path | None = None, feature_path: Path | None = None) -> None:
        self.model_path = model_path or settings.models_dir / "trade_quality_model.joblib"
        self.feature_path = feature_path or settings.models_dir / "feature_columns.json"
        self.model = joblib.load(self.model_path)
        self.feature_columns: list[str] = load_json(self.feature_path, default=[])
        if not self.feature_columns:
            raise ValueError("Feature columns metadata is missing")

    def _build_reason_list(self, payload: dict[str, Any], probability: float) -> list[str]:
        reasons: list[str] = []
        if payload.get("confidence", 0) > payload.get("active_minimum", 0):
            reasons.append("Confidence is above active minimum")
        if payload.get("ema_alignment_score", 0) >= 0.6:
            reasons.append("EMA direction is supportive")
        if abs(payload.get("vwap_distance_percent", 0)) <= 0.25:
            reasons.append("VWAP distance is controlled")
        if payload.get("adx_value", 0) >= 20:
            reasons.append("ADX shows enough trend strength")
        if payload.get("recent_win_rate_20", 0) >= 0.5:
            reasons.append("Recent win rate acceptable")
        if probability < 0.45:
            reasons.append("Model win probability is currently weak")
        return reasons or ["Prediction based on available historical patterns"]

    def predict(self, payload: dict[str, Any]) -> PredictionResult:
        row = {column: payload.get(column, 0 if column not in {"symbol", "side", "timeframe", "macd_direction"} else "unknown") for column in self.feature_columns}
        frame = pd.DataFrame([row], columns=self.feature_columns)
        probability = float(self.model.predict_proba(frame)[0][1])
        quality_score = probability

        if probability >= 0.70:
            recommendation = "TRADE"
            position_multiplier = 1.0
            risk_level = "low"
        elif probability >= 0.58:
            recommendation = "TRADE_SMALL_SIZE"
            position_multiplier = 0.75
            risk_level = "medium"
        elif probability >= 0.45:
            recommendation = "HIGH_RISK_WAIT"
            position_multiplier = 0.25
            risk_level = "high"
        else:
            recommendation = "WAIT"
            position_multiplier = 0.0
            risk_level = "high"

        return PredictionResult(
            recommendation=recommendation,
            quality_score=quality_score,
            win_probability=probability,
            risk_level=risk_level,
            position_size_multiplier=position_multiplier,
            reasons=self._build_reason_list(payload, probability),
        )
