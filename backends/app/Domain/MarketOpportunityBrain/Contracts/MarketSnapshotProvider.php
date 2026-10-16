<?php

namespace App\Domain\MarketOpportunityBrain\Contracts;

/**
 * Read-only historical candle access (MASTER_SPEC.md Layer D §44). Never exposes order
 * placement, account data or write access of any kind -- read-only by construction.
 */
interface MarketSnapshotProvider
{
    /**
     * Only CLOSED candles with close_time <= asOfUtc may be returned -- callers must never
     * receive an in-progress bar labelled as confirmed.
     *
     * @return list<\App\Domain\MarketOpportunityBrain\Data\Candle>
     */
    public function closedCandles(string $symbol, string $timeframe, \DateTimeImmutable $asOfUtc, int $limit): array;
}
