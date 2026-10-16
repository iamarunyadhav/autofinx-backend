<?php

namespace App\Domain\MarketOpportunityBrain\Contracts;

use App\Domain\MarketOpportunityBrain\Data\DecisionRecord;
use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;

/**
 * Pure, deterministic, versioned decision policy (MASTER_SPEC.md Layer G). Contract only in this
 * phase -- no implementation yet; Phase 4 (docs/market-opportunity-brain-v4/PHASES_AND_TEST_GATES.md)
 * builds the concrete `ResearchDecisionPolicy` with real gates (freshness, calibration, cost, etc).
 *
 * Any implementation MUST return a DecisionRecord, which itself cannot carry order fields or a
 * true `execution_authorized` (enforced in the DTO, not just by convention here).
 */
interface DecisionPolicy
{
    public function decide(
        DiscoveryRecord $candidate,
        ForecastRecord $forecast,
        \DateTimeImmutable $decisionAsOfUtc,
    ): DecisionRecord;
}
