from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import pandas as pd

from .utils import get_logger, safe_float


logger = get_logger(__name__)

DEFAULT_FEATURE_COLUMNS = [
    "symbol",
    "side",
    "timeframe",
    "confidence",
    "active_minimum",
    "confidence_gap",
    "recent_win_rate_5",
    "recent_win_rate_10",
    "recent_win_rate_20",
    "recent_avg_pnl_10",
    "recent_loss_streak",
    "recent_profit_factor",
    "hour_of_day",
    "day_of_week",
    "entry_price_change_percent",
    "atr_percent",
    "volatility_percent",
    "vwap_distance_percent",
    "ema_alignment_score",
    "adx_value",
    "macd_direction",
    "rsi_value",
    "volume_score",
    "spread_percent",
    "slippage_percent",
    "signal_quality",
    "soft_penalty_count",
    "hard_blocker_count",
]


@dataclass
class FeatureBundle:
    frame: pd.DataFrame
    feature_columns: list[str]


def _compute_recent_loss_streak(net_pnl: pd.Series) -> pd.Series:
    streaks: list[int] = []
    streak = 0
    for pnl in net_pnl.fillna(0.0):
        if pnl < 0:
            streak += 1
        else:
            streak = 0
        streaks.append(streak)
    return pd.Series(streaks, index=net_pnl.index)


def _profit_factor(window_pnl: pd.Series) -> float:
    gains = window_pnl[window_pnl > 0].sum()
    losses = abs(window_pnl[window_pnl < 0].sum())
    if losses == 0:
        return float(gains) if gains > 0 else 0.0
    return float(gains / losses)


def _recent_metric(series: pd.Series, window: int, reducer: str) -> pd.Series:
    shifted = series.shift(1)
    if reducer == "mean":
        return shifted.rolling(window, min_periods=1).mean().fillna(0.0)
    if reducer == "sum":
        return shifted.rolling(window, min_periods=1).sum().fillna(0.0)
    raise ValueError(f"Unsupported reducer: {reducer}")


def build_feature_frame(trades_df: pd.DataFrame, candles_df: pd.DataFrame | None = None) -> FeatureBundle:
    if trades_df.empty:
        raise ValueError("Trade dataset is empty")

    df = trades_df.copy().sort_values("entry_time").reset_index(drop=True)

    for column in [
        "confidence",
        "active_minimum",
        "entry_price",
        "exit_price",
        "quantity",
        "net_pnl",
    ]:
        if column in df.columns:
            df[column] = df[column].map(safe_float)

    df["confidence_gap"] = df["confidence"] - df["active_minimum"]
    win_series = (df["net_pnl"] > 0).astype(float)
    df["recent_win_rate_5"] = _recent_metric(win_series, 5, "mean")
    df["recent_win_rate_10"] = _recent_metric(win_series, 10, "mean")
    df["recent_win_rate_20"] = _recent_metric(win_series, 20, "mean")
    df["recent_avg_pnl_10"] = _recent_metric(df["net_pnl"], 10, "mean")
    df["recent_loss_streak"] = _compute_recent_loss_streak(df["net_pnl"]).shift(1).fillna(0)
    df["recent_profit_factor"] = (
        df["net_pnl"]
        .shift(1)
        .rolling(10, min_periods=1)
        .apply(_profit_factor, raw=False)
        .fillna(0.0)
    )

    entry_times = pd.to_datetime(df["entry_time"], errors="coerce", utc=True)
    df["hour_of_day"] = entry_times.dt.hour.fillna(0).astype(int)
    df["day_of_week"] = entry_times.dt.dayofweek.fillna(0).astype(int)

    entry_price = df["entry_price"].replace(0, np.nan)
    exit_price = df["exit_price"].replace(0, np.nan)
    df["entry_price_change_percent"] = (((exit_price - entry_price) / entry_price) * 100.0).fillna(0.0)

    fallback_columns = {
        "atr_percent": 0.0,
        "volatility_percent": df["entry_price_change_percent"].abs().rolling(5, min_periods=1).mean(),
        "vwap_distance_percent": 0.0,
        "ema_alignment_score": np.where(df["side"].str.upper() == "BUY", 0.55, 0.45),
        "adx_value": 0.0,
        "macd_direction": np.where(df["entry_price_change_percent"] >= 0, "bullish", "bearish"),
        "rsi_value": 50.0,
        "volume_score": 0.5,
        "spread_percent": 0.0,
        "slippage_percent": 0.0,
    }

    for column, fallback in fallback_columns.items():
        if column not in df.columns:
            df[column] = fallback
        else:
            if column == "macd_direction":
                df[column] = df[column].fillna(fallback)
            else:
                df[column] = df[column].fillna(fallback).map(safe_float)

    df["signal_quality"] = (
        (df["confidence_gap"] * 0.45)
        + (df["recent_win_rate_20"] * 0.25)
        + (df["ema_alignment_score"].map(safe_float) * 0.15)
        + ((df["adx_value"].map(safe_float) / 100.0) * 0.15)
    )
    df["soft_penalty_count"] = (
        (df["skip_reason"].fillna("").str.len() > 0).astype(int)
        + (df["confidence_gap"] < 0).astype(int)
        + (df["recent_loss_streak"] >= 2).astype(int)
    )
    df["hard_blocker_count"] = (
        (df["confidence"] <= 0).astype(int)
        + (df["quantity"] <= 0).astype(int)
        + (df["status"].str.lower() == "rejected").astype(int)
    )

    if candles_df is not None and not candles_df.empty:
        logger.info("Candle data detected; current feature builder uses trade-aligned fallback merge mode")

    for column in DEFAULT_FEATURE_COLUMNS:
        if column not in df.columns:
            df[column] = 0.0 if column not in {"symbol", "side", "timeframe", "macd_direction"} else "unknown"

    feature_frame = df[DEFAULT_FEATURE_COLUMNS + ["net_pnl"]].copy()
    logger.info("Built feature frame with %s rows and %s features", len(feature_frame), len(DEFAULT_FEATURE_COLUMNS))
    return FeatureBundle(frame=feature_frame, feature_columns=DEFAULT_FEATURE_COLUMNS)
