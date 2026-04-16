<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\AiDecisionProvider;

class HeuristicAiDecisionProvider implements AiDecisionProvider
{
    public function name(): string
    {
        return 'heuristic';
    }

    public function model(array $config = []): string
    {
        return 'heuristic-phase1';
    }

    public function generateProposal(array $context, array $config): array|string
    {
        $symbol = strtoupper((string) ($context['symbol'] ?? ''));
        $warnings = ['non_production_provider'];
        $blockers = array_values((array) ($context['blockers'] ?? []));
        if ($blockers !== []) {
            return [
                'action' => 'NO_TRADE',
                'symbol' => $symbol,
                'confidence' => 0.0,
                'reasoning_summary' => 'Critical context blockers prevent a trading decision.',
                'size_pct' => 0.0,
                'stop_loss_pct' => 0.0,
                'take_profit_pct' => 0.0,
                'time_stop_minutes' => 0,
                'requires_human_approval' => false,
                'warnings' => array_values(array_unique(array_merge($warnings, ['context_blockers_present']))),
            ];
        }

        $signal = (array) ($context['signal_hints']['latest'] ?? []);
        $indicators = (array) ($context['indicators'] ?? []);
        $price = (float) ($context['price']['latest'] ?? 0);
        $minConfidence = (float) config('intelligence.ai_trading.heuristic.min_trade_confidence', 0.55);
        $strongConfidence = (float) config('intelligence.ai_trading.heuristic.strong_trade_confidence', 0.65);
        $buyCeiling = (float) config('intelligence.ai_trading.heuristic.rsi_buy_ceiling', 68);
        $sellFloor = (float) config('intelligence.ai_trading.heuristic.rsi_sell_floor', 32);

        $signalSide = strtoupper((string) ($signal['side'] ?? ''));
        $rsi = (float) ($indicators['rsi_14'] ?? 0);
        $sma9 = (float) ($indicators['sma_9'] ?? 0);
        $sma21 = (float) ($indicators['sma_21'] ?? 0);
        $macdHistogram = (float) data_get($indicators, 'macd.histogram', 0);
        $volatilityPct = (float) ($indicators['volatility_pct'] ?? 0);

        $action = 'HOLD';
        $confidence = 0.35;
        $sizePct = min((float) ($config['max_trade_size_pct'] ?? 1), 1.0);
        $reason = 'No strong spot trade setup was detected by the phase 1 heuristic provider.';

        if ($signalSide === 'BUY' && $price > 0 && $sma9 >= $sma21 && $rsi > 0 && $rsi <= $buyCeiling && $macdHistogram >= 0) {
            $action = 'BUY';
            $confidence = $volatilityPct > 3 ? $minConfidence : $strongConfidence;
            $reason = 'Recent signal, positive short-term trend, and acceptable momentum suggest a supervised long entry.';
        } elseif ($signalSide === 'SELL' && $price > 0 && $sma9 <= $sma21 && $rsi >= $sellFloor && $macdHistogram <= 0) {
            $action = 'SELL';
            $confidence = $volatilityPct > 3 ? $minConfidence : $strongConfidence;
            $reason = 'Recent signal, weak short-term trend, and negative momentum suggest a supervised exit or defensive sell.';
        } elseif ($signalSide === 'BUY' || $signalSide === 'SELL') {
            $action = 'HOLD';
            $confidence = $minConfidence;
            $reason = 'A signal exists, but indicator alignment is not strong enough for unattended execution.';
            $warnings[] = 'signal_indicator_mismatch';
        }

        return [
            'action' => $action,
            'symbol' => $symbol,
            'confidence' => round($confidence, 4),
            'reasoning_summary' => $reason,
            'size_pct' => round($sizePct, 4),
            'stop_loss_pct' => (float) ($config['default_stop_loss_pct'] ?? config('intelligence.ai_trading.heuristic.default_stop_loss_pct', 0.8) ?? $context['risk']['rules']['stop_loss_pct'] ?? 0.8),
            'take_profit_pct' => (float) ($config['default_take_profit_pct'] ?? config('intelligence.ai_trading.heuristic.default_take_profit_pct', 1.6) ?? $context['risk']['rules']['take_profit_pct'] ?? 1.6),
            'time_stop_minutes' => (int) ($config['default_time_stop_minutes'] ?? config('intelligence.ai_trading.heuristic.default_time_stop_minutes', 90)),
            'requires_human_approval' => false,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
