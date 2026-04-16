<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Carbon;

class ExternalContextSummaryService
{
    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<string, mixed> $score
     * @return array<string, mixed>
     */
    public function summarize(array $events, array $score, Carbon $now, int $maxAgeMinutes): array
    {
        $news = [];
        $macro = [];
        $exchange = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $category = (string) ($event['category'] ?? '');
            $title = (string) ($event['title'] ?? '');
            $occurredAt = $event['occurred_at'] instanceof Carbon ? $event['occurred_at'] : null;
            $ageMinutes = $occurredAt ? $occurredAt->diffInMinutes($now) : null;

            if ($ageMinutes !== null && $ageMinutes > $maxAgeMinutes) {
                continue;
            }

            $line = $occurredAt
                ? sprintf('%s (%s)', $title, $occurredAt->toIso8601String())
                : $title;

            if ($category === ExternalContextTaxonomy::CATEGORY_CRYPTO_MARKET_NEWS) {
                $news[] = $line;
            } elseif ($category === ExternalContextTaxonomy::CATEGORY_MACRO_EVENT) {
                $macro[] = $line;
            } elseif ($category === ExternalContextTaxonomy::CATEGORY_EXCHANGE_INCIDENT) {
                $exchange[] = $line;
            }
        }

        return [
            'available' => true,
            'freshness_state' => 'fresh',
            'news_summary' => array_slice($news, 0, 6),
            'macro_summary' => array_slice($macro, 0, 6),
            'exchange_summary' => array_slice($exchange, 0, 6),
            'event_flags' => $score['event_flags'] ?? [],
            'high_severity_events' => $score['high_severity_events'] ?? [],
            'recommended_bias' => $score['recommended_bias'] ?? 'neutral',
            'confidence_adjustment_hint' => $score['confidence_adjustment_hint'] ?? 0.0,
            'no_trade_hint' => (bool) ($score['no_trade_hint'] ?? false),
            'pause_review_hint' => (bool) ($score['pause_review_hint'] ?? false),
        ];
    }
}

