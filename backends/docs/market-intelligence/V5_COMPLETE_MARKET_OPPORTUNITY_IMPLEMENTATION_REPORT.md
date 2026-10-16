# V5 Complete Market Opportunity Implementation Report

## Implemented in this increment

- Added isolated `v5_research_selections` research table and `V5ResearchSelection` model.
- Added authenticated read-only selection listing.
- Added authenticated manual selection creation with LONG/SHORT validation and a hard maximum of three active selections.
- Added explicit user removal, preserving the original record as `REMOVED_BY_USER`.
- Every selection response remains `execution_authorized=false`.
- Existing dynamic scanner Top-3 remains independent; it does not delete or replace selections.
- Added API regression coverage for authentication, persistence, user isolation, and selection behavior.

## Verification

```text
php artisan test tests/Feature/MarketIntelligence/V5ResearchApiTest.php --compact
4 passed, 12 assertions
```

Existing V5 scanner, refresh, shadow-outcome, and MarketOpportunityBrain isolation tests remain unchanged and are intended to run in the full regression gate.

## Still intentionally research-only

The dynamic ranking remains heuristic and uncalibrated. No validated expected return, profitability, funding/OI history, or automatic trade permission is introduced. Selection metadata supports later research-only ROI tracking but does not represent realized P&L. Production scheduler, wallet, risk, webhook, and order execution paths remain untouched.

The migration is pending and must not be applied to production without explicit approval. The next safe integration step is wiring these endpoints into the existing protected dashboard and adding fixture-based research metrics; no live-trading integration is permitted.
