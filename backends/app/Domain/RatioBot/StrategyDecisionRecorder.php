<?php

namespace App\Domain\RatioBot;

use App\Domain\RatioBot\Strategies\StrategySignal;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StrategyDecisionRecorder
{
    public function __construct(
        private readonly RatioBotStateService $stateService,
    ) {
    }

    public function record(RatioBotConfig $config, StrategySignal $signal, float $currentPrice, array $context = []): RatioBotDecision
    {
        $state = $this->stateService->getOrCreate($config);
        $priceTimestamp = $this->normalizeTimestamp($context['price_timestamp'] ?? null);
        $priceSource = isset($context['price_source']) ? (string) $context['price_source'] : null;
        $signalHash = $this->signalHash($signal->decision, $signal->reason, $currentPrice);

        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'correlation_id' => (string) Str::uuid(),
            'decision' => $signal->decision,
            'evaluated_price' => $currentPrice,
            'price_source' => $priceSource,
            'price_timestamp' => $priceTimestamp,
            'anchor_price' => $signal->anchorPrice,
            'anchor_type' => $state->active_anchor_type,
            'computed_ratio' => $signal->computedRatio,
            'threshold_used' => $signal->thresholdUsed,
            'position_state_before' => $state->position_state,
            'position_state_after' => $state->position_state,
            'execution_status' => $signal->isTrade() ? RatioBotDecision::EXEC_PENDING : RatioBotDecision::EXEC_SKIPPED,
            'reason' => $signal->reason,
            'metadata' => $signal->metadata + [
                'strategy' => $config->algorithmKey(),
                'market_type' => $config->marketType(),
                'timeframe' => $config->timeFrame(),
                'side_mode' => $config->side_mode,
                'signal_hash' => $signalHash,
            ],
            'decided_at' => now(),
        ]);

        $this->stateService->rememberSignal($config, $decision->decision, $decision->reason ?? '', $signalHash, [
            'decision_id' => $decision->id,
            'metadata' => $decision->metadata,
        ]);

        return $decision;
    }

    public function signalHash(string $decision, string $reason, float $price): string
    {
        return hash('sha1', implode('|', [
            strtoupper($decision),
            trim($reason),
            number_format($price, 8, '.', ''),
        ]));
    }

    private function normalizeTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestampMs((int) $value);
        }
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
