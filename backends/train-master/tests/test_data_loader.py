from __future__ import annotations

from pathlib import Path

from src.data_loader import TradeDataLoader


def test_import_trade_export(tmp_path: Path) -> None:
    input_file = tmp_path / "trades.csv"
    input_file.write_text(
        "symbol,side,entry_time,exit_time,entry_price,exit_price,quantity,realized_pnl,fees,confidence,active_minimum\n"
        "BTCUSDT,BUY,2026-01-01T00:00:00Z,2026-01-01T00:10:00Z,100,101,1,1.2,0.1,0.7,0.5\n",
        encoding="utf-8",
    )

    loader = TradeDataLoader()
    frame, summary = loader.import_trade_export(input_file)

    assert len(frame) == 1
    assert "net_pnl" in frame.columns
    assert summary["row_count"] == 1
