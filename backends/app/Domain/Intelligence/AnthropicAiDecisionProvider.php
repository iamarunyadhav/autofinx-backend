<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use App\Domain\Intelligence\Schema\AiProposalSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $model = $this->model($config);

        // Notes on request shape (current Claude models — opus-4-8 / sonnet-5):
        // - temperature/top_p/top_k are rejected with HTTP 400; steer via prompt only.
        // - system prompt is sent as a block array so the stable knowledge prefix can be
        //   cached (cache_control) — reads bill at ~0.1x input price.
        // - output_config.format (json_schema) guarantees the first text block is valid
        //   JSON matching the proposal schema; AiDecisionSchemaValidator remains as the
        //   second line of defense and applies numeric clamps the schema cannot express.
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->connectTimeout(2)
            ->timeout((int) ceil(((int) config('intelligence.ai_trading.anthropic.timeout_ms', 10000)) / 1000))
            ->post((string) config('intelligence.ai_trading.anthropic.api_url'), [
                'model' => $model,
                'max_tokens' => (int) config('intelligence.ai_trading.anthropic.max_output_tokens', 800),
                'system' => [[
                    'type' => 'text',
                    'text' => $this->prompts->systemPrompt(),
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompts->userPrompt($context, $config),
                ]],
                'output_config' => [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => AiProposalSchema::spot(),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic trade proposal request failed: ' . $response->body());
        }

        $body = $response->json();
        $usage = (array) data_get($body, 'usage', []);
        Log::info('AI_ANTHROPIC_USAGE', [
            'provider' => 'anthropic',
            'model' => (string) data_get($body, 'model', $model),
            'stop_reason' => data_get($body, 'stop_reason'),
            'input_tokens' => (int) data_get($usage, 'input_tokens', 0),
            'output_tokens' => (int) data_get($usage, 'output_tokens', 0),
            'cache_read_input_tokens' => (int) data_get($usage, 'cache_read_input_tokens', 0),
            'cache_creation_input_tokens' => (int) data_get($usage, 'cache_creation_input_tokens', 0),
            'request_id' => $response->header('request-id'),
        ]);

        return (string) data_get($body, 'content.0.text', '{}');
    }
}
