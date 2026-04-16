<?php

use App\Domain\Intelligence\AiDecisionOrchestratorService;
use Illuminate\Support\Facades\Http;

it('uses the configured openai provider and validates a trade proposal', function () {
    config([
        'intelligence.ai_trading.openai.api_key' => 'test-key',
        'intelligence.ai_trading.openai.api_url' => 'https://openai.local/v1/chat/completions',
    ]);

    Http::fake([
        'https://openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'action' => 'BUY',
                        'symbol' => 'BTCUSDT',
                        'confidence' => 0.72,
                        'reasoning_summary' => 'Trend and signal alignment support a supervised entry.',
                        'size_pct' => 1.0,
                        'stop_loss_pct' => 0.8,
                        'take_profit_pct' => 1.6,
                        'time_stop_minutes' => 90,
                        'requires_human_approval' => true,
                        'warnings' => ['llm_provider'],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $orchestrator = app(AiDecisionOrchestratorService::class);
    $result = $orchestrator->propose([
        'workspace_id' => 'ws-1',
        'symbol' => 'BTCUSDT',
        'exchange' => 'binance',
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
        'blockers' => [],
    ], [
        'provider' => 'openai',
        'provider_model' => 'gpt-5.4',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['provider'])->toBe('openai')
        ->and($result['model'])->toBe('gpt-5.4')
        ->and($result['proposal']->action)->toBe('BUY')
        ->and($result['proposal']->requiresHumanApproval)->toBeTrue();
});
