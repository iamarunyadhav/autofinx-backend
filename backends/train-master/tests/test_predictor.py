from __future__ import annotations

import json

import joblib

from src.predictor import TradeQualityPredictor


class WrappedModel:
    def predict_proba(self, frame):
        return [[0.2, 0.8]]


def test_predictor_returns_recommendation(tmp_path) -> None:
    feature_columns = ["symbol", "side", "timeframe", "confidence"]
    model_path = tmp_path / "model.joblib"
    features_path = tmp_path / "features.json"
    joblib.dump(WrappedModel(), model_path)
    features_path.write_text(json.dumps(feature_columns), encoding="utf-8")

    predictor = TradeQualityPredictor(model_path=model_path, feature_path=features_path)
    result = predictor.predict({"symbol": "BTCUSDT", "side": "BUY", "timeframe": "5m", "confidence": 0.9})

    assert result.recommendation == "TRADE"
    assert result.win_probability > 0.5
