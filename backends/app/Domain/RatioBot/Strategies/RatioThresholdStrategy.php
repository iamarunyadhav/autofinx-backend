<?php

namespace App\Domain\RatioBot\Strategies;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Models\RatioBotConfig;

class RatioThresholdStrategy implements StrategyInterface
{
    public function __construct(
        private readonly RatioBotDecisionService $decisionService,
    ) {
    }

    public function key(): string
    {
        return 'ratio_threshold';
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): StrategySignal
    {
        $result = $this->decisionService->evaluate($config, $currentPrice, $context);
        $decisionRecord = $result['decision_record'] ?? null;

        return new StrategySignal(
            decision: (string) ($result['decision'] ?? 'HOLD'),
            reason: (string) ($result['reason'] ?? 'ratio_strategy_noop'),
            metadata: [
                'strategy' => $config->algorithmKey(),
                'ratio' => $result['ratio'] ?? null,
            ],
            anchorPrice: isset($result['anchor']) ? (float) $result['anchor'] : null,
            computedRatio: isset($result['ratio']) ? (float) $result['ratio'] : null,
            thresholdUsed: $decisionRecord?->threshold_used !== null ? (float) $decisionRecord->threshold_used : null,
            decisionRecord: $decisionRecord,
        );
    }
}
