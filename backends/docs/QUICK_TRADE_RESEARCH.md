# Quick Trade net-cost display and research

## What changed

- Active Quick Trade displays estimated net PnL, entry commission, estimated exit commission, an exit-slippage buffer, known funding, and stake plus estimated net. This is not the exchange wallet balance. Unknown funding/other charges are explicitly excluded; final execution can differ.
- Actual entry slippage is already reflected in the entry fill and is not subtracted twice. Final history exports use booked settlement values and retain their real/partial/estimated/unavailable source.
- History has workspace-authorized, filtered, paginated JSON and CSV exports. Full JSON includes configuration, input candles, indicator snapshots, primary/shadow forecasts, cost evidence, timeline and outcome labels. Entry timestamps open a per-trade details table.
- Manual Quick Trade captures a server-side pre-entry forecast snapshot. This is a server recomputation at placement, not a claim to preserve the exact earlier forecast displayed in the browser. Robot snapshots are not added by this change.
- UnifiedForecastEngine is observational only. No strategy, wallet booking, automatic model training, or live promotion is changed.

## Deployment (human approval required)

1. Review and approve `2026_09_18_150000_create_quick_trade_research_samples.php` before applying it to a real database. Without it, reports work but intratrade samples are unavailable.
2. Deploy the backend and frontend. Ensure the existing Laravel scheduler is active with sub-minute scheduling, scheduler master switch enabled, and `QUICK_TRADE_RESEARCH_ENABLED=true`. The scheduled `quick-trades:sample-research` command runs every five seconds without overlap.
3. Ensure the existing production Binance futures cache feed is healthy. The sampler never calls the exchange or places orders. Verify new sample rows and the UI on an authorized workspace. Production deployment and live/testnet order verification are not performed by the local tests.
4. Keep `UNIFIED_FORECAST_QUICK_TRADE_SHADOW_ENABLED=true` for comparisons. Missing candles/calibration or abstention remain unknown, not a fabricated directional result.

## Dataset limitations

- Quotes are sampled, not every tick. Browser closure does not stop server collection, but a stopped scheduler/feed creates gaps. Old missing prices/forecasts cannot be reconstructed.
- The sampler uses the production futures feed only; testnet, paper and unknown environments are not substituted with production observations. Paper data is synthetic and excluded from live accuracy.
- Outcomes use the first recorded quote within ten seconds after the saved forecast horizon. Early exits do not replace the horizon price; sampling continues until the horizon window ends. Post-exit observations have no open-position gross PnL.
- Direction correctness and forecast price-band correctness are separate booleans. Missing evidence is null/Unknown, never false. A shadow direction forecast has no invented target price.
- The summary is page-local, with a minimum of 30 evaluated live rows. It is not proof of generalization or a guaranteed accuracy target. Full exports can be analyzed chronologically across pages.
- Pre-entry features and post-entry outcomes must remain separate to avoid training leakage. `training_eligible` is a basic evidence filter, not approval to train/deploy a profitable model.
- Export pagination freezes the settled-trade cutoff, not a transactional snapshot of later reconciliation updates. Large exports are accumulated in browser memory; there is no background export job or tick-retention policy in this change.
- No account secrets are included. Only whitelisted configuration is captured, not every platform setting. These files contain private trading activity; store them securely.
