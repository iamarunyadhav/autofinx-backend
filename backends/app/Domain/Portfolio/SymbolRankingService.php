<?php

namespace App\Domain\Portfolio;

class SymbolRankingService
{
    /**
     * @param array<int, array<string, mixed>> $opportunities
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $opportunities, array $config = []): array
    {
        usort($opportunities, function (array $left, array $right): int {
            $leftBlocked = (bool) ($left['blocked'] ?? false);
            $rightBlocked = (bool) ($right['blocked'] ?? false);

            if ($leftBlocked !== $rightBlocked) {
                return $leftBlocked ? 1 : -1;
            }

            $scoreComparison = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $priorityOrder = [
                'buy' => 0,
                'reduce' => 1,
                'hold' => 2,
                'no_trade' => 3,
            ];

            $leftPriority = $priorityOrder[(string) ($left['recommended_action'] ?? 'no_trade')] ?? 4;
            $rightPriority = $priorityOrder[(string) ($right['recommended_action'] ?? 'no_trade')] ?? 4;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcmp((string) ($left['symbol'] ?? ''), (string) ($right['symbol'] ?? ''));
        });

        foreach ($opportunities as $index => &$opportunity) {
            $opportunity['rank'] = $index + 1;
        }

        return $opportunities;
    }
}
