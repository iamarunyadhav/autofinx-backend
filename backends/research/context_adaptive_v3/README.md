# Context Adaptive Research V3 backtest

This isolated runner ports the supplied Pine v6 research rules to local Binance
USD-M Futures candles. It does not connect to, configure, or execute any live
AutoFinX trading path.

Run from the backend repository:

```powershell
python research/context_adaptive_v3/backtest.py
```

The default input is `D:\autofinx-ml-v4\data`, and outputs are written below
`research/context_adaptive_v3/results`. The input archive is expected to contain
BTCUSDT 5-minute monthly Parquet files.

Important parity limits: TradingView compilation is not available locally;
strategy fills use a documented conservative emulator; the default-disabled
session module stays neutral; and all signals use closed candles.
