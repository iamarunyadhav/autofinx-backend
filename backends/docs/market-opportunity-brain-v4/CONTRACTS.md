# CONTRACTS.md — Market Opportunity Brain data contracts

This module has exactly four versioned JSON contracts, each with a canonical example file already
checked in at `docs/market-opportunity-brain-v4/contracts/*.example.json` (written by the owner's
spec session before this implementation session began) and a PHP + Python implementation that
round-trips the example byte-for-byte (verified by automated tests — see TEST_EVIDENCE.md §2, §4).

Every contract carries `"execution_authorized": false` as a literal, non-overridable field. No
contract defined here, now or in any future phase of this module, may add `quantity`, `leverage`,
`api_key`, `order_type`, `order_id`, or `exchange_order_id` — this is enforced at the PHP/Python DTO
constructor level (`DecisionRecord`/`DecisionRecord` reject those fields outright), not just by
convention.

| Schema | Example file | PHP DTO | Python DTO | TS type |
|---|---|---|---|---|
| `mob.discovery.v1` | `contracts/discovery.v1.example.json` | `App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord` | `market_opportunity_brain.contracts.DiscoveryRecord` | `MobDiscoveryRecord` |
| `mob.forecast.v1` | `contracts/forecast.v1.example.json` | `App\Domain\MarketOpportunityBrain\Data\ForecastRecord` | `market_opportunity_brain.contracts.ForecastRecord` | `MobForecastRecord` |
| `mob.decision.v1` | `contracts/decision.v1.example.json` | `App\Domain\MarketOpportunityBrain\Data\DecisionRecord` | `market_opportunity_brain.contracts.DecisionRecord` | `MobDecisionRecord` |
| `mob.experiment.v1` | `contracts/experiment.v1.example.json` | `App\Domain\MarketOpportunityBrain\Data\ExperimentRecord` | `market_opportunity_brain.contracts.ExperimentRecord` | `MobExperimentRecord` |

No `outcome.v1` contract exists yet: the owner's spec session did not provide an example contract
for outcome/label records (Layer H's `outcome_labels` table), so none was fabricated here. That is
Phase 4 work, once the label-maturation design is concrete enough to write a real example against.

The TS types (`autofinx-front/frontends/src/types/marketOpportunityBrain.ts`) are **not imported by
any page, hook, or route yet** — they exist purely so a human/agent can see the exact shape a
future API client will need to parse, without any live wiring risk in this phase.
