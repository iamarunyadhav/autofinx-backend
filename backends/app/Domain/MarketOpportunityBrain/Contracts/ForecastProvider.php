<?php

namespace App\Domain\MarketOpportunityBrain\Contracts;

use App\Domain\MarketOpportunityBrain\Data\Candle;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;

/**
 * Version-pinned forecast model adapter (MASTER_SPEC.md Layer F §60). `predict()` must be a pure
 * function of its inputs: no network calls or live state inside the adapter at this layer; model
 * loading/caching/latency concerns belong to the registry that wires a concrete adapter.
 *
 * Implementations MUST NOT derive a LONG/SHORT verdict here -- only quantiles/probabilities and an
 * explicit status. Never return a result claiming OK when inputs are insufficient.
 */
interface ForecastProvider
{
    /**
     * @param  list<Candle>  $closedCandles  strictly as-of $asOfUtc, oldest first
     */
    public function predict(
        string $workspaceId,
        string $symbol,
        string $timeframe,
        int $horizonBars,
        array $closedCandles,
        \DateTimeImmutable $asOfUtc,
    ): ForecastRecord;
}
