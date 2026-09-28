from __future__ import annotations

import json
import sys
from pathlib import Path

import pandas as pd
import streamlit as st

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from src.config import settings
from src.data_loader import TradeDataLoader
from src.predictor import TradeQualityPredictor
from src.utils import load_json


st.set_page_config(page_title="Train Master Dashboard", layout="wide")
st.title("Train Master Dashboard")
st.caption("Independent AutoFinX ML training and recommendation workspace")


def _compute_summary(frame: pd.DataFrame) -> dict[str, float]:
    wins = frame[frame["net_pnl"] > 0]["net_pnl"]
    losses = frame[frame["net_pnl"] < 0]["net_pnl"]
    gross_profit = wins.sum()
    gross_loss = abs(losses.sum())
    return {
        "total_trades": int(len(frame)),
        "win_rate": float((frame["net_pnl"] > 0).mean()) if len(frame) else 0.0,
        "net_pnl": float(frame["net_pnl"].sum()) if len(frame) else 0.0,
        "average_win": float(wins.mean()) if not wins.empty else 0.0,
        "average_loss": float(losses.mean()) if not losses.empty else 0.0,
        "profit_factor": float(gross_profit / gross_loss) if gross_loss else 0.0,
        "recent_win_rate": float((frame["net_pnl"].tail(20) > 0).mean()) if len(frame) else 0.0,
        "loss_streak": int((frame["net_pnl"].tail(10) < 0).sum()) if len(frame) else 0,
    }


loader = TradeDataLoader()

uploaded_file = st.file_uploader("Upload trade export", type=["csv", "xls", "xlsx"])
processed_files = sorted(settings.processed_dir.glob("*_processed.csv"))

frame = None
selected_label = "None"
if uploaded_file is not None:
    temp_path = settings.raw_dir / "exports" / uploaded_file.name
    temp_path.write_bytes(uploaded_file.read())
    frame, summary = loader.import_trade_export(temp_path)
    selected_label = uploaded_file.name
    st.success(f"Imported {summary['row_count']} rows from {uploaded_file.name}")
elif processed_files:
    selected_file = st.selectbox("Processed dataset", [file.name for file in processed_files])
    selected_label = selected_file
    frame = loader.load_processed_trades(settings.processed_dir / selected_file)

st.subheader("Dataset Summary")
st.write(f"Uploaded trade export file: `{selected_label}`")

if frame is not None and not frame.empty:
    summary = _compute_summary(frame)
    col1, col2, col3, col4 = st.columns(4)
    col1.metric("Total Trades", summary["total_trades"])
    col2.metric("Win Rate", f"{summary['win_rate']:.2%}")
    col3.metric("Net PnL", f"{summary['net_pnl']:.2f}")
    col4.metric("Profit Factor", f"{summary['profit_factor']:.2f}")

    col5, col6, col7, col8 = st.columns(4)
    col5.metric("Average Win", f"{summary['average_win']:.2f}")
    col6.metric("Average Loss", f"{summary['average_loss']:.2f}")
    col7.metric("Recent Win Rate", f"{summary['recent_win_rate']:.2%}")
    col8.metric("Loss Streak", summary["loss_streak"])

    st.dataframe(frame.head(20), use_container_width=True)
else:
    st.info("Upload a trade export or choose a processed dataset to view metrics.")

st.subheader("Model Metrics")
report = load_json(settings.reports_dir / "evaluation_report.json", default={})
if report:
    c1, c2, c3, c4 = st.columns(4)
    c1.metric("Model Accuracy", f"{report.get('accuracy', 0):.2%}")
    c2.metric("Precision", f"{report.get('precision', 0):.2%}")
    c3.metric("Recall", f"{report.get('recall', 0):.2%}")
    c4.metric("F1 Score", f"{report.get('f1_score', 0):.2%}")
    st.write("Feature Importance")
    importance_df = pd.DataFrame(report.get("feature_importance", []))
    if not importance_df.empty:
        st.bar_chart(importance_df.set_index("feature")["importance"])
else:
    st.info("No evaluation report found yet. Train a model first.")

st.subheader("Model Recommendation Tester")
default_payload = {
    "symbol": "BTCUSDT",
    "side": "SELL",
    "timeframe": "5m",
    "confidence": 0.58,
    "active_minimum": 0.52,
    "vwap_distance_percent": -0.15,
    "ema_alignment_score": 0.8,
    "adx_value": 22,
    "macd_direction": "bearish",
    "rsi_value": 48,
    "volume_score": 0.6,
    "atr_percent": 0.8,
    "volatility_percent": 1.2,
    "recent_win_rate_20": 0.55,
    "recent_loss_streak": 1,
}
payload_text = st.text_area("Prediction payload JSON", json.dumps(default_payload, indent=2), height=260)

if st.button("Run Recommendation"):
    try:
        predictor = TradeQualityPredictor()
        result = predictor.predict(json.loads(payload_text))
        st.json(result.__dict__)
    except Exception as exc:
        st.error(f"Prediction failed: {exc}")
