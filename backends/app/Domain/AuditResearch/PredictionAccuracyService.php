<?php

namespace App\Domain\AuditResearch;

use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;

/**
 * Thin shaping layer over the EXISTING RatioBotForecastBacktest rows
 * (hit_rate_pct, sample_size, horizon_seconds, computed_at), already
 * computed by ForecastHorizonBacktestService on a schedule for every
 * brain_enabled bot. Does NOT recompute forecast correctness — just shapes
 * what's already there for the Audit Research reporting layer.
 */
class PredictionAccuracyService
{
    /**
     * @return array<int, array{horizon_seconds:int, hit_rate_pct: ?float, sample_size:int, avg_confidence_pct: ?float, computed_at: ?string}>
     */
    public function forConfig(RatioBotConfig $config): array
    {
        return RatioBotForecastBacktest::query()
            ->where('ratio_bot_config_id', $config->id)
            ->orderBy('horizon_seconds')
            ->get()
            ->map(fn (RatioBotForecastBacktest $b) => [
                'horizon_seconds' => (int) $b->horizon_seconds,
                'hit_rate_pct' => $b->hit_rate_pct !== null ? (float) $b->hit_rate_pct : null,
                'sample_size' => (int) $b->sample_size,
                'avg_confidence_pct' => $b->avg_confidence_pct !== null ? (float) $b->avg_confidence_pct : null,
                'computed_at' => optional($b->computed_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cross-bot view for a workspace — grouped by bot for the
     * prediction-accuracy endpoint.
     */
    public function forWorkspace(string $workspaceId): array
    {
        $configs = RatioBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'name', 'symbol']);

        return $configs->map(fn (RatioBotConfig $config) => [
            'ratio_bot_config_id' => $config->id,
            'name' => $config->name,
            'symbol' => $config->symbol,
            'horizons' => $this->forConfig($config),
        ])->values()->all();
    }
}
