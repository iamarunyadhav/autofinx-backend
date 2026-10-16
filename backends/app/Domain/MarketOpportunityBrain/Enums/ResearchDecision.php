<?php

namespace App\Domain\MarketOpportunityBrain\Enums;

/**
 * Research-only decision verdicts (MASTER_SPEC.md Layer G, docs/market-opportunity-brain-v4/MASTER_SPEC.md:76).
 *
 * These are NEVER executable order instructions. There is deliberately no `BUY`/`SELL` case:
 * the whole point of this enum is that it cannot be mistaken for one. See
 * App\Domain\MarketOpportunityBrain\Data\DecisionRecord, which hard-wires
 * `execution_authorized = false` and `mode = RESEARCH_ONLY` regardless of input.
 */
enum ResearchDecision: string
{
    case LONG_CANDIDATE = 'LONG_CANDIDATE';
    case SHORT_CANDIDATE = 'SHORT_CANDIDATE';
    case WAIT = 'WAIT';
    case REJECT = 'REJECT';
}
