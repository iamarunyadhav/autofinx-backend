from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    host: str = "127.0.0.1"
    port: int = 8091
    provider: str = "lightgbm"
    model_path: str | None = None
    model_dir: str | None = None
    shadow_dir: str | None = None
    log_level: str = "INFO"
    influence: bool = False
    execution: bool = False

    @classmethod
    def from_env(cls) -> "Settings":
        raw_port = os.getenv("AUTOFINX_PREDICTOR_PORT", "8091")
        try:
            port = int(raw_port)
        except ValueError as exc:
            raise ValueError("AUTOFINX_PREDICTOR_PORT must be an integer") from exc
        if not 1 <= port <= 65535:
            raise ValueError("AUTOFINX_PREDICTOR_PORT must be between 1 and 65535")
        return cls(
            host=os.getenv("AUTOFINX_PREDICTOR_HOST", "127.0.0.1"),
            port=port,
            provider=os.getenv("AUTOFINX_PREDICTOR_PROVIDER", "lightgbm"),
            model_path=os.getenv("AUTOFINX_PREDICTOR_MODEL_PATH") or None,
            model_dir=os.getenv("AUTOFINX_PREDICTOR_MODEL_DIR") or None,
            shadow_dir=os.getenv("AUTOFINX_PREDICTOR_SHADOW_DIR") or None,
            log_level=os.getenv("AUTOFINX_PREDICTOR_LOG_LEVEL", "INFO"),
        )
