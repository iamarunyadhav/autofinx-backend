from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import os

from dotenv import load_dotenv


ROOT_DIR = Path(__file__).resolve().parents[1]
load_dotenv(ROOT_DIR / ".env")


@dataclass(frozen=True)
class Settings:
    root_dir: Path = ROOT_DIR
    data_dir: Path = ROOT_DIR / "data"
    raw_dir: Path = data_dir / "raw"
    processed_dir: Path = data_dir / "processed"
    models_dir: Path = data_dir / "models"
    reports_dir: Path = data_dir / "reports"
    host: str = os.getenv("TRAIN_MASTER_HOST", "127.0.0.1")
    port: int = int(os.getenv("TRAIN_MASTER_PORT", "8010"))
    model_name: str = os.getenv("TRAIN_MASTER_MODEL_NAME", "auto")
    random_state: int = int(os.getenv("TRAIN_MASTER_RANDOM_STATE", "42"))
    test_size: float = float(os.getenv("TRAIN_MASTER_TEST_SIZE", "0.25"))
    minimum_profit_threshold: float = float(
        os.getenv("TRAIN_MASTER_MINIMUM_PROFIT_THRESHOLD", "0.5")
    )
    negative_threshold: float = float(
        os.getenv("TRAIN_MASTER_NEGATIVE_THRESHOLD", "-0.5")
    )


settings = Settings()


def ensure_directories() -> None:
    paths = [
        settings.raw_dir / "trades",
        settings.raw_dir / "candles",
        settings.raw_dir / "exports",
        settings.processed_dir,
        settings.models_dir,
        settings.reports_dir,
        settings.root_dir / "notebooks",
        settings.root_dir / "tests",
        settings.root_dir / "ui",
    ]
    for path in paths:
        path.mkdir(parents=True, exist_ok=True)
