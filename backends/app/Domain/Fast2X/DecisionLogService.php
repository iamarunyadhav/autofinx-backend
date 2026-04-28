<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;

class DecisionLogService
{
    public function log(Fast2XConfig $config, string $event, array $context = []): void
    {
        $state = $config->state;
        $config->logs()->create([
            'event' => $event,
            'bot_name' => 'Fast2X',
            'symbol' => $config->normalizedSymbol(),
            'entry_price' => $context['entryPrice'] ?? $state?->entry_price,
            'current_price' => $context['currentPrice'] ?? $state?->current_price,
            'net_pnl_percent' => $context['netPnlPercent'] ?? $state?->net_pnl_percent,
            'reason' => $context['reason'] ?? null,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
