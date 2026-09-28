from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from src.data_loader import TradeDataLoader


def main() -> None:
    parser = argparse.ArgumentParser(description="Import and normalize a trade export file")
    parser.add_argument("--input", required=True, help="Path to trade export CSV/XLS/XLSX file")
    args = parser.parse_args()

    loader = TradeDataLoader()
    _, summary = loader.import_trade_export(args.input)
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
