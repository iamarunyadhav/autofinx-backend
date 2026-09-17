<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\Market\Indicators\Indicators;
use App\Models\MarketOhlc;

/**
 * Groups RSI+Stochastic ("momentum"), MACD ("trend-confirmation"), and
 * Bollinger+CCI ("volatility/range") into three independent votes, each in
 * [-1, 1], reusing App\Domain\Market\Indicators\Indicators's existing
 * static methods verbatim (all already real/tested elsewhere -- this class
 * adds no new indicator math, only normalization + grouping).
 *
 * Anti-double-counting (Unified Forecast Engine plan, item 5): a GROUP's
 * vote is the AVERAGE of its member indicators' votes, never their sum --
 * two correlated indicators (RSI and Stochastic both measure momentum and
 * usually agree) voting the same way must not produce a stronger group
 * vote than either alone would. The final combined score is likewise the
 * average of however many of the three GROUPS actually have data, not a
 * count-weighted sum -- turning more indicators on within one group can
 * never silently inflate the overall reading.
 */
class IndicatorGroupScorer
{
    /** Same directional cutoff MultiWindowTrendScoringService uses for its own per-window votes, for consistency. */
    private const DIRECTION_VOTE_THRESHOLD = 0.3;

    private const RSI_PERIOD = 14;
    private const STOCH_K_PERIOD = 14;
    private const STOCH_D_PERIOD = 3;
    private const CCI_PERIOD = 20;
    private const BOLLINGER_PERIOD = 20;

    /**
     * @return array{
     *   momentum: array{available:bool, vote:float, direction:string, components:array},
     *   trend_confirmation: array{available:bool, vote:float, direction:string, components:array},
     *   volatility_range: array{available:bool, vote:float, direction:string, components:array},
     *   combined_vote: float, combined_direction: string, available_group_count: int,
     * }
     */
    public function scoreFor(string $exchange, string $symbol, string $timeframe, int $candleLimit = 100): array
    {
        $candles = MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->orderByDesc('ts')
            ->limit(max($candleLimit, 60))
            ->get(['open', 'high', 'low', 'close'])
            ->reverse()
            ->values()
            ->map(fn ($candle) => [
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
            ])
            ->all();

        return $this->scoreCandles($candles);
    }

    /**
     * Pure-function variant taking candles directly -- lets tests exercise
     * deterministic, known candle sequences without needing MarketOhlc rows.
     *
     * @param  array<array{open:mixed,high:mixed,low:mixed,close:mixed}>  $candles
     */
    public function scoreCandles(array $candles): array
    {
        $closes = array_map(static fn ($c) => (float) ($c['close'] ?? 0), $candles);

        $momentum = $this->momentumGroup($closes, $candles);
        $trendConfirmation = $this->trendConfirmationGroup($closes);
        $volatilityRange = $this->volatilityRangeGroup($closes, $candles);

        $groups = [$momentum, $trendConfirmation, $volatilityRange];
        $availableVotes = array_column(array_filter($groups, static fn (array $g) => $g['available']), 'vote');

        $combinedVote = $availableVotes === [] ? 0.0 : round(array_sum($availableVotes) / count($availableVotes), 4);
        $combinedDirection = $this->directionFor($combinedVote);

        return [
            'momentum' => $momentum,
            'trend_confirmation' => $trendConfirmation,
            'volatility_range' => $volatilityRange,
            'combined_vote' => $combinedVote,
            'combined_direction' => $combinedDirection,
            'available_group_count' => count($availableVotes),
        ];
    }

    /** @param array<int,array{high:mixed,low:mixed,close:mixed}> $candles */
    private function momentumGroup(array $closes, array $candles): array
    {
        $components = [];
        $votes = [];

        $rsi = Indicators::rsi($closes, self::RSI_PERIOD);
        if ($rsi !== null) {
            $vote = max(-1.0, min(1.0, ($rsi - 50) / 50));
            $components['rsi'] = ['value' => round($rsi, 2), 'vote' => round($vote, 4)];
            $votes[] = $vote;
        }

        $stochastic = Indicators::stochastic($candles, self::STOCH_K_PERIOD, self::STOCH_D_PERIOD);
        if ($stochastic !== null) {
            $vote = max(-1.0, min(1.0, ($stochastic['k'] - 50) / 50));
            $components['stochastic'] = ['value' => $stochastic, 'vote' => round($vote, 4)];
            $votes[] = $vote;
        }

        return $this->packGroup($votes, $components);
    }

    private function trendConfirmationGroup(array $closes): array
    {
        $components = [];
        $votes = [];

        $macd = Indicators::macd($closes);
        if ($macd !== null) {
            // MACD's histogram has no natural bounded scale the way RSI/
            // Stochastic (0-100) or CCI (conventionally +/-100) do, so this
            // group contributes a directional vote (histogram sign) rather
            // than a magnitude-scaled one -- an explicit, documented choice,
            // not an oversight.
            $vote = $macd['histogram'] > 0 ? 1.0 : ($macd['histogram'] < 0 ? -1.0 : 0.0);
            $components['macd'] = ['value' => $macd, 'vote' => $vote];
            $votes[] = $vote;
        }

        return $this->packGroup($votes, $components);
    }

    /** @param array<int,array{high:mixed,low:mixed,close:mixed}> $candles */
    private function volatilityRangeGroup(array $closes, array $candles): array
    {
        $components = [];
        $votes = [];

        $bollinger = Indicators::bollinger($closes, self::BOLLINGER_PERIOD);
        if ($bollinger !== null) {
            $lastClose = (float) end($closes);
            $halfWidth = ($bollinger['upper'] - $bollinger['lower']) / 2;
            $vote = $halfWidth > 0
                ? max(-1.0, min(1.0, ($lastClose - $bollinger['middle']) / $halfWidth))
                : 0.0;
            $components['bollinger'] = ['value' => $bollinger, 'vote' => round($vote, 4)];
            $votes[] = $vote;
        }

        $cci = Indicators::cci($candles, self::CCI_PERIOD);
        if ($cci !== null) {
            $vote = max(-1.0, min(1.0, $cci / 100));
            $components['cci'] = ['value' => round($cci, 2), 'vote' => round($vote, 4)];
            $votes[] = $vote;
        }

        return $this->packGroup($votes, $components);
    }

    private function packGroup(array $votes, array $components): array
    {
        if ($votes === []) {
            return [
                'available' => false,
                'vote' => 0.0,
                'direction' => 'neutral',
                'components' => $components,
            ];
        }

        // AVERAGE, never sum -- the anti-double-counting rule this whole
        // class exists to enforce (see class docblock).
        $vote = round(array_sum($votes) / count($votes), 4);

        return [
            'available' => true,
            'vote' => $vote,
            'direction' => $this->directionFor($vote),
            'components' => $components,
        ];
    }

    private function directionFor(float $vote): string
    {
        if ($vote >= self::DIRECTION_VOTE_THRESHOLD) {
            return 'bullish';
        }
        if ($vote <= -self::DIRECTION_VOTE_THRESHOLD) {
            return 'bearish';
        }

        return 'neutral';
    }
}
