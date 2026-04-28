<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotDecision;

class StrategySignal
{
    public function __construct(
        public readonly string $decision,
        public readonly string $reason,
        public readonly array $metadata = [],
        public readonly ?float $anchorPrice = null,
        public readonly ?float $computedRatio = null,
        public readonly ?float $thresholdUsed = null,
        public readonly ?RatioBotDecision $decisionRecord = null,
    ) {
    }

    public static function hold(string $reason, array $metadata = []): self
    {
        return new self(RatioBotDecision::DECISION_HOLD, $reason, $metadata);
    }

    public static function block(string $reason, array $metadata = []): self
    {
        return new self(RatioBotDecision::DECISION_BLOCK, $reason, $metadata);
    }

    public function isTrade(): bool
    {
        return in_array($this->decision, [
            RatioBotDecision::DECISION_BUY,
            RatioBotDecision::DECISION_SELL,
            RatioBotDecision::DECISION_LONG,
            RatioBotDecision::DECISION_SHORT,
            RatioBotDecision::DECISION_EXIT_LONG,
            RatioBotDecision::DECISION_EXIT_SHORT,
        ], true);
    }
}
