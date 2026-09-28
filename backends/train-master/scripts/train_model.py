from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from src.config import settings
from src.data_loader import TradeDataLoader
from src.feature_engineering import build_feature_frame
from src.label_builder import build_labels
from src.model_trainer import train_quality_model


def main() -> None:
    parser = argparse.ArgumentParser(description="Train the AutoFinX trade quality model")
    parser.add_argument("--input", required=True, help="Path to trade export CSV/XLS/XLSX file")
    parser.add_argument("--candles", help="Optional path to candle data")
    parser.add_argument("--model-name", default=None, help="Specific model to train")
    args = parser.parse_args()

    loader = TradeDataLoader()
    trades_df, import_summary = loader.import_trade_export(args.input)
    candles_df = loader.load_optional_candles(args.candles)
    bundle = build_feature_frame(trades_df, candles_df)
    labeled_df = build_labels(
        bundle.frame,
        minimum_profit_threshold=settings.minimum_profit_threshold,
        negative_threshold=settings.negative_threshold,
    )
    artifacts = train_quality_model(
        labeled_df=labeled_df,
        feature_columns=bundle.feature_columns,
        model_name=args.model_name,
    )

    result = {
        "import_summary": import_summary,
        "selected_model": artifacts.model_name,
        "metrics": artifacts.metrics,
    }
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
