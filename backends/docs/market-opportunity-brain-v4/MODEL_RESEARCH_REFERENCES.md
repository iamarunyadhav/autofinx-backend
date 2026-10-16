# Optional model candidates — not installed and not validated for crypto profitability

- Amazon Chronos-2: https://huggingface.co/amazon/chronos-2; library https://github.com/amazon-science/chronos-forecasting. Official inference provides probabilistic quantiles for time-series forecasting, including multivariate/covariate scenarios. 120M parameters. Model card currently lists a large download; verify memory/device suitability. Model forecasts do **not** directly certify trade direction or expected net P&L.
- IBM Granite TinyTimeMixer R3: https://huggingface.co/ibm-granite/granite-timeseries-ttm-r3; model card describes multivariate, zero/few-shot support and efficient batch inference. Follow actual vendor-supported inference docs; model format/load API may differ from Chronos.
- Chronos Bolt: https://huggingface.co/amazon/chronos-bolt-base, optional alternative only after CPU/RAM profiling.
- Essential baselines: prior-close persistence, drift/random-walk, rolling volatility, existing validated rule-only Strategy Lab (where legally/evidentially comparable). Forecast accuracy vs naïve benchmark **and** net after-cost improvement are separate gates.

Version pinning: save model revision/commit, approved dependency hashes/lockfile, model hardware/runtime, data hash, preprocessing version, inference timestamp, latency, and calibration status for each prediction. Download weights to approved *research host* only. Never send private orders, account records, internal keys or raw user account data to model hubs.

Given prior D-046/D-050 no-profit findings, prioritize demonstrating incremental **point-in-time discovery**, **volatility/range calibration** and **WAIT quality** before interpreting direction scores as trade signals. No guaranteed profit.
