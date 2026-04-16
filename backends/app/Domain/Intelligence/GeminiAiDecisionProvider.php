<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiAiDecisionProvider implements AiDecisionProvider
{
    public function __construct(
        private readonly AiDecisionPromptBuilderService $prompts,
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(array $config = []): string
    {
        return (string) ($config['provider_model'] ?? config('intelligence.ai_trading.gemini.model'));
    }

    public function generateProposal(array $context, array $config): array|string
    {
        $apiKey = (string) config('intelligence.ai_trading.gemini.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('AI_TRADE_GEMINI_API_KEY is not configured.');
        }

        $model = $this->model($config);
        $baseUrl = rtrim((string) config('intelligence.ai_trading.gemini.api_url'), '/');
        $response = Http::timeout((int) ceil(((int) config('intelligence.ai_trading.gemini.timeout_ms', 5000)) / 1000))
            ->post("{$baseUrl}/{$model}:generateContent?key={$apiKey}", [
                'contents' => [[
                    'parts' => [
                        ['text' => $this->prompts->systemPrompt()],
                        ['text' => $this->prompts->userPrompt($context, $config)],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini trade proposal request failed: ' . $response->body());
        }

        return (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '{}');
    }
}
