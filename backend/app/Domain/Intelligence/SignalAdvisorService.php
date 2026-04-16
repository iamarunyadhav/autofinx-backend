<?php

namespace App\Domain\Intelligence;

use App\Models\Bot;
use App\Models\Setting;
use App\Models\TradeSignal;
use Illuminate\Support\Facades\Http;

class SignalAdvisorService
{
    public function assess(Bot $bot, TradeSignal $signal, array $quote): array
    {
        $settings = $this->workspaceSettings($bot->workspace_id);
        $enabled = $this->boolValue($settings['enabled'] ?? env('AI_BRAIN_ENABLED', false));
        if (! $enabled) {
            return [
                'enabled' => false,
                'provider' => null,
                'approved' => true,
                'confidence' => 0.0,
                'reason' => 'AI advisor disabled',
            ];
        }

        $provider = strtolower((string) ($settings['provider'] ?? env('AI_BRAIN_PROVIDER', 'openai')));
        $maxLatencyMs = max(500, (int) ($settings['timeout_ms'] ?? env('AI_BRAIN_TIMEOUT_MS', 3500)));
        $payload = $this->signalPayload($bot, $signal, $quote);

        try {
            return match ($provider) {
                'gemini' => $this->assessWithGemini($payload, $maxLatencyMs),
                'claude', 'anthropic' => $this->assessWithClaude($payload, $maxLatencyMs),
                default => $this->assessWithOpenAi($payload, $maxLatencyMs),
            };
        } catch (\Throwable $e) {
            return [
                'enabled' => true,
                'provider' => $provider,
                'approved' => true,
                'confidence' => 0.0,
                'reason' => 'AI provider unavailable; pass-through',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assessWithOpenAi(array $payload, int $timeoutMs): array
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY missing');
        }

        $model = (string) env('OPENAI_MODEL', 'gpt-4.1-mini');
        $response = Http::withToken($apiKey)
            ->timeout((int) ceil($timeoutMs / 1000))
            ->post((string) env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'), [
                'model' => $model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a conservative trading risk reviewer. Return JSON only: {"approved":true|false,"confidence":0..1,"reason":"short reason"}.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($payload),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI request failed: ' . $response->body());
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '{}');
        return $this->normalizeDecision('openai', $content);
    }

    private function assessWithGemini(array $payload, int $timeoutMs): array
    {
        $apiKey = (string) env('GEMINI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY missing');
        }

        $model = (string) env('GEMINI_MODEL', 'gemini-1.5-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $response = Http::timeout((int) ceil($timeoutMs / 1000))
            ->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => 'Return JSON only {"approved":true|false,"confidence":0..1,"reason":"short reason"}'],
                            ['text' => json_encode($payload)],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini request failed: ' . $response->body());
        }

        $content = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '{}');
        return $this->normalizeDecision('gemini', $content);
    }

    private function assessWithClaude(array $payload, int $timeoutMs): array
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY missing');
        }

        $model = (string) env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest');
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout((int) ceil($timeoutMs / 1000))
            ->post((string) env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'), [
                'model' => $model,
                'max_tokens' => 180,
                'temperature' => 0.1,
                'system' => 'You are a conservative trading risk reviewer. Return JSON only.',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Return JSON only {"approved":true|false,"confidence":0..1,"reason":"short reason"} for this trade context: ' . json_encode($payload),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Claude request failed: ' . $response->body());
        }

        $content = (string) data_get($response->json(), 'content.0.text', '{}');
        return $this->normalizeDecision('claude', $content);
    }

    private function normalizeDecision(string $provider, string $rawContent): array
    {
        $decoded = json_decode(trim($rawContent), true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        return [
            'enabled' => true,
            'provider' => $provider,
            'approved' => (bool) ($decoded['approved'] ?? true),
            'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0))),
            'reason' => (string) ($decoded['reason'] ?? 'No reason'),
            'raw' => $decoded,
        ];
    }

    private function signalPayload(Bot $bot, TradeSignal $signal, array $quote): array
    {
        return [
            'asset_class' => strtoupper((string) ($bot->asset_class ?? $signal->asset_class ?? 'CRYPTO')),
            'asset_code' => strtoupper((string) ($bot->asset_code ?? $signal->asset_code ?? $signal->symbol)),
            'provider' => strtolower((string) ($bot->provider ?? $signal->provider ?? $bot->exchange ?? 'binance')),
            'side' => strtoupper((string) $signal->side),
            'decision_price' => (float) $signal->decision_price,
            'decision_ts' => optional($signal->decision_ts)->toIso8601String(),
            'max_slippage_pct' => (float) $signal->max_slippage_pct,
            'ttl_ms' => (int) $signal->ttl_ms,
            'quote' => $quote,
            'strategy_type' => $bot->strategy_type,
            'strategy_params' => array_filter((array) ($bot->strategy_params ?? []), static function ($value, $key) {
                return ! in_array((string) $key, ['api_key', 'api_secret', 'secret', 'token'], true);
            }, ARRAY_FILTER_USE_BOTH),
        ];
    }

    private function workspaceSettings(?string $workspaceId): array
    {
        if (! $workspaceId) {
            return [];
        }

        return Setting::where('workspace_id', $workspaceId)
            ->where('group', 'ai')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->key => $item->value_json])
            ->toArray();
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

