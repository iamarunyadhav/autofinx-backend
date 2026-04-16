<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiAiDecisionProvider implements AiDecisionProvider
{
    public function __construct(
        private readonly AiDecisionPromptBuilderService $prompts,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(array $config = []): string
    {
        return (string) ($config['provider_model'] ?? config('intelligence.ai_trading.openai.model'));
    }

    public function generateProposal(array $context, array $config): array|string
    {
        $apiKey = (string) config('intelligence.ai_trading.openai.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('AI_TRADE_OPENAI_API_KEY is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->timeout((int) ceil(((int) config('intelligence.ai_trading.openai.timeout_ms', 5000)) / 1000))
            ->post((string) config('intelligence.ai_trading.openai.api_url'), [
                'model' => $this->model($config),
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->prompts->systemPrompt()],
                    ['role' => 'user', 'content' => $this->prompts->userPrompt($context, $config)],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI trade proposal request failed: ' . $response->body());
        }

        return (string) data_get($response->json(), 'choices.0.message.content', '{}');
    }
}
