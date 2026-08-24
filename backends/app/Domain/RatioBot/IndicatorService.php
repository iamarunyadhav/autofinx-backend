<?php

namespace App\Domain\RatioBot;

use App\Domain\Market\Indicators\Indicators;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;

class IndicatorService
{
    public function snapshot(RatioBotConfig $config, float $currentPrice): array
    {
        $params = (array) ($config->strategy_params ?? []);
        $lookback = max(30, (int) ($params['indicator_lookback_candles'] ?? 80));
        $candles = MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $config->timeFrame())
            ->orderByDesc('ts')
            ->limit($lookback)
            ->get()
            ->reverse()
            ->values()
            ->map(static fn (MarketOhlc $candle) => [
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
            ])
            ->all();

        $closes = array_map(static fn (array $candle) => (float) $candle['close'], $candles);
        $volumes = array_map(static fn (array $candle) => (float) $candle['volume'], $candles);
        $last = $candles !== [] ? $candles[array_key_last($candles)] : null;
        $resolvedPrice = $currentPrice > 0 ? $currentPrice : (float) ($last['close'] ?? 0);
        $sufficientData = count($candles) >= 30 && $resolvedPrice > 0;

        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $previous = (float) $closes[$i - 1];
            $current = (float) $closes[$i];
            if ($previous <= 0 || $current <= 0) {
                continue;
            }
            $returns[] = abs(($current / $previous) - 1);
        }

        $rangeSamples = array_map(
            static fn (array $candle) => ((float) $candle['close']) > 0
                ? (((float) $candle['high'] - (float) $candle['low']) / (float) $candle['close'])
                : 0.0,
            array_slice($candles, -20)
        );

        // The average must be over the candles PRECEDING the current one —
        // including the current candle's own volume in its baseline biases
        // the ratio toward 1.0 and understates real spikes/droughts.
        $priorVolumes = count($volumes) > 1 ? array_slice($volumes, 0, -1) : [];
        $volumeWindow = array_slice($priorVolumes, -20);
        $volumeAverage = $this->avg($volumeWindow);
        $currentVolume = (float) ($last['volume'] ?? 0);
        $volumeRatio = $volumeAverage > 0 ? $currentVolume / $volumeAverage : null;

        $atr = Indicators::atr($candles, 14);
        $atrPct = ($atr !== null && $resolvedPrice > 0) ? $atr / $resolvedPrice : null;
        $ema9 = Indicators::ema($closes, 9);
        $ema21 = Indicators::ema($closes, 21);
        $ema50 = Indicators::ema($closes, 50);
        $rsi = Indicators::rsi($closes, 14);
        $macd = Indicators::macd($closes);
        $adx = Indicators::adx($candles, 14);
        $spreadRatio = $last && (float) ($last['close'] ?? 0) > 0
            ? (((float) $last['high'] - (float) $last['low']) / (float) $last['close'])
            : null;

        $trendStrength = ($ema9 !== null && $ema21 !== null && $resolvedPrice > 0)
            ? abs($ema9 - $ema21) / $resolvedPrice
            : null;
        $averageReturn = $this->avg(array_slice($returns, -20));
        $latestAbsReturn = $returns !== [] ? (float) end($returns) : null;
        $averageRangePct = $this->avg($rangeSamples);
        $liquidityNotional = $this->avg(array_map(
            static fn (array $candle) => (float) $candle['close'] * (float) $candle['volume'],
            array_slice($candles, -20)
        ));

        $marketMode = 'UNKNOWN';
        if ($sufficientData) {
            if (($adx !== null && $adx >= 25) || ($trendStrength !== null && $trendStrength >= max(0.0025, (float) ($atrPct ?? 0) * 0.25))) {
                $marketMode = 'TRENDING';
            } elseif (($atrPct ?? 0.0) >= 0.012 || $averageRangePct >= 0.012) {
                $marketMode = 'VOLATILE';
            } else {
                $marketMode = 'SIDEWAYS';
            }
        }

        $volatilityScore = max(
            (float) ($atrPct ?? 0),
            (float) $averageReturn,
            (float) $averageRangePct,
        );
        $volatilityLevel = match (true) {
            $volatilityScore >= 0.018 => 'EXTREME',
            $volatilityScore >= 0.010 => 'HIGH',
            $volatilityScore >= 0.004 => 'MEDIUM',
            default => 'LOW',
        };

        return [
            'sufficient_data' => $sufficientData,
            'candles_analyzed' => count($candles),
            'price' => $resolvedPrice,
            'ema_9' => $ema9,
            'ema_21' => $ema21,
            'ema_50' => $ema50,
            'rsi' => $rsi,
            'macd_line' => $macd['line'] ?? null,
            'macd_signal' => $macd['signal'] ?? null,
            'macd_histogram' => $macd['histogram'] ?? null,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'adx' => $adx,
            'volume_average' => $volumeAverage,
            'current_volume' => $currentVolume,
            'volume_ratio' => $volumeRatio,
            'spread_ratio' => $spreadRatio,
            'average_range_pct' => $averageRangePct,
            'average_abs_return' => $averageReturn,
            'latest_abs_return' => $latestAbsReturn,
            'trend_strength' => $trendStrength,
            'liquidity_notional' => $liquidityNotional,
            'market_mode' => $marketMode,
            'volatility_level' => $volatilityLevel,
        ];
    }

    public function confirm(RatioBotConfig $config, string $decision, float $currentPrice, array $snapshot = [], array $context = []): array
    {
        $params = (array) ($config->strategy_params ?? []);
        $enabled = (bool) ($params['use_indicator_confirmation'] ?? false);
        if (! $enabled) {
            return [
                'enabled' => false,
                'ok' => true,
                'reasons' => [],
                'metrics' => $snapshot,
            ];
        }

        if (! (bool) ($snapshot['sufficient_data'] ?? false)) {
            return [
                'enabled' => true,
                'ok' => false,
                'reasons' => ['insufficient_indicator_data'],
                'metrics' => $snapshot,
            ];
        }

        $metadata = (array) ($context['decision_metadata'] ?? []);
        $protectiveExit = (bool) ($metadata['protective_exit'] ?? false);
        $trigger = (string) ($metadata['trigger'] ?? '');
        if (! $protectiveExit && in_array($trigger, ['stop_loss', 'time_stop'], true)) {
            $protectiveExit = true;
        }

        $minVolumeRatio = max(0.0, (float) ($params['min_volume_ratio'] ?? 0.6));
        $maxSpreadRatio = max(0.0, (float) ($params['max_spread_ratio'] ?? 0.008));
        $minLiquidity = max(0.0, (float) ($params['min_liquidity_notional'] ?? 0));
        $maxSpikeRatio = max(0.0, (float) ($params['max_indicator_spike_ratio'] ?? 0.03));
        $allowSideways = (bool) ($params['allow_sideways_market'] ?? false);
        $allowExtremeVolatility = (bool) ($params['allow_extreme_volatility'] ?? false);
        $requireTrendAlignment = (bool) ($params['require_trend_alignment'] ?? true);
        $rsiOverbought = (float) ($params['rsi_overbought'] ?? 70);
        $rsiOversold = (float) ($params['rsi_oversold'] ?? 30);

        $reasons = [];

        if (! $protectiveExit) {
            if (($snapshot['volume_ratio'] ?? null) !== null && (float) $snapshot['volume_ratio'] < $minVolumeRatio) {
                $reasons[] = 'volume_too_low';
            }
            if (($snapshot['spread_ratio'] ?? null) !== null && (float) $snapshot['spread_ratio'] > $maxSpreadRatio) {
                $reasons[] = 'spread_too_high';
            }
            if ($minLiquidity > 0 && (float) ($snapshot['liquidity_notional'] ?? 0) < $minLiquidity) {
                $reasons[] = 'liquidity_too_low';
            }
            if (($snapshot['latest_abs_return'] ?? null) !== null && (float) $snapshot['latest_abs_return'] > $maxSpikeRatio) {
                $reasons[] = 'candle_spike_abnormal';
            }
            if (! $allowSideways && (string) ($snapshot['market_mode'] ?? '') === 'SIDEWAYS') {
                $reasons[] = 'market_too_choppy';
            }
            if (! $allowExtremeVolatility && (string) ($snapshot['volatility_level'] ?? '') === 'EXTREME') {
                $reasons[] = 'abnormal_volatility';
            }
        }

        $ema9 = (float) ($snapshot['ema_9'] ?? 0);
        $ema21 = (float) ($snapshot['ema_21'] ?? 0);
        $ema50 = (float) ($snapshot['ema_50'] ?? 0);
        $rsi = $snapshot['rsi'] !== null ? (float) $snapshot['rsi'] : null;
        $macdHistogram = $snapshot['macd_histogram'] !== null ? (float) $snapshot['macd_histogram'] : null;

        switch (strtoupper($decision)) {
            case 'BUY':
            case 'LONG':
                if ($rsi !== null && $rsi >= $rsiOverbought) {
                    $reasons[] = 'rsi_overbought';
                }
                if ($requireTrendAlignment && $ema9 > 0 && $ema21 > 0 && $ema50 > 0) {
                    if ($ema9 < $ema21 && $ema21 < $ema50 && ($macdHistogram ?? -1) <= 0) {
                        $reasons[] = 'bearish_trend_alignment';
                    }
                }
                break;

            case 'SELL':
                if (! $protectiveExit && $rsi !== null && $rsi <= $rsiOversold && (string) ($snapshot['market_mode'] ?? '') === 'TRENDING') {
                    $reasons[] = 'sell_into_oversold';
                }
                break;

            case 'SHORT':
                if ($rsi !== null && $rsi <= $rsiOversold) {
                    $reasons[] = 'rsi_oversold';
                }
                if ($requireTrendAlignment && $ema9 > 0 && $ema21 > 0 && $ema50 > 0) {
                    if ($ema9 > $ema21 && $ema21 > $ema50 && ($macdHistogram ?? 1) >= 0) {
                        $reasons[] = 'bullish_trend_alignment';
                    }
                }
                break;
        }

        return [
            'enabled' => true,
            'ok' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'metrics' => $snapshot + [
                'confirmed_decision' => strtoupper($decision),
                'protective_exit' => $protectiveExit,
                'current_price' => $currentPrice,
            ],
        ];
    }

    private function avg(array $values): float
    {
        $count = count($values);

        return $count > 0 ? array_sum($values) / $count : 0.0;
    }
}
