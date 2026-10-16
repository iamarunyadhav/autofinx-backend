"""Amazon Chronos-2 forecast adapter (MASTER_SPEC.md Layer F, MODEL_RESEARCH_REFERENCES.md).

Real API confirmed by introspecting the actually-installed `chronos-forecasting==2.3.2` package in
this session's isolated venv (D:\\autofinx-mob-v4\\venv) -- NOT assumed from documentation alone,
per this repo's own "verify against the real code/library before trusting a spec" discipline:

    from chronos import Chronos2Pipeline
    pipeline = Chronos2Pipeline.from_pretrained("amazon/chronos-2", device_map="cpu")
    quantiles, mean = pipeline.predict_quantiles([series_tensor], prediction_length=N,
                                                  quantile_levels=[0.1, 0.5, 0.9])

`quantiles[0]` has shape (prediction_length, len(quantile_levels)); this adapter reports the
FINAL horizon step's quantiles as the forecast-at-horizon (matching the PHP `ForecastRecord`
contract's single p10/p50/p90 triple, not a full per-bar path).

No network call and no model load happens at import time -- only inside `predict()`, and the
pipeline is memoized per-process so repeated calls do not reload ~456MB of weights each time.
"""

from __future__ import annotations

import hashlib
import time
from typing import List, Optional

from market_opportunity_brain.contracts import ForecastRecord

MODEL_NAME = "chronos2"
MODEL_REVISION = "amazon/chronos-2"  # HF repo id; exact commit sha recorded in benchmark output, not hardcoded here

_PIPELINE_CACHE = {}


def _get_pipeline():
    if "pipeline" not in _PIPELINE_CACHE:
        from chronos import Chronos2Pipeline

        _PIPELINE_CACHE["pipeline"] = Chronos2Pipeline.from_pretrained(MODEL_REVISION, device_map="cpu")

    return _PIPELINE_CACHE["pipeline"]


def predict(
    closes: List[float],
    horizon_bars: int,
    symbol: str,
    timeframe: str,
    workspace_id: str,
    as_of_utc: str,
    last_close_time_utc: Optional[str] = None,
) -> ForecastRecord:
    forecast_id = f"chronos2-{symbol}-{as_of_utc}"
    common = dict(
        forecast_id=forecast_id, workspace_id=workspace_id, exchange="BINANCE", market="USDM_PERPETUAL",
        symbol=symbol, timeframe=timeframe, horizon_bars=horizon_bars, as_of_utc=as_of_utc,
        known_by_utc=as_of_utc, label_available_at_utc=None, model_name=MODEL_NAME, model_revision=MODEL_REVISION,
        feature_schema_version="mob-features-v1",
    )

    if not closes:
        return ForecastRecord(
            **common, dataset_hash_sha256=None,
            prediction_quantiles={"p10": None, "p50": None, "p90": None},
            probabilities={"up": None, "neutral": None, "down": None},
            probability_calibrated=False, status="UNAVAILABLE", reason="NO_CLOSED_CANDLES_AVAILABLE",
        )

    try:
        import torch
    except ImportError as exc:
        return ForecastRecord(
            **common, dataset_hash_sha256=None,
            prediction_quantiles={"p10": None, "p50": None, "p90": None},
            probabilities={"up": None, "neutral": None, "down": None},
            probability_calibrated=False, status="MODEL_UNAVAILABLE", reason=f"DEPENDENCY_MISSING: {exc}",
        )

    try:
        t0 = time.time()
        pipeline = _get_pipeline()
        series = torch.tensor([float(c) for c in closes], dtype=torch.float32)
        quantiles, _mean = pipeline.predict_quantiles(
            [series], prediction_length=max(1, horizon_bars), quantile_levels=[0.1, 0.5, 0.9],
        )
        # quantiles[0] (item 0 = our one input series) has shape (channels=1, horizon_bars, 3) --
        # confirmed empirically (NOT (horizon_bars, 3) as a naive reading of the library's own
        # type hint "list[Tensor]" might suggest): index channel 0, then the LAST horizon step.
        last_step = quantiles[0][0][-1].tolist()
        latency_ms = (time.time() - t0) * 1000.0
        p10, p50, p90 = float(last_step[0]), float(last_step[1]), float(last_step[2])
        # Chronos-2 returns raw quantiles only; per MASTER_SPEC.md Layer F §70, no direction
        # percentage is invented from a quantile spread -- probabilities stay null/uncalibrated
        # until a real CalibrationValidator exists (not built in this phase).
        dataset_hash = hashlib.sha256(f"{symbol}|{last_close_time_utc}|{closes[-1]}|{MODEL_NAME}".encode()).hexdigest()

        return ForecastRecord(
            **common, dataset_hash_sha256=dataset_hash,
            prediction_quantiles={"p10": p10, "p50": p50, "p90": p90},
            probabilities={"up": None, "neutral": None, "down": None},
            probability_calibrated=False, status="OK", reason=f"latency_ms={latency_ms:.1f}",
        )
    except Exception as exc:  # noqa: BLE001 -- must degrade to MODEL_UNAVAILABLE, never crash the caller
        return ForecastRecord(
            **common, dataset_hash_sha256=None,
            prediction_quantiles={"p10": None, "p50": None, "p90": None},
            probabilities={"up": None, "neutral": None, "down": None},
            probability_calibrated=False, status="MODEL_UNAVAILABLE", reason=f"INFERENCE_ERROR: {exc}",
        )


def predict_batch(windows, horizon_bars: int, batch_size: int = 16) -> dict:
    """Batched Chronos-2 inference for the offline experiment dataset builder (one model load).

    `windows` = list of {"key": str, "closes": [float,...]}. Each window is forecast independently
    (univariate, no covariates, no cross-window information) so batching cannot leak future data.
    Returns {"status","reason","revision","results":{key:{"p10","p50","p90"}}}. Never raises.
    """
    try:
        import torch

        t0 = time.time()
        pipeline = _get_pipeline()
        results = {}
        for start in range(0, len(windows), max(1, batch_size)):
            chunk = windows[start:start + max(1, batch_size)]
            series = [torch.tensor([float(c) for c in w["closes"]], dtype=torch.float32) for w in chunk]
            quantiles, _mean = pipeline.predict_quantiles(
                series, prediction_length=max(1, horizon_bars), quantile_levels=[0.1, 0.5, 0.9],
            )
            for w, q in zip(chunk, quantiles):
                last = q[0][-1].tolist()
                results[w["key"]] = {"p10": float(last[0]), "p50": float(last[1]), "p90": float(last[2])}
        return {"status": "OK", "reason": f"elapsed_s={time.time() - t0:.1f}", "revision": MODEL_REVISION, "results": results}
    except Exception as exc:  # noqa: BLE001
        return {"status": "MODEL_UNAVAILABLE", "reason": f"INFERENCE_ERROR: {exc}", "revision": MODEL_REVISION, "results": {}}
