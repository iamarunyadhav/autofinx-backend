<?php

namespace App\Domain\Trading;

/**
 * Thin adapter that turns the existing {@see SignalPreviewService} output into a
 * direction signal the Candle Direction Bot can act on. Keeps the bot decoupled
 * from the scoring internals so the underlying indicator engine can evolve.
 *
 * getCandleDirectionSignal() now delegates to {@see CandlePatternAnalyzer} for
 * full multi-candle pattern recognition (single, double, triple, trend) before
 * optionally nudging with indicator consensus.
 */
class CandleSignalAdapter
{
    public function __construct(
        private readonly SignalPreviewService $signals,
        private readonly CandlePatternAnalyzer $patternAnalyzer,
    ) {
    }

    /**
     * Resolve a directional signal using indicator consensus only (original path).
     * Used by the timed-expiry bot.
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
     * Master-brain candle direction signal — the heart of the Candle Direction Bot.
     *
     * Runs the full {@see CandlePatternAnalyzer} pipeline:
     *   1. Single-candle patterns (Marubozu, Hammer, Doji variants…)
     *   2. Double-candle patterns (Engulfing, Piercing, Harami, Kicker…)
     *   3. Triple-candle patterns (Morning/Evening Star, 3-Soldiers/Crows…)
     *   4. Trend context (last ≤ 10 candles: streak, higher-highs, bias)
     *
     * Confidence output:
     *   - floor 0.60 for any directional candle
     *   - +0.07–0.22 for confirmed patterns
     *   - ±0.06 for trend alignment / counter-trend
     *   - capped at 0.99
     *
     * Indicator set is applied LAST as an optional nudge (±0.05) — never blocks.
     *
     * @param  array<int, array<string, mixed>>  $candles  ordered oldest -> newest
     * @param  array<int, string>  $indicators
     * @return array{side: 'BUY'|'SELL'|'NEUTRAL', confidence: float, pattern: string, reasons: array<int, string>, raw: array<string, mixed>}
     */
    public function getCandleDirectionSignal(array $candles, string $timeframe = '1m', array $indicators = []): array
    {
        if ($candles === []) {
            return ['side' => 'NEUTRAL', 'confidence' => 0.0, 'pattern' => 'none', 'reasons' => ['No candles.'], 'raw' => []];
        }

        // Full pattern analysis.
        $analysis = $this->patternAnalyzer->analyze($candles);

        $side = $analysis['side'];
        $confidence = $analysis['confidence'];
        $reasons = $analysis['reasons'];

        // Optional indicator confirmation — small nudge only, never vetoes pattern.
        if ($indicators !== [] && $side !== 'NEUTRAL') {
            $preview = $this->signals->preview($candles, $timeframe, $indicators);
            $indicatorSide = match (strtolower((string) ($preview['direction'] ?? 'neutral'))) {
                'buy' => 'BUY',
                'sell' => 'SELL',
                default => 'NEUTRAL',
            };
            if ($indicatorSide === $side) {
                $confidence = min(0.99, $confidence + 0.05);
                $reasons[] = 'Indicators agree.';
            } elseif ($indicatorSide !== 'NEUTRAL') {
                $confidence = max(0.40, $confidence - 0.05);
                $reasons[] = 'Indicators diverge (pattern leads).';
            }
        }

        $c = $candles[array_key_last($candles)];

        return [
            'side' => $side,
            'confidence' => round(min(0.99, $confidence), 4),
            'pattern' => $analysis['pattern'],
            'pattern_type' => $analysis['pattern_type'],
            'pattern_bias' => $analysis['pattern_bias'],
            'trend_bias' => $analysis['trend_bias'],
            'trend_streak' => $analysis['trend_streak'],
            'reasons' => $reasons,
            'score_breakdown' => $analysis['score_breakdown'],
            'raw' => [
                'open' => (float) ($c['open'] ?? 0),
                'close' => (float) ($c['close'] ?? 0),
                'body_ratio' => $analysis['score_breakdown']['raw_conf'] ?? 0,
            ],
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
