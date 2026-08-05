<?php

use App\Domain\Intelligence\AnthropicAiDecisionProvider;
use App\Domain\Trading\KlineBrainService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('anthropic provider sends modern request shape (no temperature, cached system, structured output)', function () {
    config([
        'intelligence.ai_trading.anthropic.api_key' => 'test-key',
        'intelligence.ai_trading.anthropic.model' => 'claude-opus-4-8',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'action' => 'HOLD',
                'symbol' => 'BTCUSDT',
                'confidence' => 0.5,
                'reasoning_summary' => 'No edge.',
                'size_pct' => 0,
                'stop_loss_pct' => 0,
                'take_profit_pct' => 0,
                'time_stop_minutes' => 0,
                'requires_human_approval' => false,
                'warnings' => [],
            ])]],
            'usage' => ['input_tokens' => 5000, 'output_tokens' => 120, 'cache_read_input_tokens' => 4500, 'cache_creation_input_tokens' => 0],
            'stop_reason' => 'end_turn',
            'model' => 'claude-opus-4-8',
        ]),
    ]);

    $result = app(AnthropicAiDecisionProvider::class)->generateProposal(
        ['symbol' => 'BTCUSDT', 'price' => ['latest' => 64000.0]],
        []
    );

    expect($result)->toBeString()
        ->and(json_decode((string) $result, true)['action'])->toBe('HOLD');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ! array_key_exists('temperature', $data)
            && ! array_key_exists('top_p', $data)
            && ! array_key_exists('top_k', $data)
            && data_get($data, 'model') === 'claude-opus-4-8'
            && is_array(data_get($data, 'system'))
            && data_get($data, 'system.0.cache_control.type') === 'ephemeral'
            && data_get($data, 'output_config.format.type') === 'json_schema'
            && data_get($data, 'output_config.format.schema.additionalProperties') === false;
    });
});

test('ai context builder prefers the live kline cache over legacy market candles', function () {
    $candles = [];
    $base = 64000.0;
    for ($i = 0; $i < 60; $i++) {
        $close = $base + $i * 10;
        $candles[] = [
            'open' => $close - 5,
            'high' => $close + 8,
            'low' => $close - 9,
            'close' => $close,
            'volume' => 100 + $i,
            'ts' => 1747640000000 + $i * 900000,
        ];
    }
    Cache::put('autofinx:kline:BTCUSDT:15m:candles', $candles);

    $klineBrain = app(KlineBrainService::class);
    expect(count($klineBrain->getCachedCandles('BTCUSDT', '15m')))->toBe(60);

    $context = app(\App\Domain\Intelligence\AiContextBuilderService::class)
        ->build((string) \Illuminate\Support\Str::uuid(), 'BTCUSDT');

    expect(data_get($context, 'candles.source'))->toBe('kline_cache')
        ->and(data_get($context, 'candles.timeframe'))->toBe('15m')
        ->and(data_get($context, 'candles.count'))->toBe(60)
        ->and(data_get($context, 'indicators.atr_14'))->not->toBeNull()
        ->and(data_get($context, 'indicators.adx_14'))->not->toBeNull();
});
