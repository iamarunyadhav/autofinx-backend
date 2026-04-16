<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\SentimentSignalProvider;
use Illuminate\Support\Carbon;

class NullSentimentSignalProvider implements SentimentSignalProvider
{
    public function name(): string
    {
        return 'null_sentiment';
    }

    public function reliabilityScore(): float
    {
        return 0.0;
    }

    public function fetchForSymbol(string $symbol, array $options = []): array
    {
        return [
            'provider' => $this->name(),
            'reliability_score' => $this->reliabilityScore(),
            'symbol' => strtoupper($symbol),
            'overall_sentiment' => 'unknown',
            'sentiment_score' => 0.0,
            'social_volume' => null,
            'fetched_at' => Carbon::now()->toIso8601String(),
            'freshness_state' => 'unknown',
            'sources_breakdown' => [],
            'warning' => 'Null sentiment provider — no data available. Do not use sentiment as a trade signal.',
        ];
    }
}
