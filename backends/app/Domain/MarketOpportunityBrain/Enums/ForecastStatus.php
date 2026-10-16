<?php

namespace App\Domain\MarketOpportunityBrain\Enums;

/**
 * Forecast adapter output status (MASTER_SPEC.md Layer F, docs/market-opportunity-brain-v4/MASTER_SPEC.md:68).
 * A model that is offline/incompatible/absent must report MODEL_UNAVAILABLE and let the rest of
 * the app keep functioning -- never silently fall back to a live signal.
 */
enum ForecastStatus: string
{
    case OK = 'OK';
    case UNAVAILABLE = 'UNAVAILABLE';
    case MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';
    case STALE = 'STALE';
}
