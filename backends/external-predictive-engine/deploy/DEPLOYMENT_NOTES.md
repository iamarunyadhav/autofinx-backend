# External Predictor Deployment Notes

This is a final-deployment template, not an instruction to run during local
development. The service must remain localhost-only and shadow-only.

Required server preparation:

1. Deploy `external-predictive-engine/` inside the backend repository at
   `autofinx-backend/backends/external-predictive-engine/`. It remains outside
   Laravel's `public/` directory and is therefore not directly web-served.
2. Create the virtual environment and install `requirements.txt`.
3. Create the `storage/` directory and grant it to the service user.
4. Install the Supervisor example as `autofinx-predictor.conf`.
5. Keep `AUTOFINX_FORECAST_EXTERNAL_ML_ENABLED=false` until a real model file
   has been trained and validated.
6. After a model is available, set only the model path and enable shadow output;
   keep influence and execution false.
7. Verify `/health` from the server itself before enabling the Laravel provider.

No Binance API key belongs in this service. Market data is supplied by the
existing AutoFinX feed/bridge.
