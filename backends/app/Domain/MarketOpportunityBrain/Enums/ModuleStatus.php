<?php

namespace App\Domain\MarketOpportunityBrain\Enums;

/**
 * Module/pipeline status values (docs/market-opportunity-brain-v4/CONFIG_PROPOSAL.md:25).
 * Availability is not equivalent to profitability -- none of these values imply an edge exists.
 */
enum ModuleStatus: string
{
    case DISABLED = 'DISABLED';
    case OFFLINE_MOCK = 'OFFLINE_MOCK';
    case WAITING_FOR_DATA = 'WAITING_FOR_DATA';
    case SCANNING = 'SCANNING';
    case FORECASTING = 'FORECASTING';
    case DECIDING = 'DECIDING';
    case EVALUATING = 'EVALUATING';
    case DEGRADED = 'DEGRADED';
    case BLOCKED = 'BLOCKED';
}
