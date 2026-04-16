<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use InvalidArgumentException;

class AiDecisionProviderFactory
{
    public function __construct(
        private readonly AiDecisionPromptBuilderService $prompts,
    ) {
    }

    public function make(?string $providerName = null): AiDecisionProvider
    {
        return match (strtolower(trim((string) ($providerName ?: config('intelligence.ai_trading.provider', 'heuristic'))))) {
            'null', 'off', 'disabled' => new NullAiDecisionProvider(),
            'heuristic' => new HeuristicAiDecisionProvider(),
            'openai' => new OpenAiAiDecisionProvider($this->prompts),
            'gemini' => new GeminiAiDecisionProvider($this->prompts),
            'claude', 'anthropic' => new AnthropicAiDecisionProvider($this->prompts),
            default => throw new InvalidArgumentException('Unsupported AI trading provider.'),
        };
    }
}
