<?php

namespace App\Domain\Trading;

/**
 * Thin adapter that turns the existing {@see SignalPreviewService} output into a
 * direction signal the Candle Direction Bot can act on. Keeps the bot decoupled
 * from the scoring internals so the underlying indicator engine can evolve.
 */
class CandleSignalAdapter
{
    public function __construct(private readonly SignalPreviewService $signals)
    {
    }

    /**
     * Resolve a directional signal for a set of candles.
     *
     * @param  array<int, array<string, mixed>>  $candles  ordered oldest -> newest
     * @param  array<int, string>  $indicators
     * @return array{side: 'BUY'|'SELL'|'NEUTRAL', confidence: float, reasons: array<int, string>, raw: array<string, mixed>}
     */
    public function getDirectionSignal(array $candles, string $timeframe, array $indicators = []): array
    {
        $preview = $this->signals->preview($candles, $timeframe, $indicators);

        $direction = (string) ($preview['direction'] ?? 'neutral');
        $side = match (strtolower($direction)) {
            'buy' => 'BUY',
            'sell' => 'SELL',
            default => 'NEUTRAL',
        };

        return [
            'side' => $side,
            'confidence' => round(((float) ($preview['confidence'] ?? 0)) / 100, 4),
            'reasons' => array_values((array) ($preview['reasons'] ?? [])),
            'raw' => $preview,
        ];
    }

    /**
     * Pure candle-direction signal — the heart of the Candle Direction Bot.
     * Direction comes from the latest closed candle (close vs open); confidence
     * scales with the candle body relative to its range, so a strong, decisive
     * candle reads high and a doji reads neutral. Selected indicators only nudge
     * confidence (agree -> boost, disagree -> trim); they never veto the candle.
     *
     * @param  array<int, array<string, mixed>>  $candles  ordered oldest -> newest
     * @param  array<int, string>  $indicators
     * @return array{side: 'BUY'|'SELL'|'NEUTRAL', confidence: float, reasons: array<int, string>, raw: array<string, mixed>}
     */
    public function getCandleDirectionSignal(array $candles, string $timeframe = '1m', array $indicators = []): array
    {
        if ($candles === []) {
            return ['side' => 'NEUTRAL', 'confidence' => 0.0, 'reasons' => ['No candles.'], 'raw' => []];
        }

        $c = $candles[array_key_last($candles)];
        $open = (float) ($c['open'] ?? 0);
        $close = (float) ($c['close'] ?? 0);
        $high = (float) ($c['high'] ?? 0);
        $low = (float) ($c['low'] ?? 0);
        $range = max($high - $low, 1e-9);
        $bodyRatio = min(1.0, abs($close - $open) / $range);

        if ($bodyRatio < 0.05) {
            return [
                'side' => 'NEUTRAL',
                'confidence' => round(0.5 + $bodyRatio, 4),
                'reasons' => ['Doji / indecision candle.'],
                'raw' => ['body_ratio' => round($bodyRatio, 4)],
            ];
        }

        $side = $close > $open ? 'BUY' : 'SELL';
        // 0.5 floor + up to 0.5 from body strength -> a clear candle reads 0.7-0.99.
        $confidence = 0.5 + $bodyRatio * 0.5;
        $reasons = [sprintf('%s candle, body %.0f%% of range.', $side === 'BUY' ? 'Bullish' : 'Bearish', $bodyRatio * 100)];

        // Optional indicator confirmation (does not block the candle direction).
        if ($indicators !== []) {
            $preview = $this->signals->preview($candles, $timeframe, $indicators);
            $indicatorSide = match (strtolower((string) ($preview['direction'] ?? 'neutral'))) {
                'buy' => 'BUY',
                'sell' => 'SELL',
                default => 'NEUTRAL',
            };
            if ($indicatorSide === $side) {
                $confidence = min(0.99, $confidence + 0.1);
                $reasons[] = 'Indicators agree.';
            } elseif ($indicatorSide !== 'NEUTRAL') {
                $confidence = max(0.5, $confidence - 0.1);
                $reasons[] = 'Indicators diverge (candle still leads).';
            }
        }

        return [
            'side' => $side,
            'confidence' => round(min(0.99, $confidence), 4),
            'reasons' => $reasons,
            'raw' => ['body_ratio' => round($bodyRatio, 4), 'open' => $open, 'close' => $close],
        ];
    }

    /**
     * Whether the signal clears the session's confidence threshold (0-100 scale).
     */
    public function isActionable(array $signal, int $confidenceThreshold): bool
    {
        return $signal['side'] !== 'NEUTRAL'
            && ($signal['confidence'] * 100) >= $confidenceThreshold;
    }
}
