<?php

namespace App\Domain\Trading;

use App\Domain\Market\Indicators\Indicators;

class SignalPreviewService
{
    public function __construct(private readonly TimeframeRegistry $timeframes)
    {
    }

    public function preview(array $candles, string $timeframe, array $indicatorSet = []): array
    {
        $normalizedIndicators = collect($indicatorSet)
            ->map(fn ($indicator) => strtoupper((string) $indicator))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalizedIndicators === []) {
            $normalizedIndicators = ['RSI', 'MACD', 'BOLLINGER'];
        }

        $closes = array_values(array_map(fn (array $row) => (float) ($row['close'] ?? 0), $candles));
        $latestPrice = $closes !== [] ? end($closes) : 0.0;

        $bullish = 0.0;
        $bearish = 0.0;
        $neutral = 0.0;
        $bullishReasons = [];
        $bearishReasons = [];
        $indicatorValues = [];
        $timeframeFactor = $this->periodFactor($timeframe);

        $fastTrend = Indicators::ema($closes, max(4, (int) round(9 * $timeframeFactor)));
        $slowTrend = Indicators::ema($closes, max(6, (int) round(21 * $timeframeFactor)));
        if ($fastTrend !== null && $slowTrend !== null) {
            if ($fastTrend > $slowTrend) {
                $bullish += 8;
                $bullishReasons[] = 'Short-term EMA trend is rising.';
            } elseif ($fastTrend < $slowTrend) {
                $bearish += 8;
                $bearishReasons[] = 'Short-term EMA trend is falling.';
            } else {
                $neutral += 4;
            }
        }

        if (in_array('RSI', $normalizedIndicators, true)) {
            $rsi = Indicators::rsi($closes, max(5, (int) round(14 * $timeframeFactor)));
            $indicatorValues['RSI'] = $rsi !== null ? round($rsi, 2) : null;
            if ($rsi !== null) {
                if ($rsi <= 35) {
                    $bullish += 24;
                    $bullishReasons[] = "RSI is oversold at {$indicatorValues['RSI']}.";
                } elseif ($rsi >= 65) {
                    $bearish += 24;
                    $bearishReasons[] = "RSI is overbought at {$indicatorValues['RSI']}.";
                } else {
                    $neutral += 8;
                }
            }
        }

        if (in_array('MACD', $normalizedIndicators, true)) {
            $macd = Indicators::macd(
                $closes,
                max(5, (int) round(12 * $timeframeFactor)),
                max(8, (int) round(26 * $timeframeFactor)),
                max(4, (int) round(9 * $timeframeFactor))
            );
            $indicatorValues['MACD'] = $macd ? [
                'line' => round((float) $macd['line'], 6),
                'signal' => round((float) $macd['signal'], 6),
                'histogram' => round((float) $macd['histogram'], 6),
            ] : null;
            if ($macd) {
                if ($macd['line'] > $macd['signal']) {
                    $bullish += 26;
                    $bullishReasons[] = 'MACD line is above the signal line.';
                } elseif ($macd['line'] < $macd['signal']) {
                    $bearish += 26;
                    $bearishReasons[] = 'MACD line is below the signal line.';
                } else {
                    $neutral += 6;
                }
            }
        }

        if (in_array('BOLLINGER', $normalizedIndicators, true)) {
            $bands = Indicators::bollinger($closes, max(8, (int) round(20 * $timeframeFactor)), 2.0);
            $indicatorValues['BOLLINGER'] = $bands ? [
                'upper' => round((float) $bands['upper'], 4),
                'middle' => round((float) $bands['middle'], 4),
                'lower' => round((float) $bands['lower'], 4),
            ] : null;
            if ($bands) {
                if ($latestPrice <= $bands['lower']) {
                    $bullish += 18;
                    $bullishReasons[] = 'Price is pressing the lower Bollinger band.';
                } elseif ($latestPrice >= $bands['upper']) {
                    $bearish += 18;
                    $bearishReasons[] = 'Price is pressing the upper Bollinger band.';
                } else {
                    $neutral += 10;
                }
            }
        }

        if (in_array('CCI', $normalizedIndicators, true)) {
            $cci = Indicators::cci($candles, max(8, (int) round(20 * $timeframeFactor)));
            $indicatorValues['CCI'] = $cci !== null ? round($cci, 2) : null;
            if ($cci !== null) {
                if ($cci <= -100) {
                    $bullish += 14;
                    $bullishReasons[] = "CCI is stretched lower at {$indicatorValues['CCI']}.";
                } elseif ($cci >= 100) {
                    $bearish += 14;
                    $bearishReasons[] = "CCI is stretched higher at {$indicatorValues['CCI']}.";
                } else {
                    $neutral += 6;
                }
            }
        }

        if (in_array('STOCHASTIC', $normalizedIndicators, true)) {
            $stochastic = Indicators::stochastic(
                $candles,
                max(5, (int) round(14 * $timeframeFactor)),
                max(3, (int) round(3 * $timeframeFactor))
            );
            $indicatorValues['STOCHASTIC'] = $stochastic ? [
                'k' => round((float) $stochastic['k'], 2),
                'd' => round((float) $stochastic['d'], 2),
            ] : null;
            if ($stochastic) {
                if ($stochastic['k'] <= 20 && $stochastic['d'] <= 20) {
                    $bullish += 14;
                    $bullishReasons[] = 'Stochastic is in oversold territory.';
                } elseif ($stochastic['k'] >= 80 && $stochastic['d'] >= 80) {
                    $bearish += 14;
                    $bearishReasons[] = 'Stochastic is in overbought territory.';
                } else {
                    $neutral += 6;
                }
            }
        }

        $scores = [
            'buy' => round($bullish, 2),
            'sell' => round($bearish, 2),
            'neutral' => round($neutral, 2),
        ];
        $dominantKey = collect($scores)->sortDesc()->keys()->first() ?: 'neutral';
        $totalScore = max(1.0, $bullish + $bearish + $neutral);
        $dominantScore = (float) ($scores[$dominantKey] ?? 0);
        $spread = abs($bullish - $bearish);
        $direction = $dominantKey === 'neutral' || $spread < 8 ? 'neutral' : $dominantKey;
        $confidence = (int) round(($dominantScore / $totalScore) * 100);

        return [
            'direction' => $direction,
            'confidence' => $direction === 'neutral' ? min($confidence, 55) : $confidence,
            'bullish_score' => $scores['buy'],
            'bearish_score' => $scores['sell'],
            'neutral_score' => $scores['neutral'],
            'supporting_indicators' => $direction === 'sell' ? $bearishReasons : $bullishReasons,
            'conflicting_indicators' => $direction === 'sell' ? $bullishReasons : $bearishReasons,
            'reasons' => $direction === 'neutral'
                ? array_slice(array_merge($bullishReasons, $bearishReasons), 0, 4)
                : array_slice($direction === 'sell' ? $bearishReasons : $bullishReasons, 0, 4),
            'indicator_values' => $indicatorValues,
            'timeframe' => $timeframe,
        ];
    }

    private function periodFactor(string $timeframe): float
    {
        $seconds = $this->timeframes->seconds($timeframe);

        return match (true) {
            $seconds <= 15 => 0.65,
            $seconds <= 60 => 1.0,
            $seconds <= 300 => 1.25,
            default => 1.5,
        };
    }
}
