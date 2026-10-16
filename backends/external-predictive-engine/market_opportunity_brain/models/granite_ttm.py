"""IBM Granite TinyTimeMixer R3 (TTM-R3) forecast adapter (MASTER_SPEC.md Layer F,
MODEL_RESEARCH_REFERENCES.md).

Real API confirmed by introspecting the actually-installed `granite-tsfm==0.3.3` package in this
session's SEPARATE isolated venv (`D:\\autofinx-mob-v4\\venv-granite` -- NOT the same venv as
Chronos-2; see DECISIONS.md MOB-D-008 for the dependency conflict that makes two venvs necessary):

    from tsfm_public.toolkit.get_model import get_model
    model = get_model("ibm-granite/granite-timeseries-ttm-r3", context_length=512, prediction_length=N)
    out = model(past_values=past_values_tensor)  # past_values: (batch, context_length, channels)
    out.prediction_outputs  # (batch, prediction_length, channels)

**IMPORTANT, HONEST, CONFIRMED FINDING (DECISIONS.md MOB-D-010)**: loading this model via the
library's OWN official `get_model()` selector -- the documented, recommended entry point, not a
custom/guessed API -- logs "Some weights... were not initialized from the model checkpoint...and
are newly initialized" for the large majority of the model's mixer/decoder/head weights, and the
library itself prints "You should probably TRAIN this model on a down-stream task to be able to
use it for predictions and inference." This means the model AS CURRENTLY PUBLISHED/CACHED cannot
be trusted for zero-shot forecasting in this environment: the forward pass runs without error and
returns a correctly-shaped tensor, but its *values* would be dominated by randomly-initialized
weights, not the pretrained checkpoint. This adapter therefore deliberately reports
`MODEL_UNAVAILABLE` rather than `OK` for this reason, until a future session resolves the
revision/library-version mismatch (MASTER_SPEC.md's own caution: "do not blindly assume custom
inference signatures or model dimensions" -- this is exactly such a case, discovered empirically).
"""

from __future__ import annotations

import hashlib
import io
import logging
import time
from typing import List, Optional

from market_opportunity_brain.contracts import ForecastRecord

MODEL_NAME = "granite_ttm_r3"
MODEL_REVISION = "ibm-granite/granite-timeseries-ttm-r3"

_MODEL_CACHE = {}


class _CaptureHandler(logging.Handler):
    """Captures transformers' "newly initialized" warning so predict() can detect it
    programmatically instead of just letting it print to stderr."""

    def __init__(self):
        super().__init__()
        self.messages: list[str] = []

    def emit(self, record: logging.LogRecord) -> None:
        self.messages.append(record.getMessage())


def _load_model(context_length: int, horizon_bars: int):
    key = (context_length, horizon_bars)
    if key not in _MODEL_CACHE:
        from tsfm_public.toolkit.get_model import get_model

        handler = _CaptureHandler()
        logging.getLogger("transformers.modeling_utils").addHandler(handler)
        try:
            model = get_model(MODEL_REVISION, context_length=context_length, prediction_length=horizon_bars)
        finally:
            logging.getLogger("transformers.modeling_utils").removeHandler(handler)

        weights_not_loaded = any("newly initialized" in m for m in handler.messages)
        model.eval()
        _MODEL_CACHE[key] = (model, weights_not_loaded)

    return _MODEL_CACHE[key]


def predict(
    closes: List[float],
    horizon_bars: int,
    symbol: str,
    timeframe: str,
    workspace_id: str,
    as_of_utc: str,
    last_close_time_utc: Optional[str] = None,
    context_length: int = 512,
) -> ForecastRecord:
    forecast_id = f"granite-ttm-r3-{symbol}-{as_of_utc}"
    common = dict(
        forecast_id=forecast_id, workspace_id=workspace_id, exchange="BINANCE", market="USDM_PERPETUAL",
        symbol=symbol, timeframe=timeframe, horizon_bars=horizon_bars, as_of_utc=as_of_utc,
        known_by_utc=as_of_utc, label_available_at_utc=None, model_name=MODEL_NAME, model_revision=MODEL_REVISION,
        feature_schema_version="mob-features-v1",
        prediction_quantiles={"p10": None, "p50": None, "p90": None},
        probabilities={"up": None, "neutral": None, "down": None},
        probability_calibrated=False,
    )

    if not closes:
        return ForecastRecord(**common, dataset_hash_sha256=None, status="UNAVAILABLE", reason="NO_CLOSED_CANDLES_AVAILABLE")

    try:
        import torch
    except ImportError as exc:
        return ForecastRecord(**common, dataset_hash_sha256=None, status="MODEL_UNAVAILABLE", reason=f"DEPENDENCY_MISSING: {exc}")

    try:
        t0 = time.time()
        model, weights_not_loaded = _load_model(context_length, horizon_bars)

        if weights_not_loaded:
            # Confirmed, reproducible finding -- see module docstring and DECISIONS.md MOB-D-010.
            # Never report OK for a forward pass whose core weights are known to be untrained.
            return ForecastRecord(
                **common, dataset_hash_sha256=None, status="MODEL_UNAVAILABLE",
                reason="ZERO_SHOT_WEIGHTS_NOT_VERIFIED_SEE_DECISIONS_MD_MOB_D_010",
            )

        series = [float(c) for c in closes]
        if len(series) < context_length:
            # Left-pad with the earliest known value rather than fabricate earlier history --
            # TTM's patch-mixer architecture requires a fixed context_length input shape.
            series = [series[0]] * (context_length - len(series)) + series
        else:
            series = series[-context_length:]

        past_values = torch.tensor(series, dtype=torch.float32).reshape(1, context_length, 1)
        with torch.no_grad():
            out = model(past_values=past_values)
        path = out.prediction_outputs[0, :, 0].tolist()
        latency_ms = (time.time() - t0) * 1000.0

        last_step = float(path[-1])
        # TTM-R3 (as used here) is a point-forecast model, not a quantile model -- p10=p50=p90 at
        # the point estimate is an honest representation of "no distributional uncertainty
        # estimate available", not a fabricated spread.
        dataset_hash = hashlib.sha256(f"{symbol}|{last_close_time_utc}|{closes[-1]}|{MODEL_NAME}".encode()).hexdigest()

        return ForecastRecord(
            **{**common, "prediction_quantiles": {"p10": last_step, "p50": last_step, "p90": last_step}},
            dataset_hash_sha256=dataset_hash, status="OK", reason=f"latency_ms={latency_ms:.1f}",
        )
    except Exception as exc:  # noqa: BLE001
        return ForecastRecord(**common, dataset_hash_sha256=None, status="MODEL_UNAVAILABLE", reason=f"INFERENCE_ERROR: {exc}")
