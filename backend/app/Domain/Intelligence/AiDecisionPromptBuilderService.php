<?php

namespace App\Domain\Intelligence;

class AiDecisionPromptBuilderService
{
    public function systemPrompt(): string
    {
        return implode(' ', [
            'You are a conservative Binance spot trading decision engine.',
            'Return JSON only.',
            'You must never suggest futures, leverage, margin, withdrawals, or custody actions.',
            'If context blockers exist, action must be NO_TRADE or PAUSE_TRADING.',
            'If portfolio_intelligence is provided, treat it as advisory ranking context only; it never overrides policy, risk, or operator control.',
            'Supported actions are BUY, SELL, HOLD, NO_TRADE, PAUSE_TRADING.',
            'Confidence must be between 0 and 1.',
            'For BUY or SELL, include size_pct, stop_loss_pct, take_profit_pct, and time_stop_minutes.',
            'Warnings must be an array of short machine-readable strings.',
        ]);
    }

    public function userPrompt(array $context, array $config): string
    {
        return json_encode([
            'workspace_id' => $context['workspace_id'] ?? null,
            'symbol' => $context['symbol'] ?? null,
            'exchange' => $context['exchange'] ?? 'binance',
            'market_type' => 'spot',
            'operator_mode' => $config['mode'] ?? 'advisor',
            'operator_policy' => [
                'allowed_symbols' => $config['allowed_symbols'] ?? [],
                'blocked_symbols' => $config['blocked_symbols'] ?? [],
                'max_trade_size_pct' => $config['max_trade_size_pct'] ?? null,
                'min_confidence' => $config['min_confidence'] ?? null,
                'require_stop_loss' => $config['require_stop_loss'] ?? true,
                'require_take_profit' => $config['require_take_profit'] ?? true,
                'require_time_stop' => $config['require_time_stop'] ?? true,
            ],
            'context' => $context,
            'portfolio_intelligence' => $context['portfolio_intelligence'] ?? null,
            'response_contract' => [
                'action' => 'BUY|SELL|HOLD|NO_TRADE|PAUSE_TRADING',
                'symbol' => 'string',
                'confidence' => '0..1',
                'reasoning_summary' => 'short string',
                'size_pct' => 'number',
                'stop_loss_pct' => 'number',
                'take_profit_pct' => 'number',
                'time_stop_minutes' => 'integer',
                'requires_human_approval' => 'boolean',
                'warnings' => ['array', 'of', 'strings'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
