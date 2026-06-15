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
     * Whether the signal clears the session's confidence threshold (0-100 scale).
     */
    public function isActionable(array $signal, int $confidenceThreshold): bool
    {
        return $signal['side'] !== 'NEUTRAL'
            && ($signal['confidence'] * 100) >= $confidenceThreshold;
    }
}
