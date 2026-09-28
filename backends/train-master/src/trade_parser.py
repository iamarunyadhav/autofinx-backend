from __future__ import annotations

from pathlib import Path
from typing import Iterable

import pandas as pd

from .utils import get_logger, safe_float, summarize_missing_columns


logger = get_logger(__name__)

TRADE_COLUMN_ALIASES = {
    "symbol": ["symbol", "pair", "market"],
    "side": ["side", "direction", "position_side"],
    "entry_time": ["entry_time", "open_time", "entry date", "opened_at"],
    "exit_time": ["exit_time", "close_time", "exit date", "closed_at"],
    "entry_price": ["entry_price", "open_price", "buy_price"],
    "exit_price": ["exit_price", "close_price", "sell_price"],
    "quantity": ["quantity", "qty", "size"],
    "status": ["status", "state"],
    "realized_pnl": ["realized_pnl", "realized pnl", "pnl", "gross_pnl"],
    "fees": ["fees", "fee", "commission"],
    "net_pnl": ["net_pnl", "net pnl", "profit", "profit_loss"],
    "confidence": ["confidence", "score", "signal_confidence"],
    "active_minimum": ["active_minimum", "min_confidence", "threshold"],
    "signal": ["signal", "signal_type"],
    "dominant_signal": ["dominant_signal", "dominant"],
    "timeframe": ["timeframe", "interval"],
    "skip_reason": ["skip_reason", "skip note", "skip_note"],
    "trade_reason": ["trade_reason", "reason", "entry_reason"],
    "metadata": ["metadata", "meta", "notes"],
}

REQUIRED_OUTPUT_COLUMNS = list(TRADE_COLUMN_ALIASES.keys()) + [
    "win_or_loss",
    "duration",
]


def _normalize_column_names(columns: Iterable[str]) -> dict[str, str]:
    mapping: dict[str, str] = {}
    for column in columns:
        normalized = str(column).strip().lower().replace("-", "_")
        normalized = normalized.replace(" ", "_")
        mapping[column] = normalized
    return mapping


def load_trade_file(path: Path) -> pd.DataFrame:
    suffix = path.suffix.lower()
    if suffix == ".csv":
        return pd.read_csv(path)
    if suffix in {".xls", ".xlsx"}:
        return pd.read_excel(path)
    raise ValueError(f"Unsupported file type: {path.suffix}")


def normalize_trade_dataframe(df: pd.DataFrame) -> tuple[pd.DataFrame, list[str]]:
    normalized = df.rename(columns=_normalize_column_names(df.columns)).copy()
    missing_columns: list[str] = []

    output = pd.DataFrame()
    for target, aliases in TRADE_COLUMN_ALIASES.items():
        source = next((alias for alias in aliases if alias in normalized.columns), None)
        if source is None:
            output[target] = None
            missing_columns.append(target)
        else:
            output[target] = normalized[source]

    for numeric_column in [
        "entry_price",
        "exit_price",
        "quantity",
        "realized_pnl",
        "fees",
        "net_pnl",
        "confidence",
        "active_minimum",
    ]:
        output[numeric_column] = output[numeric_column].map(safe_float)

    for time_column in ["entry_time", "exit_time"]:
        output[time_column] = pd.to_datetime(output[time_column], errors="coerce", utc=True)

    output["symbol"] = output["symbol"].fillna("UNKNOWN").astype(str).str.upper()
    output["side"] = output["side"].fillna("UNKNOWN").astype(str).str.upper()
    output["timeframe"] = output["timeframe"].fillna("unknown").astype(str)
    output["status"] = output["status"].fillna("unknown").astype(str)
    output["signal"] = output["signal"].fillna("unknown").astype(str)
    output["dominant_signal"] = output["dominant_signal"].fillna("unknown").astype(str)
    output["skip_reason"] = output["skip_reason"].fillna("").astype(str)
    output["trade_reason"] = output["trade_reason"].fillna("").astype(str)
    output["metadata"] = output["metadata"].fillna("").astype(str)

    output["net_pnl"] = output["net_pnl"].where(
        output["net_pnl"] != 0.0,
        output["realized_pnl"] - output["fees"],
    )
    output["win_or_loss"] = (output["net_pnl"] > 0).map({True: "win", False: "loss"})
    output["duration"] = (
        (output["exit_time"] - output["entry_time"]).dt.total_seconds().fillna(0) / 60.0
    ).clip(lower=0)

    logger.info("Trade parser normalized %s rows", len(output))
    if missing_columns:
        logger.warning("Missing trade fields detected: %s", summarize_missing_columns(missing_columns))

    return output[REQUIRED_OUTPUT_COLUMNS], summarize_missing_columns(missing_columns)
