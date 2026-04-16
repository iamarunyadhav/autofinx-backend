<?php

namespace App\Domain\Intelligence\Contracts;

/**
 * Sentiment Signal Provider contract.
 *
 * ⚠️  LOW-TRUST SOURCE — READ BEFORE IMPLEMENTING:
 *
 * Sentiment signals from social media, forums, or influencer aggregators are
 * inherently noisy, gameable, and should NOT directly influence order sizing.
 *
 * Allowed uses:
 *  - Soft confidence adjustment (±5–10 pp at most)
 *  - Caution overlay when extreme greed/fear is detected
 *  - Informational display in the operator dashboard
 *
 * Prohibited uses:
 *  - Triggering BUY/SELL decisions directly from sentiment scores
 *  - Bypassing risk/policy gates based on sentiment
 *  - Presenting social sentiment as a reliable predictive signal
 *
 * Every result must carry a reliability_score so the AI context builder
 * can weight it appropriately and downstream consumers can cap its influence.
 */
interface SentimentSignalProvider
{
    public function name(): string;

    /**
     * Reliability score for this provider (0.0 = untrusted, 1.0 = fully trusted).
     * Most social/influencer providers should be <= 0.3.
     */
    public function reliabilityScore(): float;

    /**
     * Fetch sentiment signals for the given symbol.
     *
     * @param  string  $symbol  Normalized symbol, e.g. BTCUSDT
     * @param  array<string, mixed>  $options
     * @return array{
     *   provider: string,
     *   reliability_score: float,
     *   symbol: string,
     *   overall_sentiment: 'bullish'|'bearish'|'neutral'|'unknown',
     *   sentiment_score: float,
     *   social_volume: int|null,
     *   fetched_at: string,
     *   freshness_state: 'fresh'|'stale'|'unknown',
     *   sources_breakdown: array<string, mixed>,
     *   warning: string
     * }
     */
    public function fetchForSymbol(string $symbol, array $options = []): array;
}
