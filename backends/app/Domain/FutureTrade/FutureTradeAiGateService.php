<?php

namespace App\Domain\FutureTrade;

use App\Domain\Intelligence\FuturesAiDecisionClient;
use App\Models\FutureTradeAiDecision;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI confirmation gate for the futures webhook path.
 *
 * HARD RULES (enforced here in code — never delegated to the model):
 * - Runs only AFTER every deterministic risk gate has already passed.
 * - The AI may only CONFIRM (multiplier 1.0), RESIZE down (clamped to
 *   [min_size_multiplier, 1.0]), or VETO. It can never upsize, flip
 *   direction, change symbol, or bypass a risk gate.
 * - CONFIRM below the configured confidence threshold is treated as VETO.
 * - API failure follows the fail mode: paper fail-open / live fail-closed
 *   (mode 'auto'), overridable per bot.
 * - The daily call cap limits AI SPEND only — when exhausted, the signal
 *   executes deterministically (SKIPPED_CAP); the cap never blocks trading.
 * - Every evaluation persists a FutureTradeAiDecision row (the accuracy dataset).
 */
class FutureTradeAiGateService
{
    public function __construct(
        private readonly FutureTradeAiContextBuilder $context,
        private readonly FuturesAiDecisionClient $client,
    ) {
    }

    public function isEnabled(FutureTradeBotConfig $config, array $settings): bool
    {
        return (bool) ($settings['aiGateEnabled'] ?? false)
            && (string) config('future_trade.ai_gate.api_key', '') !== '';
    }

    /**
     * @return array{allow: bool, decision: string, size_multiplier: float, reason: string, record: ?FutureTradeAiDecision}
     */
    public function evaluate(FutureTradeBotConfig $config, array $signal, array $settings, FutureTradeBotState $state): array
    {
        $mode = $this->mode($config, $settings);
        $base = [
            'workspace_id' => (string) $config->workspace_id,
            'future_trade_bot_config_id' => (string) $config->id,
            'signal_key' => (string) ($signal['signal_key'] ?? ''),
            'symbol' => strtoupper((string) ($signal['symbol'] ?? '')),
            'side' => ($signal['signal'] ?? '') === 'BUY' ? 'LONG' : 'SHORT',
            'timeframe' => strtolower((string) ($signal['timeframe'] ?? $settings['timeframe'] ?? '15m')),
            'mode' => $mode,
            'signal_confidence' => (float) ($signal['confidence'] ?? 0),
            'pine_score' => max(
                (float) data_get($signal, 'debug.longScore', 0),
                (float) data_get($signal, 'debug.shortScore', 0)
            ),
            'ai_provider' => 'anthropic',
        ];

        // Spend cap — pass through deterministically, no API call, no block.
        if (! $this->underDailyCallCap($config)) {
            $record = FutureTradeAiDecision::create($base + [
                'decision' => FutureTradeAiDecision::DECISION_SKIPPED_CAP,
                'size_multiplier' => 1.0,
                'reasoning' => 'Daily AI call cap reached — executed deterministically without AI review.',
            ]);

            return [
                'allow' => true,
                'decision' => FutureTradeAiDecision::DECISION_SKIPPED_CAP,
                'size_multiplier' => 1.0,
                'reason' => 'ai_call_cap_reached_pass_through',
                'record' => $record,
            ];
        }

        try {
            $context = $this->context->build($config, $signal, $settings, $state);
            $result = $this->client->decide($context);
        } catch (Throwable $e) {
            return $this->handleFailure($config, $settings, $base, $mode, $e);
        }

        $decision = $result['decision'];
        $usage = $result['usage'];
        $rawDecision = strtoupper((string) ($decision['decision'] ?? 'VETO'));
        $aiConfidence = $this->clamp((float) ($decision['confidence'] ?? 0), 0.0, 1.0);
        $minConfidence = (float) ($settings['aiGateMinConfidence'] ?? 0.60);
        $minMultiplier = (float) config('future_trade.ai_gate.min_size_multiplier', 0.25);

        $multiplier = match ($rawDecision) {
            FutureTradeAiDecision::DECISION_CONFIRM => 1.0,
            FutureTradeAiDecision::DECISION_RESIZE => $this->clamp((float) ($decision['size_multiplier'] ?? 1.0), $minMultiplier, 1.0),
            default => 0.0,
        };

        $riskFlags = array_values(array_map(
            static fn ($flag) => mb_substr((string) $flag, 0, 64),
            (array) ($decision['risk_flags'] ?? [])
        ));

        $allow = in_array($rawDecision, [FutureTradeAiDecision::DECISION_CONFIRM, FutureTradeAiDecision::DECISION_RESIZE], true);
        $reason = 'ai_' . strtolower($rawDecision);

        // Low-conviction CONFIRM/RESIZE is a veto — the model must actually believe it.
        if ($allow && $aiConfidence < $minConfidence) {
            $allow = false;
            $rawDecision = FutureTradeAiDecision::DECISION_VETO;
            $reason = 'ai_confidence_below_threshold';
            $riskFlags[] = 'low_ai_confidence';
        }

        // Live latency guard: by the time the AI answers, the candle must still be
        // actionable. Budget = ingest latency gate + AI timeout + queue buffer.
        if ($allow && $mode !== 'paper' && $this->liveLatencyExceeded($signal, $settings)) {
            $allow = false;
            $rawDecision = FutureTradeAiDecision::DECISION_VETO;
            $reason = 'ai_gate_latency_exceeded';
            $riskFlags[] = 'stale_after_ai_latency';
        }

        $record = FutureTradeAiDecision::create($base + [
            'decision' => $rawDecision,
            'ai_confidence' => $aiConfidence,
            'size_multiplier' => $allow ? $multiplier : 0.0,
            'sl_adjust_pct' => $this->clampNullable($decision['sl_adjust_pct'] ?? null, -30.0, 30.0),
            'tp_adjust_pct' => $this->clampNullable($decision['tp_adjust_pct'] ?? null, -30.0, 30.0),
            'reasoning' => mb_substr((string) ($decision['reasoning'] ?? ''), 0, 1000),
            'risk_flags' => $riskFlags,
            'ai_model' => (string) $result['model'],
            'prompt_version' => (string) $result['prompt_version'],
            'request_id' => $result['request_id'],
            'latency_ms' => (int) $result['latency_ms'],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_input_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation_input_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'cost_usd' => $this->client->costUsd((string) $result['model'], $usage),
            'context_json' => $context,
        ]);

        return [
            'allow' => $allow,
            'decision' => $rawDecision,
            'size_multiplier' => $allow ? $multiplier : 0.0,
            'reason' => $reason,
            'record' => $record,
        ];
    }

    public function markExecuted(?string $decisionId, string $logicalTradeId): void
    {
        if ($decisionId === null || $decisionId === '') {
            return;
        }

        FutureTradeAiDecision::query()->whereKey($decisionId)->update([
            'executed' => true,
            'logical_trade_id' => $logicalTradeId,
        ]);
    }

    public function markBlocked(?string $decisionId, string $blockReason): void
    {
        if ($decisionId === null || $decisionId === '') {
            return;
        }

        FutureTradeAiDecision::query()->whereKey($decisionId)->update([
            'block_reason' => mb_substr($blockReason, 0, 64),
        ]);
    }

    /**
     * @return array{allow: bool, decision: string, size_multiplier: float, reason: string, record: ?FutureTradeAiDecision}
     */
    private function handleFailure(FutureTradeBotConfig $config, array $settings, array $base, string $mode, Throwable $e): array
    {
        $failMode = strtolower((string) ($settings['aiGateFailMode'] ?? 'auto'));
        $failOpen = match ($failMode) {
            'open' => true,
            'closed' => false,
            default => $mode === 'paper', // auto: paper fail-open, live/testnet fail-closed
        };

        Log::warning('FUTURES_AI_GATE_ERROR', [
            'config_id' => (string) $config->id,
            'symbol' => $base['symbol'],
            'mode' => $mode,
            'fail_open' => $failOpen,
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);

        $decision = $failOpen
            ? FutureTradeAiDecision::DECISION_ERROR_FAIL_OPEN
            : FutureTradeAiDecision::DECISION_ERROR_FAIL_CLOSED;

        $record = FutureTradeAiDecision::create($base + [
            'decision' => $decision,
            'size_multiplier' => $failOpen ? 1.0 : 0.0,
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'reasoning' => $failOpen
                ? 'AI unavailable — executed deterministically (fail-open).'
                : 'AI unavailable — signal skipped (fail-closed).',
        ]);

        return [
            'allow' => $failOpen,
            'decision' => $decision,
            'size_multiplier' => $failOpen ? 1.0 : 0.0,
            'reason' => $failOpen ? 'ai_error_fail_open' : 'ai_error_fail_closed',
            'record' => $record,
        ];
    }

    private function underDailyCallCap(FutureTradeBotConfig $config): bool
    {
        $cap = (int) config('future_trade.ai_gate.max_calls_per_day', 100);
        if ($cap <= 0) {
            return true;
        }

        // Workspace-wide count so multi-slot bots share one spend budget.
        // SKIPPED_CAP rows are excluded — they never hit the API.
        $callsToday = FutureTradeAiDecision::query()
            ->where('workspace_id', (string) $config->workspace_id)
            ->where('decision', '!=', FutureTradeAiDecision::DECISION_SKIPPED_CAP)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return $callsToday < $cap;
    }

    private function liveLatencyExceeded(array $signal, array $settings): bool
    {
        $candleClose = (string) ($signal['candle_close'] ?? '');
        if ($candleClose === '') {
            return false;
        }

        try {
            $closedAt = Carbon::parse($candleClose);
        } catch (Throwable) {
            return false;
        }

        $budgetMs = (int) ($settings['webhookMaxSignalLatencyMs'] ?? config('future_trade.pine_webhook_max_signal_latency_ms', 4000))
            + (int) config('future_trade.ai_gate.timeout_ms', 8000)
            + 8000; // queue/dispatch buffer

        return $closedAt->diffInMilliseconds(now(), true) > $budgetMs;
    }

    private function mode(FutureTradeBotConfig $config, array $settings): string
    {
        $liveRequested = (bool) $config->enable_live_trading && (bool) ($settings['liveTrading'] ?? false);
        if (! $liveRequested) {
            return 'paper';
        }

        return ((bool) ($settings['forceTestnet'] ?? true)) ? 'testnet' : 'live';
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function clampNullable(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return $this->clamp((float) $value, $min, $max);
    }
}
