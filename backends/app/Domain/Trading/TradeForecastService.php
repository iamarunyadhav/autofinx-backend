<?php

namespace App\Domain\Trading;

class TradeForecastService
{
    public function __construct(
        private readonly TimeframeRegistry $timeframes,
        private readonly CandleDirectionBotService $candleBot,
    ) {
    }

    /**
     * Near-term price forecast from recent candles, an optional trailing
     * decision (action/confidence), and the horizon the caller cares about.
     *
     * Ported from RobotSessionService::robotForecast() — session-independent,
     * used by both Robot Trade (via RobotSessionService) and Quick Trade.
     *
     * @param  array<array{open:mixed,high:mixed,low:mixed,close:mixed}>  $candles
     * @param  array{action?:mixed,confidence?:mixed}|null  $decision
     */
    public function forecast(array $candles, string $timeframe, int $horizonSeconds, ?array $decision = null, ?array $volatility = null): array
    {
        $currentPrice = (float) (end($candles)['close'] ?? 0);

        if (count($candles) < 10 || $currentPrice <= 0) {
            return [
                'available' => false,
                'summary' => 'Not enough fresh candles to estimate the next move.',
            ];
        }

        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        // Chart timeframe supplies the context; requested trade duration is
        // the forecast target. Never silently stretch a 5m trade forecast to
        // 15m merely because the user is viewing a 15m chart.
        $horizonSeconds = max(5, $horizonSeconds ?: $tfSeconds);
        $horizonCandles = max(1, (int) ceil($horizonSeconds / $tfSeconds));
        $recent = array_slice($candles, -min(100, count($candles)));
        $closes = array_map(static fn ($c) => (float) ($c['close'] ?? 0), $recent);
        $lastSlice = array_slice($recent, -min(12, count($recent)));
        $bull = 0;
        $bear = 0;
        foreach ($lastSlice as $candle) {
            $direction = $this->candleBot->candleDirection($candle);
            if ($direction === 'BUY') {
                $bull++;
            } elseif ($direction === 'SELL') {
                $bear++;
            }
        }

        $firstClose = (float) ($closes[0] ?? 0);
        $lastClose = (float) end($closes);
        $driftPct = $firstClose > 0 ? (($lastClose - $firstClose) / $firstClose) * 100 : 0.0;
        $atrPct = max(0.01, (float) ($volatility['atr_pct'] ?? 0) ?: $this->computeAtrAvgPct($recent));
        $rangePct = min(5.0, $atrPct * sqrt($horizonCandles));
        $decisionAction = strtoupper((string) data_get($decision, 'action', 'HOLD'));
        $confidence = (float) data_get($decision, 'confidence', 0);
        $confidencePct = $confidence <= 1 ? $confidence * 100 : $confidence;

        $biasScore = ($bull - $bear) + ($driftPct > 0 ? 1 : ($driftPct < 0 ? -1 : 0));
        if ($decisionAction === 'BUY') {
            $biasScore += 2;
        } elseif ($decisionAction === 'SELL') {
            $biasScore -= 2;
        }

        $bias = $biasScore >= 2 ? 'BUY' : ($biasScore <= -2 ? 'SELL' : 'NEUTRAL');
        $quality = min(95, max(35, 45 + abs($biasScore) * 8 + min(20, $confidencePct / 5)));
        $expectedMid = $bias === 'BUY'
            ? $currentPrice * (1 + ($rangePct * 0.35) / 100)
            : ($bias === 'SELL' ? $currentPrice * (1 - ($rangePct * 0.35) / 100) : $currentPrice);

        return [
            'available' => true,
            'horizon_seconds' => $horizonSeconds,
            'horizon_candles' => $horizonCandles,
            'context_timeframe' => $timeframe,
            'context_timeframe_seconds' => $tfSeconds,
            'resolution_warning' => $horizonSeconds < $tfSeconds
                ? 'Forecast horizon is shorter than chart candle resolution; use a chart timeframe at or below the trade duration for the most directly observable context.' : null,
            'bias' => $bias,
            'confidence' => round($quality, 1),
            'current_price' => round($currentPrice, 8),
            'expected_mid_price' => round($expectedMid, 8),
            'expected_low' => round($currentPrice * (1 - $rangePct / 100), 8),
            'expected_high' => round($currentPrice * (1 + $rangePct / 100), 8),
            'atr_pct' => round($atrPct, 4),
            'recent_bull_candles' => $bull,
            'recent_bear_candles' => $bear,
            'drift_pct' => round($driftPct, 4),
            'summary' => $bias === 'NEUTRAL'
                ? 'Near-term forecast is balanced; wait for stronger direction.'
                : "Near-term bias favors {$bias}, based on recent candles, ATR and latest robot decision.",
        ];
    }

    /**
     * Expected move vs. fee+slippage cost for a given stake — the "is this
     * trade worth it after costs" edge estimate.
     *
     * Ported from RobotSessionService::robotNetEdgeEstimate(). No stake
     * flooring here — callers apply their own minimum-stake rules before
     * calling in, so the numbers always reflect the stake actually at risk.
     *
     * Adds `actual_cost_usdt` / `expected_net_usdt_actual` alongside the
     * original (safety-buffered) fields: the original `expected_net_usdt`
     * uses a 2x-cost safety margin meant as an entry gate, not a literal
     * profit estimate — the `_actual` fields use the real round-trip cost.
     *
     * @param  array<array{high:mixed,low:mixed,close:mixed}>  $candles
     */
    public function netEdge(array $candles, float $stake): array
    {
        $atrPct = $this->computeAtrAvgPct($candles);
        $expectedMovePct = max(0.0, $atrPct * 0.35);

        $feeRate = max(0.0, (float) config('trading.binance_futures_taker_fee_rate', 0.0005));
        $slippageBufferPct = max(0.0, (float) config('robot_trade.live_slippage_buffer_pct', 0.04));
        $costPct = ($feeRate * 2 * 100) + $slippageBufferPct;
        $requiredMovePct = $costPct * max(1.0, (float) config('robot_trade.live_min_edge_cost_multiplier', 2.0));

        $expectedGrossUsdt = $stake * ($expectedMovePct / 100);
        $requiredCostUsdt = $stake * ($requiredMovePct / 100);
        $expectedNetUsdt = $expectedGrossUsdt - $requiredCostUsdt;
        $minExpectedNetUsdt = max(0.0, (float) config('robot_trade.live_min_expected_net_usdt', 0.03));

        $actualCostUsdt = $stake * ($costPct / 100);
        $expectedNetUsdtActual = $expectedGrossUsdt - $actualCostUsdt;

        $result = [
            'tradeable' => $expectedMovePct >= $requiredMovePct && $expectedNetUsdt >= $minExpectedNetUsdt,
            'stake' => round($stake, 8),
            'atr_pct' => round($atrPct, 4),
            'expected_move_pct' => round($expectedMovePct, 4),
            'fee_rate' => round($feeRate, 6),
            'round_trip_fee_pct' => round($feeRate * 2 * 100, 4),
            'slippage_buffer_pct' => round($slippageBufferPct, 4),
            'required_move_pct' => round($requiredMovePct, 4),
            'expected_gross_usdt' => round($expectedGrossUsdt, 8),
            'required_cost_usdt' => round($requiredCostUsdt, 8),
            'expected_net_usdt' => round($expectedNetUsdt, 8),
            'min_expected_net_usdt' => round($minExpectedNetUsdt, 8),
            'actual_cost_usdt' => round($actualCostUsdt, 8),
            'expected_net_usdt_actual' => round($expectedNetUsdtActual, 8),
        ];

        if (! $result['tradeable']) {
            $result['reason'] = sprintf(
                'Expected move %.4f%% / net %.4f USDT is below live fee/slippage requirement %.4f%% / %.4f USDT.',
                $expectedMovePct,
                $expectedNetUsdt,
                $requiredMovePct,
                $minExpectedNetUsdt
            );
        }

        return $result;
    }

    /**
     * Average ATR as % of price over the last 20 periods.
     *
     * @param  array<array{high:mixed,low:mixed,close:mixed}>  $candles
     */
    public function computeAtrAvgPct(array $candles): float
    {
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $trs    = [];
        $count  = min(count($candles), 21);
        for ($i = 1; $i < $count; $i++) {
            $trs[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        $last = (float) end($closes);
        return (count($trs) > 0 && $last > 0) ? (array_sum($trs) / count($trs) / $last) * 100 : 0.0;
    }

    /**
     * Adapts SignalPreviewService::preview()'s shape (`direction`: lowercase
     * buy/sell/neutral) into the `{action, confidence}` shape forecast()
     * expects (`action`: uppercase BUY/SELL/HOLD) — without this, passing
     * a signal preview straight into forecast() silently degrades to HOLD.
     *
     * @param  array{direction?:mixed,confidence?:mixed}  $signalPreview
     */
    public function decisionFromSignalPreview(array $signalPreview): array
    {
        $direction = strtolower((string) ($signalPreview['direction'] ?? 'neutral'));

        return [
            'action' => $direction === 'buy' ? 'BUY' : ($direction === 'sell' ? 'SELL' : 'HOLD'),
            'confidence' => (float) ($signalPreview['confidence'] ?? 0),
        ];
    }
}
