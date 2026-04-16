<?php

namespace App\Domain\Intelligence\Data;

class AiTradeProposal
{
    public function __construct(
        public readonly string $action,
        public readonly string $symbol,
        public readonly float $confidence,
        public readonly string $reasoningSummary,
        public readonly float $sizePct,
        public readonly float $stopLossPct,
        public readonly float $takeProfitPct,
        public readonly int $timeStopMinutes,
        public readonly bool $requiresHumanApproval,
        public readonly array $warnings = [],
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            action: strtoupper((string) ($payload['action'] ?? 'NO_TRADE')),
            symbol: strtoupper((string) ($payload['symbol'] ?? '')),
            confidence: (float) ($payload['confidence'] ?? 0),
            reasoningSummary: trim((string) ($payload['reasoning_summary'] ?? '')),
            sizePct: (float) ($payload['size_pct'] ?? 0),
            stopLossPct: (float) ($payload['stop_loss_pct'] ?? 0),
            takeProfitPct: (float) ($payload['take_profit_pct'] ?? 0),
            timeStopMinutes: (int) ($payload['time_stop_minutes'] ?? 0),
            requiresHumanApproval: (bool) ($payload['requires_human_approval'] ?? false),
            warnings: array_values(array_map('strval', (array) ($payload['warnings'] ?? []))),
        );
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'symbol' => $this->symbol,
            'confidence' => round($this->confidence, 4),
            'reasoning_summary' => $this->reasoningSummary,
            'size_pct' => round($this->sizePct, 4),
            'stop_loss_pct' => round($this->stopLossPct, 4),
            'take_profit_pct' => round($this->takeProfitPct, 4),
            'time_stop_minutes' => $this->timeStopMinutes,
            'requires_human_approval' => $this->requiresHumanApproval,
            'warnings' => array_values($this->warnings),
        ];
    }

    public function isTradeAction(): bool
    {
        return in_array($this->action, ['BUY', 'SELL'], true);
    }

    public function isReductionAction(): bool
    {
        return $this->action === 'REDUCE';
    }

    public function requiresReview(): bool
    {
        return $this->action === 'REVIEW_REQUIRED';
    }

    public function isTradingAction(): bool
    {
        return in_array($this->action, ['BUY', 'SELL', 'REDUCE'], true);
    }

    public function isPauseAction(): bool
    {
        return $this->action === 'PAUSE_TRADING';
    }

    public static function allowedActions(): array
    {
        return ['BUY', 'SELL', 'HOLD', 'NO_TRADE', 'PAUSE_TRADING', 'REDUCE', 'REVIEW_REQUIRED'];
    }
}
