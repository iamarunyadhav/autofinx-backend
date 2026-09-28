from __future__ import annotations

from pathlib import Path

import pandas as pd

from .utils import get_logger


logger = get_logger(__name__)


def load_candle_file(path: Path) -> pd.DataFrame:
    suffix = path.suffix.lower()
    if suffix == ".csv":
        candles = pd.read_csv(path)
    elif suffix in {".xls", ".xlsx"}:
        candles = pd.read_excel(path)
    else:
        raise ValueError(f"Unsupported candle file type: {path.suffix}")

    candles.columns = [
        str(column).strip().lower().replace("-", "_").replace(" ", "_")
        for column in candles.columns
    ]
    if "timestamp" in candles.columns:
        candles["timestamp"] = pd.to_datetime(candles["timestamp"], errors="coerce", utc=True)
    logger.info("Loaded candle file %s with %s rows", path.name, len(candles))
    return candles
