<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Carbon;

class ExternalContextScoringService
{
    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function score(array $events, Carbon $now, int $maxAgeMinutes): array
    {
        $flags = [];
        $highSeverity = [];
        $confidencePenalty = 0.0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $severity = strtoupper((string) ($event['severity'] ?? ExternalContextTaxonomy::SEVERITY_LOW));
            $impact = strtoupper((string) ($event['impact_flag'] ?? ExternalContextTaxonomy::IMPACT_IGNORE));
            $occurredAt = $event['occurred_at'] instanceof Carbon ? $event['occurred_at'] : null;

            $ageMinutes = $occurredAt ? max(0, $occurredAt->diffInMinutes($now)) : null;
            $decay = $this->freshnessDecay($ageMinutes, $maxAgeMinutes);

            $severityRank = ExternalContextTaxonomy::severityRank($severity);
            $basePenalty = match ($severityRank) {
                4 => 0.20,
                3 => 0.12,
                2 => 0.05,
                default => 0.02,
            };

            $confidencePenalty += $basePenalty * $decay;

            if ($severityRank >= 3 && $decay > 0) {
                $highSeverity[] = [
                    'category' => (string) ($event['category'] ?? ''),
                    'severity' => $severity,
                    'impact_flag' => $impact,
                    'title' => (string) ($event['title'] ?? ''),
                    'occurred_at' => $occurredAt?->toIso8601String(),
                    'source_url' => $event['source_url'] ?? null,
                ];
            }

            if ($impact !== ExternalContextTaxonomy::IMPACT_IGNORE && $decay > 0) {
                $flags[$impact] = true;
            }
        }

        $flagsList = array_keys(array_filter($flags));
        $recommendedBias = $this->recommendedBias($flagsList);
        $confidenceHint = -1 * min(0.35, round($confidencePenalty, 4));

        return [
            'event_flags' => $flagsList,
            'recommended_bias' => $recommendedBias,
            'confidence_adjustment_hint' => $confidenceHint,
            'no_trade_hint' => in_array(ExternalContextTaxonomy::IMPACT_NO_TRADE, $flagsList, true),
            'pause_review_hint' => in_array(ExternalContextTaxonomy::IMPACT_PAUSE_REVIEW, $flagsList, true),
            'high_severity_events' => array_slice($highSeverity, 0, 8),
        ];
    }

    private function recommendedBias(array $flags): string
    {
        if (in_array(ExternalContextTaxonomy::IMPACT_PAUSE_REVIEW, $flags, true)) {
            return 'pause_review';
        }
        if (in_array(ExternalContextTaxonomy::IMPACT_NO_TRADE, $flags, true)) {
            return 'no_trade';
        }
        if (in_array(ExternalContextTaxonomy::IMPACT_CAUTION, $flags, true) || in_array(ExternalContextTaxonomy::IMPACT_REDUCE_CONFIDENCE, $flags, true)) {
            return 'caution';
        }

        return 'neutral';
    }

    private function freshnessDecay(?int $ageMinutes, int $maxAgeMinutes): float
    {
        if ($ageMinutes === null) {
            return 0.5;
        }

        if ($maxAgeMinutes <= 0) {
            return 0.0;
        }

        $ratio = max(0.0, min(1.0, 1 - ($ageMinutes / $maxAgeMinutes)));

        return round(max(0.15, $ratio), 4);
    }
}

