from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from src.config import settings
from src.utils import load_json


def main() -> None:
    report = load_json(settings.reports_dir / "evaluation_report.json", default={})
    if not report:
        raise FileNotFoundError("No evaluation report found. Train a model first.")
    print(json.dumps(report, indent=2))
    summary_path = settings.reports_dir / "evaluation_summary.md"
    print(f"\nSummary file: {summary_path}")


if __name__ == "__main__":
    main()
