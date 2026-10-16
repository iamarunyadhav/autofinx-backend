<?php

namespace App\Domain\MarketOpportunityBrain\Contracts;

use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;

/**
 * Point-in-time symbol universe (MASTER_SPEC.md Layer D §46). Implementations MUST only return
 * symbols that were actually listed/tradable as of the given timestamp -- never the current
 * live universe projected backward, and never a symbol whose `known_at` would be after `asOfUtc`.
 */
interface MarketUniverseProvider
{
    /**
     * @return iterable<DiscoveryRecord>
     */
    public function universeAsOf(\DateTimeImmutable $asOfUtc): iterable;
}
