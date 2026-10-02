<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlCandle;
use App\Models\MlShadowPrediction;

/**
 * Live shadow evaluation. Scores each stored prediction against the real
 * closed candle at exactly T+h, and reports forecast metrics and fee-aware
 * decision metrics SEPARATELY, always with their sample counts.
 *
 * Evaluation starts from zero: only ml_shadow_predictions created by this
 * pipeline are ever scored. No historical/manual Quick Trade is read here.
 */
class ShadowEvaluationService
{
    public function __construct(private readonly FundingRateService $funding) {}
    // A target candle still missing this long after T+h is a data hole, not a
    // pending outcome; it is excluded from metrics rather than guessed.
    private const UNSCORABLE_AFTER_MS = 2 * 86_400_000;

    /** @return array{scored:int, unscorable:int} */
    public function score(?string $onlySymbol = null): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $interval = MlCandleCollector::intervalMs($timeframe);
        $nowMs = now('UTC')->getTimestampMs();
        $totals = ['scored' => 0, 'unscorable' => 0];

        MlShadowPrediction::query()->where('status', 'PENDING')->where('target_time_ms', '<=', $nowMs)
            ->when($onlySymbol, fn ($q) => $q->where('symbol', $onlySymbol))
            ->chunkById(500, function ($predictions) use ($timeframe, $interval, $nowMs, &$totals): void {
                foreach ($predictions as $prediction) {
                    $candle = MlCandle::query()->where('provider', (string) config('ml.provider', 'binance_futures'))
                        ->where('symbol', $prediction->symbol)->where('timeframe', $timeframe)
                        ->where('open_time_ms', (int) $prediction->target_time_ms - $interval)->first(['close']);
                    if (! $candle) {
                        if ($nowMs - (int) $prediction->target_time_ms > self::UNSCORABLE_AFTER_MS) {
                            $prediction->forceFill(['status' => 'UNSCORABLE', 'scored_at' => now('UTC')])->save();
                            $totals['unscorable']++;
                        }

                        continue;
                    }
                    $actualReturn = (float) $candle->close / (float) $prediction->current_price - 1;
                    $hasReturn = $prediction->predicted_return !== null;
                    $predicted = (float) $prediction->predicted_return;
                    $costPct = (float) data_get($prediction->cost_snapshot, 'cost_pct', 0);
                    // Funding actually paid/received over (T, T+h] (longs pay, shorts receive).
                    $horizonMs = (int) $prediction->target_time_ms - (int) $prediction->anchor_time_ms;
                    $fundingBuy = $this->funding->buyCostPct($prediction->symbol, [(int) $prediction->anchor_time_ms], $horizonMs)[(int) $prediction->anchor_time_ms] ?? 0.0;
                    $net = match ($prediction->decision) {
                        'BUY' => $actualReturn * 100 - $costPct - $fundingBuy,
                        'SELL' => -$actualReturn * 100 - $costPct + $fundingBuy,
                        default => null,
                    };
                    // Direction: regression = sign of the predicted return;
                    // classifier = the side it took (WAIT has no direction).
                    $direction = $hasReturn ? ($predicted <=> 0.0) : match ($prediction->decision) { 'BUY' => 1, 'SELL' => -1, default => 0 };
                    $prediction->forceFill([
                        'status' => 'SCORED',
                        'actual_price' => (float) $candle->close,
                        'actual_return' => $actualReturn,
                        // A flat outcome or no predicted direction has nothing to hit.
                        'direction_hit' => $actualReturn == 0.0 || $direction === 0 ? null : (($actualReturn > 0) === ($direction > 0)),
                        'abs_error_pct' => $hasReturn ? abs($predicted - $actualReturn) * 100 : null,
                        'decision_net_pct' => $net,
                        'funding_actual_buy_pct' => $fundingBuy,
                        'scored_at' => now('UTC'),
                    ])->save();
                    $totals['scored']++;
                }
            });

        return $totals;
    }

    /** @return array<string, mixed> */
    public function metrics(string $symbol, int $horizon, ?string $modelVersion = null): array
    {
        $minimum = max(1, (int) config('ml.evaluation.min_samples', 50));
        $scored = MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->where('status', 'SCORED')->when($modelVersion, fn ($q) => $q->where('model_version', $modelVersion));

        $forecastCount = (clone $scored)->count();
        $directional = (clone $scored)->whereNotNull('direction_hit');
        $directionalCount = (clone $directional)->count();
        $hits = (clone $directional)->where('direction_hit', true)->count();
        $mae = (clone $scored)->avg('abs_error_pct');

        $decisions = (clone $scored)->whereIn('decision', ['BUY', 'SELL']);
        $decisionCount = (clone $decisions)->count();
        $wins = (clone $decisions)->where('decision_net_pct', '>', 0)->count();
        $avgNet = (clone $decisions)->avg('decision_net_pct');

        $forecastReady = $forecastCount >= $minimum;
        $decisionReady = $decisionCount >= $minimum;

        return [
            'minimum_samples' => $minimum,
            'pending' => MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'PENDING')->count(),
            'forecast' => [
                'status' => $forecastReady ? 'ok' : 'insufficient_data',
                'samples' => $forecastCount,
                'directional_samples' => $directionalCount,
                'direction_hit_rate_pct' => $forecastReady && $directionalCount > 0 ? round($hits / $directionalCount * 100, 2) : null,
                'mae_pct' => $forecastReady && $mae !== null ? round((float) $mae, 6) : null,
            ],
            'decision' => [
                'status' => $decisionReady ? 'ok' : 'insufficient_data',
                'samples' => $decisionCount,
                'wait_samples' => (clone $scored)->where('decision', 'WAIT')->count(),
                'net_win_rate_pct' => $decisionReady ? round($wins / $decisionCount * 100, 2) : null,
                'average_net_pct' => $decisionReady && $avgNet !== null ? round((float) $avgNet, 6) : null,
            ],
        ];
    }
}
