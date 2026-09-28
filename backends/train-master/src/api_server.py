from __future__ import annotations

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from .predictor import TradeQualityPredictor


class PredictionRequest(BaseModel):
    symbol: str = Field(default="UNKNOWN")
    side: str = Field(default="UNKNOWN")
    timeframe: str = Field(default="unknown")
    confidence: float = 0.0
    active_minimum: float = 0.0
    recent_win_rate_5: float = 0.0
    recent_win_rate_10: float = 0.0
    recent_win_rate_20: float = 0.0
    recent_avg_pnl_10: float = 0.0
    recent_loss_streak: float = 0.0
    recent_profit_factor: float = 0.0
    hour_of_day: int = 0
    day_of_week: int = 0
    entry_price_change_percent: float = 0.0
    atr_percent: float = 0.0
    volatility_percent: float = 0.0
    vwap_distance_percent: float = 0.0
    ema_alignment_score: float = 0.0
    adx_value: float = 0.0
    macd_direction: str = "neutral"
    rsi_value: float = 50.0
    volume_score: float = 0.0
    spread_percent: float = 0.0
    slippage_percent: float = 0.0
    signal_quality: float = 0.0
    soft_penalty_count: int = 0
    hard_blocker_count: int = 0


def create_app() -> FastAPI:
    app = FastAPI(title="AutoFinX Train Master API", version="0.1.0")

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.post("/predict-trade-quality")
    def predict_trade_quality(request: PredictionRequest) -> dict:
        try:
            predictor = TradeQualityPredictor()
        except Exception as exc:
            raise HTTPException(status_code=503, detail=f"Model unavailable: {exc}") from exc

        result = predictor.predict(request.model_dump())
        return {
            "recommendation": result.recommendation,
            "quality_score": result.quality_score,
            "win_probability": result.win_probability,
            "risk_level": result.risk_level,
            "position_size_multiplier": result.position_size_multiplier,
            "reasons": result.reasons,
        }

    return app
