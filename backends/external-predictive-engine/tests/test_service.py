import json
import sys
from pathlib import Path
from urllib.request import Request, urlopen

sys.path.insert(0, str(Path(__file__).parents[1]))

from external_predictive_engine.config import Settings
from external_predictive_engine.http_service import make_handler
from external_predictive_engine.providers import LightGBMProvider
from http.server import ThreadingHTTPServer
import threading


def test_unconfigured_provider_is_safe_and_unavailable():
    result = LightGBMProvider().predict(__import__("external_predictive_engine.providers", fromlist=["PredictionRequest"]).PredictionRequest("BTCUSDT", 900, "2026-09-28T00:00:00Z", {"rsi": 50.0}))
    assert result.status == "unavailable"
    assert result.as_dict()["influence"] is False
    assert result.as_dict()["execution"] is False


def test_health_and_prediction_contract():
    settings = Settings(port=0)
    provider = LightGBMProvider()
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(settings, provider))
    threading.Thread(target=server.handle_request, daemon=True).start()
    with urlopen(f"http://127.0.0.1:{server.server_port}/health") as response:
        payload = json.load(response)
    assert payload["provider"]["status"] == "unavailable"
    assert payload["execution"] is False
    server.server_close()
