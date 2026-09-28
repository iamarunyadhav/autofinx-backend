# AutoFinX Train Master

AutoFinX Train Master is an independent Python machine learning module for analyzing historical trade performance and producing trade-quality recommendations. It does not execute trades and does not modify the existing AutoFinX bot, Laravel backend, dashboard, routes, queue workers, scheduler, or live trading logic.

## What It Does

Train Master ingests historical trade exports and optional candle-derived indicators, builds features, trains explainable models, evaluates their behavior, and exposes a prediction API plus a standalone dashboard.

Current outputs are recommendation-only:

- `TRADE`
- `TRADE_SMALL_SIZE`
- `WAIT`
- `HIGH_RISK_WAIT`

Each prediction also includes:

- quality score
- win probability
- risk level
- position size multiplier
- reason summary

## Folder Layout

```txt
train-master/
  README.md
  requirements.txt
  .env.example
  data/
  src/
  scripts/
  notebooks/
  tests/
  ui/
```

## Installation

From the project root:

```bash
cd train-master
python -m venv venv
```

Linux/macOS:

```bash
source venv/bin/activate
pip install -r requirements.txt
```

Windows:

```bash
venv\Scripts\activate
pip install -r requirements.txt
```

## Environment

Copy `.env.example` to `.env` if you want to override defaults:

```bash
cp .env.example .env
```

Key settings:

- `TRAIN_MASTER_HOST`
- `TRAIN_MASTER_PORT`
- `TRAIN_MASTER_MODEL_NAME`
- `TRAIN_MASTER_RANDOM_STATE`
- `TRAIN_MASTER_MINIMUM_PROFIT_THRESHOLD`
- `TRAIN_MASTER_NEGATIVE_THRESHOLD`

## Import Trade Export

Supported inputs:

- `future-trade-export-*.csv`
- `future-trade-export-*.xls`
- `future-trade-export-*.xlsx`

Example:

```bash
python scripts/import_trade_export.py --input data/raw/exports/future-trade-export.xlsx
```

This normalizes trade history and saves:

- processed dataset CSV
- import summary JSON

## Train Model

Example:

```bash
python scripts/train_model.py --input data/raw/exports/future-trade-export.xlsx
```

Training flow:

1. Load trade export
2. Clean and normalize fields
3. Build trade-only or trade-plus-indicator features
4. Build labels
5. Train candidate models
6. Select best model by validation F1
7. Save model artifacts and evaluation files

Artifacts:

- `data/models/trade_quality_model.joblib`
- `data/models/feature_columns.json`
- `data/models/model_metadata.json`
- `data/reports/evaluation_report.json`
- `data/reports/evaluation_summary.md`

## Evaluate Model

To regenerate evaluation summaries from saved artifacts:

```bash
python scripts/evaluate_model.py
```

Reported metrics include:

- accuracy
- precision
- recall
- f1 score
- confusion matrix
- win prediction accuracy
- loss avoidance accuracy
- feature importance
- average PnL when model says trade
- average PnL when model says wait
- false positive loss count
- false negative missed win count

Important: a high classification accuracy does not guarantee trading profitability.

## Run Prediction API

Start the FastAPI server:

```bash
python scripts/run_api.py
```

Default endpoint:

```txt
POST http://127.0.0.1:8010/predict-trade-quality
```

Example payload:

```json
{
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
  "recent_loss_streak": 1
}
```

## Run Dashboard

The Streamlit dashboard is fully standalone:

```bash
streamlit run ui/app.py
```

It shows:

- uploaded trade export file
- total trades
- win rate
- net pnl
- average win
- average loss
- profit factor
- recent win rate
- loss streak
- model accuracy
- feature importance
- model recommendation tester

## Future Integration Plan

Later, the Laravel bot can call:

```txt
POST http://127.0.0.1:8010/predict-trade-quality
```

Suggested future decision flow:

```txt
normal strategy signal
-> risk checks
-> Train Master ML quality score
-> if ML says TRADE, allow
-> if ML says TRADE_SMALL_SIZE, reduce size
-> if ML says WAIT, skip
```

That integration is not implemented here. Train Master is isolated on purpose.

## Important Warning

Train Master does not guarantee profit. It is a historical pattern analysis system and should be treated as a recommendation engine, not an autonomous trading authority.

## Commands

Linux/macOS:

```bash
cd train-master
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python scripts/import_trade_export.py --input data/raw/exports/future-trade-export.xlsx
python scripts/train_model.py --input data/raw/exports/future-trade-export.xlsx
python scripts/evaluate_model.py
python scripts/run_api.py
streamlit run ui/app.py
```

Windows:

```bash
cd train-master
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
python scripts/import_trade_export.py --input data/raw/exports/future-trade-export.xlsx
python scripts/train_model.py --input data/raw/exports/future-trade-export.xlsx
python scripts/evaluate_model.py
python scripts/run_api.py
streamlit run ui/app.py
```
