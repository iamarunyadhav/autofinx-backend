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

Training requires a real, strictly ordered closed-candle CSV. It uses a
chronological 80/20 split and writes a model plus a versioned metadata file:

```bash
python train_lightgbm.py --input data/BTCUSDT-5m.csv --output models/btcusdt-5m-v1.txt --horizon 12
```

No data file means no training; the pipeline does not generate synthetic market
data.
