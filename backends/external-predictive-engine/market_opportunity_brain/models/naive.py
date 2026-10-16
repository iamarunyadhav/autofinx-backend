"""`naive_last` baseline -- Python mirror of
app/Domain/MarketOpportunityBrain/Forecast/NaiveLastForecastAdapter.php. Always available, zero
dependencies, deterministic. Any real model that cannot beat this is not adding value
(MODEL_RESEARCH_REFERENCES.md: "prioritize demonstrating incremental... value before interpreting
direction scores as trade signals").
"""

from __future__ import annotations

import hashlib
from typing import List, Optional

from market_opportunity_brain.contracts import ForecastRecord

MODEL_NAME = "naive_last"
MODEL_REVISION = "v1"


def predict(
    closes: List[float],
    horizon_bars: int,
    symbol: str,
    timeframe: str,
    workspace_id: str,
    as_of_utc: str,
    last_close_time_utc: Optional[str] = None,
) -> ForecastRecord:
    forecast_id = f"naive-last-{symbol}-{as_of_utc}"

    if not closes:
        return ForecastRecord(
            forecast_id=forecast_id, workspace_id=workspace_id, exchange="BINANCE", market="USDM_PERPETUAL",
            symbol=symbol, timeframe=timeframe, horizon_bars=horizon_bars, as_of_utc=as_of_utc,
            known_by_utc=as_of_utc, label_available_at_utc=None, model_name=MODEL_NAME, model_revision=MODEL_REVISION,
            feature_schema_version="mob-features-v1", dataset_hash_sha256=None,
            prediction_quantiles={"p10": None, "p50": None, "p90": None},
            probabilities={"up": None, "neutral": None, "down": None},
            probability_calibrated=False, status="UNAVAILABLE", reason="NO_CLOSED_CANDLES_AVAILABLE",
        )

    last_close = float(closes[-1])
    dataset_hash = hashlib.sha256(f"{symbol}|{last_close_time_utc}|{last_close}".encode()).hexdigest()

    return ForecastRecord(
        forecast_id=forecast_id, workspace_id=workspace_id, exchange="BINANCE", market="USDM_PERPETUAL",
        symbol=symbol, timeframe=timeframe, horizon_bars=horizon_bars, as_of_utc=as_of_utc,
        known_by_utc=as_of_utc, label_available_at_utc=None, model_name=MODEL_NAME, model_revision=MODEL_REVISION,
        feature_schema_version="mob-features-v1", dataset_hash_sha256=dataset_hash,
        prediction_quantiles={"p10": last_close, "p50": last_close, "p90": last_close},
        probabilities={"up": None, "neutral": None, "down": None},
        probability_calibrated=False, status="OK", reason=None,
    )
