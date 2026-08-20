<?php

namespace App\Domain\RatioBot;

use App\Domain\RatioBot\Strategies\StrategyRegistry;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use Illuminate\Support\Facades\Log;

class RatioBotRealtimeEngine
{
    public function __construct(
        private readonly StrategyRegistry $strategies,
        private readonly RatioBotStateService $stateService,
        private readonly StrategyDecisionRecorder $recorder,
        private readonly RatioBotRiskService $risk,
        private readonly RatioBotExecutionService $execution,
        private readonly IndicatorService $indicators,
        private readonly DynamicThresholdService $dynamicThresholds,
        private readonly TrailingProfitService $trailingProfits,
        private readonly RatioBotBrainService $brain,
    ) {
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): array
    {
        $indicatorSnapshot = $this->indicators->snapshot($config, $currentPrice);
        $dynamicThresholds = $this->dynamicThresholds->resolve($config, $currentPrice, $indicatorSnapshot);
        $trailingProfit = $this->trailingProfits->resolve($config, $config->state, $currentPrice, $dynamicThresholds);
        $marketHealth = $this->marketHealthSnapshot($config, $context, $indicatorSnapshot, $dynamicThresholds);
        $brainSnapshot = $config->brain_enabled ? $this->brain->resolve($config, $currentPrice) : null;

        $this->stateService->markMarketSnapshot(
            $config,
            $currentPrice,
            isset($context['price_source']) ? (string) $context['price_source'] : null,
            $context['price_timestamp'] ?? null,
            [
                'dynamic_thresholds' => $dynamicThresholds,
                'indicator_snapshot' => $indicatorSnapshot,
                'trailing_profit' => $trailingProfit,
                'market_health' => $marketHealth,
                'brain_snapshot' => $brainSnapshot ? [
                    'direction' => $brainSnapshot->direction,
                    'confidence' => $brainSnapshot->confidence,
                    'forecast_bias' => $brainSnapshot->forecast_bias,
                    'forecast_confidence' => $brainSnapshot->forecast_confidence,
                ] : null,
            ],
        );

        Log::info('[RatioBotEngine] Market analysis resolved', [
            'config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'dynamic_thresholds' => $dynamicThresholds,
            'market_health' => $marketHealth,
            'indicator_summary' => [
                'market_mode' => $indicatorSnapshot['market_mode'] ?? null,
                'volatility_level' => $indicatorSnapshot['volatility_level'] ?? null,
                'rsi' => $indicatorSnapshot['rsi'] ?? null,
                'adx' => $indicatorSnapshot['adx'] ?? null,
                'volume_ratio' => $indicatorSnapshot['volume_ratio'] ?? null,
                'spread_ratio' => $indicatorSnapshot['spread_ratio'] ?? null,
            ],
        ]);

        $strategy = $this->strategies->resolve($config);
        $analysisContext = array_merge($context, [
            'dynamic_thresholds' => $dynamicThresholds,
            'indicator_snapshot' => $indicatorSnapshot,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        $signal = $strategy->evaluate($config, $currentPrice, $analysisContext);
        $decision = $signal->decisionRecord ?? $this->recorder->record($config, $signal, $currentPrice, $context);

        if (! $signal->decisionRecord) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_SIGNAL_EMITTED,
                'context' => [
                    'decision' => $decision->decision,
                    'reason' => $decision->reason,
                    'decision_id' => $decision->id,
                ],
                'occurred_at' => now(),
            ]);
        }

        if ($decision->decision === RatioBotDecision::DECISION_BLOCK) {
            $this->stateService->block($config, 'strategy_block', (string) $decision->reason);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        if (! $decision->isTrade()) {
            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        $indicatorConfirmation = $this->indicators->confirm($config, $decision->decision, $currentPrice, $indicatorSnapshot, [
            'decision_metadata' => (array) ($decision->metadata ?? []),
            'dynamic_thresholds' => $dynamicThresholds,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        $riskBlock = $this->risk->preTradeBlockReason($config, $decision, [
            'dynamic_thresholds' => $dynamicThresholds,
            'indicator_snapshot' => $indicatorSnapshot,
            'indicator_confirmation' => $indicatorConfirmation,
            'trailing_profit' => $trailingProfit,
            'market_health' => $marketHealth,
        ]);
        if ($riskBlock !== null) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_SKIPPED,
                'reason' => $riskBlock['detail'],
                'metadata' => array_merge((array) ($decision->metadata ?? []), [
                    'risk_block' => $riskBlock,
                    'indicator_confirmation' => $indicatorConfirmation,
                    'market_health' => $marketHealth,
                ]),
            ]);

            if ((bool) $riskBlock['pause']) {
                $this->stateService->block($config, (string) $riskBlock['code'], (string) $riskBlock['detail']);
            }

            Log::warning('[RatioBotEngine] Trade blocked by risk guard', [
                'config_id' => $config->id,
                'decision_id' => $decision->id,
                'code' => $riskBlock['code'],
            ]);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $riskBlock['detail']];
        }

        if ($config->isAutoMode()) {
            $this->execution->execute($config, $decision);

            return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
        }

        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_SKIPPED,
            'reason' => 'mode=' . $config->mode . ' requires manual approval',
        ]);

        return ['decision' => $decision->decision, 'decision_record' => $decision, 'reason' => $decision->reason];
    }

    private function marketHealthSnapshot(RatioBotConfig $config, array $context, array $indicatorSnapshot, array $dynamicThresholds): array
    {
        $priceTimestamp = $context['price_timestamp'] ?? null;
        $priceAgeSeconds = null;
        if ($priceTimestamp instanceof \DateTimeInterface) {
            $priceAgeSeconds = now()->diffInSeconds($priceTimestamp);
        } elseif (is_numeric($priceTimestamp)) {
            $priceAgeSeconds = abs(((int) floor(microtime(true) * 1000)) - (int) $priceTimestamp) / 1000;
        }

        return [
            'feed_status' => $config->state?->feed_status,
            'latency_ms' => isset($context['latency_ms']) ? (int) $context['latency_ms'] : null,
            'price_age_seconds' => $context['price_age_seconds'] ?? $priceAgeSeconds,
            'price_source' => $context['price_source'] ?? null,
            'volume_ratio' => $indicatorSnapshot['volume_ratio'] ?? null,
            'spread_ratio' => $indicatorSnapshot['spread_ratio'] ?? null,
            'liquidity_notional' => $indicatorSnapshot['liquidity_notional'] ?? null,
            'market_mode' => $dynamicThresholds['market_mode'] ?? ($indicatorSnapshot['market_mode'] ?? null),
            'volatility_level' => $dynamicThresholds['volatility_level'] ?? ($indicatorSnapshot['volatility_level'] ?? null),
        ];
    }
}
