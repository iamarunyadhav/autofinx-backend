<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Raw Anthropic Messages API client for the futures AI confirmation gate.
 *
 * Kept on the Laravel Http facade (raw HTTP, snake_case wire format) to match
 * the existing AnthropicAiDecisionProvider pattern — no SDK dependency.
 */
class FuturesAiDecisionClient
{
    /**
     * Structured-output schema for the gate decision. The API guarantees the
     * first text block is valid JSON matching this schema. Numeric bounds
     * (confidence 0-1, multiplier 0.25-1.0, sl/tp caps) are NOT expressible
     * here — FutureTradeAiGateService clamps them server-side.
     */
    public const DECISION_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['decision', 'confidence', 'size_multiplier', 'sl_adjust_pct', 'tp_adjust_pct', 'reasoning', 'risk_flags'],
        'properties' => [
            'decision' => ['type' => 'string', 'enum' => ['CONFIRM', 'VETO', 'RESIZE']],
            'confidence' => ['type' => 'number', 'description' => 'Conviction in this decision, 0..1.'],
            'size_multiplier' => ['type' => 'number', 'description' => '1.0 for CONFIRM, 0.25-1.0 for RESIZE, ignored for VETO.'],
            'sl_adjust_pct' => ['type' => ['number', 'null'], 'description' => 'Optional relative stop-loss adjustment in percent, or null.'],
            'tp_adjust_pct' => ['type' => ['number', 'null'], 'description' => 'Optional relative take-profit adjustment in percent, or null.'],
            'reasoning' => ['type' => 'string', 'description' => 'Factual justification, max 300 characters.'],
            'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    public function __construct(private readonly FuturesAiPromptBuilder $prompts)
    {
    }

    /**
     * @return array{decision: array, usage: array, latency_ms: int, request_id: ?string, model: string, prompt_version: string}
     */
    public function decide(array $context, array $options = []): array
    {
        $apiKey = (string) config('future_trade.ai_gate.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('AI gate API key is not configured (FUTURE_TRADE_AI_GATE_API_KEY / ANTHROPIC_API_KEY).');
        }

        $model = (string) ($options['model'] ?? config('future_trade.ai_gate.model', 'claude-opus-4-8'));
        $timeoutMs = (int) ($options['timeout_ms'] ?? config('future_trade.ai_gate.timeout_ms', 8000));

        $body = [
            'model' => $model,
            'max_tokens' => (int) config('future_trade.ai_gate.max_output_tokens', 500),
            // No temperature/top_p/top_k — rejected (HTTP 400) on current Claude models.
            'system' => [[
                'type' => 'text',
                'text' => $this->prompts->systemPrompt(),
                // 1h TTL: 15m-timeframe signals are sparse; the default 5m TTL
                // would miss the cache on almost every call.
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => $this->prompts->userMessage($context),
            ]],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => self::DECISION_SCHEMA,
                ],
            ],
        ];

        // On opus-4-8 omitting `thinking` runs without thinking (lowest latency).
        // On sonnet-5 omission means adaptive thinking — disable it explicitly to
        // keep the gate inside its latency budget.
        if (str_starts_with($model, 'claude-sonnet-5')) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $startedAt = microtime(true);
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->connectTimeout(2)
            ->timeout((int) ceil($timeoutMs / 1000))
            ->retry(1, 250, fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
            ->post((string) config('future_trade.ai_gate.api_url'), $body);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            throw new RuntimeException('AI gate request failed (HTTP ' . $response->status() . '): ' . mb_substr((string) $response->body(), 0, 500));
        }

        $json = $response->json();
        $decision = json_decode((string) data_get($json, 'content.0.text', ''), true);
        if (! is_array($decision) || ! isset($decision['decision'])) {
            throw new RuntimeException('AI gate returned an unparseable decision payload.');
        }

        return [
            'decision' => $decision,
            'usage' => (array) data_get($json, 'usage', []),
            'latency_ms' => $latencyMs,
            'request_id' => $response->header('request-id') ?: null,
            'model' => (string) data_get($json, 'model', $model),
            'prompt_version' => $this->prompts->promptVersion(),
        ];
    }

    /**
     * USD cost of one call from the configured per-model price map.
     * Cached reads are billed at ~0.1x input price; cache writes at ~1.25x.
     */
    public function costUsd(string $model, array $usage): ?float
    {
        $prices = (array) config('future_trade.ai_gate.prices_per_mtok', []);
        $price = $prices[$model] ?? null;
        if ($price === null) {
            foreach ($prices as $prefix => $candidate) {
                if (str_starts_with($model, (string) $prefix)) {
                    $price = $candidate;
                    break;
                }
            }
        }
        if ($price === null) {
            return null;
        }

        $in = (float) ($price['in'] ?? 0);
        $out = (float) ($price['out'] ?? 0);

        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $cost = ($inputTokens * $in)
            + ($cacheRead * $in * 0.1)
            + ($cacheWrite * $in * 2.0) // 1h-TTL cache writes bill at 2x
            + ($outputTokens * $out);

        return round($cost / 1_000_000, 6);
    }
}
