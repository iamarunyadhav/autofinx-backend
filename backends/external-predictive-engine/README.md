# AutoFinX External Predictive Engine

Phase 2 isolated shadow-only service. It has no exchange credentials, order
client, trading decision authority, or execution capability.

## Run

```bash
python -m external_predictive_engine
```

The default HTTP endpoint is `http://127.0.0.1:8091/health`.

## Contract

- `GET /health` returns service/provider availability.
- `POST /v1/predict` accepts a timestamped feature payload.
- A provider without a loaded model returns `unavailable`; it never fabricates
  a prediction.
- `influence` and `execution` are hard-coded false in this phase.
- The market bridge is read-only and consumes AutoFinX's existing
  `market:live:{SYMBOL}` Redis snapshots. It does not open a Binance socket.

Install optional model dependencies from `requirements.txt` only inside the
isolated Python environment.

## Training

Training is permitted only from a sealed `prediction:fresh-dataset` JSONL
snapshot. The trainer independently rejects duplicate, misaligned, gapped, or
non-positive candles, then uses chronological 70/15/15 train/validation/holdout
evaluation. A model must beat the zero-return MAE baseline and clear 52%
holdout direction accuracy with at least 200 holdout rows before Laravel adds
it to the **shadow-only** fresh model registry.

```bash
python train_lightgbm.py --input fresh.jsonl --output candidate.txt --timeframe 5m --horizon 12
```

Candidate artifacts go under Laravel storage, not the predictor's configured
model directory. Registry membership never enables execution or trade influence.
Legacy model files remain test/archive material until a separate, explicitly
reviewed activation design exists.
