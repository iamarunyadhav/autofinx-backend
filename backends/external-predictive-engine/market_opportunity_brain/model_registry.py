"""Lazy model registry (MASTER_SPEC.md Layer F: "Models load lazily on separate worker/service").

Importing this module never imports torch/transformers/chronos -- it only maps a model name to
the submodule that knows how to lazy-load its own dependencies on first `predict()` call.
"""

from __future__ import annotations

from typing import Callable, Dict, List

AVAILABLE_MODELS: Dict[str, str] = {
    "naive_last": "market_opportunity_brain.models.naive",
    "chronos2": "market_opportunity_brain.models.chronos2",
    "granite_ttm_r3": "market_opportunity_brain.models.granite_ttm",
}


def list_models() -> List[str]:
    return sorted(AVAILABLE_MODELS.keys())


def get_predict_fn(model_name: str) -> Callable:
    if model_name not in AVAILABLE_MODELS:
        raise ValueError(f"Unknown model: {model_name}. Available: {list_models()}")

    import importlib

    module = importlib.import_module(AVAILABLE_MODELS[model_name])

    return module.predict
