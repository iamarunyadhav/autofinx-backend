"""Smoke and causality tests for the v10.1 research adapter."""
import numpy as np
from v4 import strategy_v1 as v1

def test_adapter_declares_research_only_translation():
    assert "research" in v1.TRANSLATION_STATUS

def test_v10_short_gate_removes_long_signals():
    bars = {
        "open": np.array([100., 99., 98., 97., 96., 95.]),
        "high": np.array([101., 100., 99., 98., 97., 96.]),
        "low": np.array([99., 98., 97., 96., 95., 94.]),
        "close": np.array([99.5, 98.5, 97.5, 96.5, 95.5, 94.5]),
        "volume": np.ones(6),
    }
    ind = {"setup_long": np.ones(6, dtype=bool), "setup_short": np.ones(6, dtype=bool),
           "htf3": np.full(6, -1.)}
    out = v1._v10_gates(ind, bars, "BTCUSDT")
    assert not out["setup_long"].any()

