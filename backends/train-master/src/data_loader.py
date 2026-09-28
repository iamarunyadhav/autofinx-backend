from __future__ import annotations

from pathlib import Path

import pandas as pd

from .candle_parser import load_candle_file
from .config import ensure_directories, settings
from .trade_parser import load_trade_file, normalize_trade_dataframe
from .utils import get_logger, save_json


logger = get_logger(__name__)


class TradeDataLoader:
    def __init__(self) -> None:
        ensure_directories()

    def import_trade_export(self, input_path: str | Path) -> tuple[pd.DataFrame, dict]:
        path = Path(input_path)
        if not path.exists():
            raise FileNotFoundError(f"Trade export not found: {path}")

        raw_df = load_trade_file(path)
        trades_df, missing_columns = normalize_trade_dataframe(raw_df)

        processed_path = settings.processed_dir / f"{path.stem}_processed.csv"
        trades_df.to_csv(processed_path, index=False)

        summary = {
            "source_file": str(path),
            "processed_file": str(processed_path),
            "row_count": int(len(trades_df)),
            "missing_columns": missing_columns,
            "win_rate": float((trades_df["net_pnl"] > 0).mean()) if len(trades_df) else 0.0,
            "net_pnl": float(trades_df["net_pnl"].sum()) if "net_pnl" in trades_df else 0.0,
        }
        save_json(settings.reports_dir / "import_summary.json", summary)
        logger.info("Saved processed trade export to %s", processed_path)
        return trades_df, summary

    def load_processed_trades(self, processed_path: str | Path) -> pd.DataFrame:
        path = Path(processed_path)
        if not path.exists():
            raise FileNotFoundError(f"Processed trade data not found: {path}")
        df = pd.read_csv(path)
        for time_column in ["entry_time", "exit_time"]:
            if time_column in df.columns:
                df[time_column] = pd.to_datetime(df[time_column], errors="coerce", utc=True)
        return df

    def load_optional_candles(self, input_path: str | Path | None) -> pd.DataFrame | None:
        if not input_path:
            return None
        path = Path(input_path)
        if not path.exists():
            logger.warning("Optional candle file not found: %s", path)
            return None
        return load_candle_file(path)
