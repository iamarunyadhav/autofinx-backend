<?php

namespace App\Domain\Intelligence;

class AiDecisionOrchestratorService
{
    public function __construct(
        private readonly AiDecisionProviderFactory $providers,
        private readonly AiDecisionSchemaValidator $validator,
    ) {
    }

    public function propose(array $context, array $config): array
    {
        $provider = null;
        $providerName = (string) ($config['provider'] ?? 'heuristic');
        $rawText = null;
        $rawJson = null;

        try {
            $provider = $this->providers->make($providerName);
            $raw = $provider->generateProposal($context, $config);
            $rawText = is_string($raw) ? $raw : json_encode($raw);
            $rawJson = is_array($raw) ? $raw : (json_decode($raw, true) ?: null);
            $proposal = $this->validator->validateOrFail($raw);

            return [
                'ok' => true,
                'provider' => $provider->name(),
                'model' => $provider->model($config),
                'raw_text' => $rawText,
                'raw_json' => $rawJson,
                'proposal' => $proposal,
                'validation' => [
                    'ok' => true,
                    'errors' => [],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'provider' => $provider?->name() ?? $providerName,
                'model' => $provider?->model($config) ?? (string) ($config['provider_model'] ?? ''),
                'raw_text' => $rawText,
                'raw_json' => $rawJson,
                'proposal' => null,
                'validation' => [
                    'ok' => false,
                    'errors' => [$e->getMessage()],
                ],
            ];
        }
    }
}
