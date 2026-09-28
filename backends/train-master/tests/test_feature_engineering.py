from __future__ import annotations

import pandas as pd

from src.feature_engineering import build_feature_frame


def test_build_feature_frame_trade_only() -> None:
    frame = pd.DataFrame(
        [
            {
                "symbol": "BTCUSDT",
                "side": "BUY",
                "timeframe": "5m",
                "entry_time": "2026-01-01T00:00:00Z",
                "exit_time": "2026-01-01T00:10:00Z",
                "entry_price": 100.0,
                "exit_price": 101.0,
                "quantity": 1.0,
                "net_pnl": 0.8,
                "confidence": 0.7,
                "active_minimum": 0.5,
                "skip_reason": "",
                "status": "closed",
            },
            {
                "symbol": "ETHUSDT",
                "side": "SELL",
                "timeframe": "5m",
                "entry_time": "2026-01-01T00:20:00Z",
                "exit_time": "2026-01-01T00:30:00Z",
                "entry_price": 200.0,
                "exit_price": 198.0,
                "quantity": 1.0,
                "net_pnl": 1.1,
                "confidence": 0.6,
                "active_minimum": 0.5,
                "skip_reason": "",
                "status": "closed",
            },
        ]
    )

    bundle = build_feature_frame(frame)
    assert "confidence_gap" in bundle.frame.columns
    assert "signal_quality" in bundle.frame.columns
    assert len(bundle.feature_columns) > 5
