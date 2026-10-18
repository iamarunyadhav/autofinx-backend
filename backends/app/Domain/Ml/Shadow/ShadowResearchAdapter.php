<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Nexus\NexusResearchProvider;
use Throwable;

/** Provider-neutral, opt-in research adapter. Never changes models or execution. */
final class ShadowResearchAdapter
{
    public function review(array $diagnostics, string $symbol): array
    {
        if (! (bool) config('ml.shadow_research.llm_enabled', false)) {
            return ['status' => 'DISABLED', 'suggestions' => [], 'message' => 'LLM review is disabled by default.'];
        }
        if (! app()->bound(NexusResearchProvider::class)) {
            return ['status' => 'PROVIDER_UNAVAILABLE', 'suggestions' => [], 'message' => 'No research provider is configured.'];
        }

        $context = json_encode([
            'task' => 'Review ML shadow diagnostics and propose testable hypotheses only.',
            'symbol' => $symbol,
            'diagnostics' => $diagnostics,
            'constraints' => ['no trading', 'no deployment', 'no model promotion', 'holdout validation required'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            $result = app(NexusResearchProvider::class)->research($context, (string) config('nexus.llm.routine_model'));
            return ['status' => 'COMPLETED', 'suggestions' => array_slice($result['candidates'] ?? [], 0, 5), 'message' => 'Research suggestions only; no code or model was changed.'];
        } catch (Throwable) {
            return ['status' => 'PROVIDER_FAILED', 'suggestions' => [], 'message' => 'Research provider failed safely.'];
        }
    }
}
