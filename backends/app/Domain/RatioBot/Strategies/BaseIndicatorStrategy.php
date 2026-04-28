<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use Illuminate\Support\Collection;

abstract class BaseIndicatorStrategy implements StrategyInterface
{
    protected function candles(RatioBotConfig $config, int $limit): Collection
    {
        return MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $config->timeFrame())
            ->orderByDesc('ts')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    protected function closes(RatioBotConfig $config, int $limit): array
    {
        return $this->candles($config, $limit)
            ->map(fn ($candle) => (float) $candle->close)
            ->values()
            ->all();
    }

    protected function currentExposure(RatioBotConfig $config): string
    {
        $state = $config->state;
        if (! $state) {
            return 'flat';
        }

        if ($config->marketType() === 'futures') {
            $qty = (float) ($state->last_position_qty ?? 0);
            $side = strtolower((string) ($state->last_position_side ?? ''));

            if ($qty > 0 && in_array($side, ['long', 'short'], true)) {
                return $side;
            }

            return 'flat';
        }

        return $state->isHolding() || (float) ($state->last_buy_quantity ?? 0) > 0 ? 'long' : 'flat';
    }

    protected function directionalSignal(RatioBotConfig $config, string $bias, string $reason, array $metrics = []): StrategySignal
    {
        $exposure = $this->currentExposure($config);
        $sideMode = strtolower((string) ($config->side_mode ?: 'buy_sell'));

        if ($config->marketType() === 'spot') {
            if ($bias === 'bull' && $exposure === 'flat') {
                return new StrategySignal(RatioBotDecision::DECISION_BUY, $reason, $metrics);
            }
            if ($bias === 'bear' && $exposure === 'long') {
                return new StrategySignal(RatioBotDecision::DECISION_SELL, $reason, $metrics);
            }

            return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
        }

        if ($sideMode === 'long_only') {
            if ($bias === 'bull' && $exposure === 'flat') {
                return new StrategySignal(RatioBotDecision::DECISION_LONG, $reason, $metrics);
            }
            if ($bias === 'bear' && $exposure === 'long') {
                return new StrategySignal(RatioBotDecision::DECISION_EXIT_LONG, $reason, $metrics);
            }

            return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
        }

        if ($sideMode === 'short_only') {
            if ($bias === 'bear' && $exposure === 'flat') {
                return new StrategySignal(RatioBotDecision::DECISION_SHORT, $reason, $metrics);
            }
            if ($bias === 'bull' && $exposure === 'short') {
                return new StrategySignal(RatioBotDecision::DECISION_EXIT_SHORT, $reason, $metrics);
            }

            return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
        }

        if ($bias === 'bull') {
            if ($exposure === 'flat') {
                return new StrategySignal(RatioBotDecision::DECISION_LONG, $reason, $metrics);
            }
            if ($exposure === 'short' && (bool) $config->close_on_opposite_signal) {
                return new StrategySignal(
                    (bool) $config->allow_reverse_signal ? RatioBotDecision::DECISION_LONG : RatioBotDecision::DECISION_EXIT_SHORT,
                    $reason,
                    $metrics + ['reversal' => (bool) $config->allow_reverse_signal]
                );
            }

            return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
        }

        if ($bias === 'bear') {
            if ($exposure === 'flat') {
                return new StrategySignal(RatioBotDecision::DECISION_SHORT, $reason, $metrics);
            }
            if ($exposure === 'long' && (bool) $config->close_on_opposite_signal) {
                return new StrategySignal(
                    (bool) $config->allow_reverse_signal ? RatioBotDecision::DECISION_SHORT : RatioBotDecision::DECISION_EXIT_LONG,
                    $reason,
                    $metrics + ['reversal' => (bool) $config->allow_reverse_signal]
                );
            }

            return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
        }

        return StrategySignal::hold($reason, $metrics + ['exposure' => $exposure]);
    }

    protected function avg(array $values): float
    {
        $count = count($values);

        return $count > 0 ? array_sum($values) / $count : 0.0;
    }
}
