<?php

namespace App\Domain\Intelligence\Contracts;

/**
 * Market Research Provider contract.
 *
 * Implementations fetch on-chain analytics, token metrics, liquidity analysis,
 * fundamental data, or third-party research reports for a given symbol.
 *
 * Usage rules:
 *  - Output is advisory only; it must never bypass policy or risk gates.
 *  - Stale data must be surfaced via freshness_state = 'stale'.
 *  - If the provider is unavailable, return an empty array rather than fabricating data.
 *  - All results must carry a 'source' and 'fetched_at' field.
 */
interface MarketResearchProvider
{
    public function name(): string;

    /**
     * Fetch research items for the given symbol.
     *
     * @param  string  $symbol  Normalized symbol, e.g. BTCUSDT
     * @param  array<string, mixed>  $options
     * @return array<int, array{
     *   source: string,
     *   symbol: string,
     *   title: string,
     *   summary: string,
     *   url: string|null,
     *   sentiment: 'positive'|'negative'|'neutral'|'unknown',
     *   confidence: float,
     *   fetched_at: string,
     *   freshness_state: 'fresh'|'stale'|'unknown'
     * }>
     */
    public function fetchForSymbol(string $symbol, array $options = []): array;
}
