<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;

class NullAiDecisionProvider implements AiDecisionProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function model(array $config = []): string
    {
        return 'null-provider';
    }

    public function generateProposal(array $context, array $config): array|string
    {
        return [
            'action' => 'NO_TRADE',
            'symbol' => strtoupper((string) ($context['symbol'] ?? '')),
            'confidence' => 0.0,
            'reasoning_summary' => 'No AI trading provider is configured for this environment.',
            'size_pct' => 0.0,
            'stop_loss_pct' => 0.0,
            'take_profit_pct' => 0.0,
            'time_stop_minutes' => 0,
            'requires_human_approval' => false,
            'warnings' => ['non_production_provider', 'provider_unconfigured'],
        ];
    }
}
