from __future__ import annotations

import json
import logging
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

from .config import Settings
from .providers import LightGBMProvider, PredictionRequest

logger = logging.getLogger(__name__)


def provider_for(settings: Settings) -> LightGBMProvider:
    if settings.provider != "lightgbm":
        raise ValueError(f"unsupported provider: {settings.provider}")
    return LightGBMProvider(settings.model_path, settings.model_dir)


def make_handler(settings: Settings, provider: LightGBMProvider):
    class Handler(BaseHTTPRequestHandler):
        def _write(self, status: int, payload: dict[str, Any]) -> None:
            body = json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self) -> None:  # noqa: N802
            if self.path == "/health":
                self._write(200, {"service": "external-predictive-engine", "version": "0.1.0", "provider": provider.health(), "influence": False, "execution": False})
                return
            self._write(404, {"error": "not_found"})

        def do_POST(self) -> None:  # noqa: N802
            if self.path != "/v1/predict":
                self._write(404, {"error": "not_found"})
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                body = json.loads(self.rfile.read(length))
                request = PredictionRequest(
                    symbol=str(body["symbol"]),
                    horizon_seconds=int(body["horizon_seconds"]),
                    market_timestamp=str(body["market_timestamp"]),
                    features={str(k): float(v) for k, v in body.get("features", {}).items()},
                    timeframe=str(body.get("timeframe", "")),
                )
                result = provider.predict(request)
                self._write(200, {"prediction": result.as_dict()})
            except (KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
                self._write(400, {"error": "invalid_request", "reason": str(exc)})
            except Exception:
                logger.exception("prediction request failed")
                self._write(500, {"error": "prediction_failed"})

        def log_message(self, fmt: str, *args: Any) -> None:
            logger.info("%s - %s", self.address_string(), fmt % args)

    return Handler


def serve(settings: Settings) -> None:
    provider = provider_for(settings)
    server = ThreadingHTTPServer((settings.host, settings.port), make_handler(settings, provider))
    logger.info("listening on %s:%s provider=%s", settings.host, settings.port, settings.provider)
    server.serve_forever()
