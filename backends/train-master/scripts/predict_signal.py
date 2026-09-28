from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from src.predictor import TradeQualityPredictor


def main() -> None:
    parser = argparse.ArgumentParser(description="Predict a trade quality score from JSON input")
    parser.add_argument("--input-json", required=True, help="Raw JSON payload")
    args = parser.parse_args()

    predictor = TradeQualityPredictor()
    payload = json.loads(args.input_json)
    result = predictor.predict(payload)
    print(json.dumps(result.__dict__, indent=2))


if __name__ == "__main__":
    main()
