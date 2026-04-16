<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicAiDecisionProvider implements AiDecisionProvider
{
    public function __construct(
        private readonly AiDecisionPromptBuilderService $prompts,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(array $config = []): string
    {
        return (string) ($config['provider_model'] ?? config('intelligence.ai_trading.anthropic.model'));
    }

    public function generateProposal(array $context, array $config): array|string
    {
        $apiKey = (string) config('intelligence.ai_trading.anthropic.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('AI_TRADE_ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout((int) ceil(((int) config('intelligence.ai_trading.anthropic.timeout_ms', 5000)) / 1000))
            ->post((string) config('intelligence.ai_trading.anthropic.api_url'), [
                'model' => $this->model($config),
                'max_tokens' => 400,
                'temperature' => 0.1,
                'system' => $this->prompts->systemPrompt(),
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompts->userPrompt($context, $config),
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic trade proposal request failed: ' . $response->body());
        }

        return (string) data_get($response->json(), 'content.0.text', '{}');
    }
}
