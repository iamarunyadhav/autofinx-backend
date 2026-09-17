# Ratio Bot execution-cost reconciliation

## Deployment

The migration `2026_09_18_120000_create_ratio_bot_execution_cost_records.php`
requires human approval before application to a real database. No production
migration, cron change, service restart, or real trade was performed by this change.

After approving/applying that migration and deploying backend/frontend code:

```sh
php artisan ratio-bots:reconcile-costs --bot=BOT_UUID
```

The existing Laravel schedule registers `ratio-bots:reconcile-costs --limit=20`
every ten minutes under the existing Ratio Bot scheduler flag. Confirm that the
production scheduler actually runs; Supervisor-only setups need an equivalent
operator-managed invocation. Oldest-checked bots run first. Disabled bots with
live orders remain eligible so a close does not stop delayed-fee retries.

## Accounting contract

- This is a separate, read-only exchange accounting projection. It never places
  or closes orders, rebooks wallet entries, or updates recovery/threshold logic.
- Spot commission comes from order-filtered `myTrades`; futures evidence comes
  from `userTrades`, `income(FUNDING_FEE)`, and stable `positionRisk` snapshots.
- Exchange fill quantity/price drives weighted fill prices. Reference-vs-fill
  divergence is displayed separately and is **not** subtracted from PnL again.
- Commission is retained in its charged currency. Quote fees are direct; spot
  base fees are valued at the fill price and consume base inventory. BNB/other
  third-token fees without an evidenced conversion remain partial.
- Spot PnL uses weighted-average actual-fill cost basis, including base-fee
  inventory consumption. Untracked starting inventory cannot produce verified
  PnL. Futures PnL uses exchange-reported realized PnL.
- Positive funding income is a credit (negative cost); negative income is a
  charge. No fixed eight-hour funding schedule or current-rate estimate is used
  in this new ledger. The old estimated tracker remains separate and unchanged.
- Futures funding allocation requires a complete one-way position history with
  a proven flat starting point and exclusive ownership. Normal and recovery
  orders use their stored bot metadata; historical stop IDs come from bot events.
  Unknown reductions are attributed only when this entire position is proven
  exclusively owned. Unknown openings, shared/hedged positions, same-millisecond
  trade/funding boundaries and missing evidence remain explicitly unavailable.
- Funding remains in the durable ledger after a position closes. Per-bot source
  keys deduplicate exchange trade/income IDs. Retrying replaces the projection,
  rather than adding costs to prior totals. Account/environment changes cannot
  silently mix ledgers.
- Catch-up reads every missed seven-day window, then rereads a trailing seven
  days for delayed records. Cursors and evidence commit together; failed pages
  cannot advance coverage. Already collected old evidence survives API retention.
- Initial history gaps beyond Binance retention require a historical import;
  the service does not fabricate a zero. Arbitrarily late corrections outside
  the seven-day overlap are not automatically rediscovered.
- Runtime/UI distinguish known amounts, actual net PnL, source, timestamp, and
  unresolved reason. New order updates or a snapshot older than 30 minutes
  invalidate the displayed verified net until the next reconciliation.
- The runtime response shows at most 50 order/funding audit entries. Durable
  evidence is not truncated. Existing legacy net remains clearly labeled as
  excluding funding; the new actual net is a separate field.

## Explicit limits / verification still required

Shared-account funding attribution, hedge-leg allocation, third-token historical
FX conversion, and imported starting inventory are intentionally unresolved,
not claimed complete. Spot orders with >=1000 fills are flagged as truncated;
futures saturated time windows are split with a bounded request budget.
Large histories should be monitored for API weight, ledger growth, and runtime.

Automated tests use fake exchange responses and isolated SQLite migrations.
An operator must verify actual testnet entry/recovery/close and funding evidence
before relying on the production display. This change improves accounting, not
prediction accuracy or guaranteed profitability.

API contracts checked against Binance's official
[futures trade API](https://developers.binance.com/en/docs/catalog/core-trading-derivatives-trading-usd-s-m-futures/api/rest-api/trade)
and [income API](https://developers.binance.com/en/docs/catalog/core-trading-derivatives-trading-usd-s-m-futures/api/rest-api/account).
