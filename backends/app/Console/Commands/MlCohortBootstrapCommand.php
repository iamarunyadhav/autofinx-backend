<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Domain\Ml\Shadow\MlHistoryWindow;
use App\Models\MlCandle;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Incrementally downloads history for the least-complete configured V5/ML
 * research symbol. It is checkpointed by MlCandleCollector and intentionally
 * processes one bounded unit per run, keeping shared hosting responsive.
 */
final class MlCohortBootstrapCommand extends MlShadowCommand
{
    protected $signature = 'ml:cohort:bootstrap {--force : Run even when ML is disabled}';

    protected $description = 'Bounded, resumable V5/ML research cohort candle bootstrap (shadow-only).';

    public function handle(MlCandleCollector $collector, FeatureLabelService $features): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        if (! (bool) config('ml.cohort_bootstrap.enabled', false) && ! $this->option('force')) {
            $this->line('Cohort bootstrap is disabled (AUTOFINX_ML_COHORT_BOOTSTRAP_ENABLED=false); skipped.');

            return self::SUCCESS;
        }

        $timeframe = (string) config('ml.base_timeframe', '5m');
        $symbol = $this->leastCompleteSymbol($timeframe);
        if ($symbol === null) {
            $this->line('No configured ML research symbols; skipped.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('ml:shadow:cohort-bootstrap', 14 * 60);
        if (! $lock->get()) {
            $this->line('Cohort bootstrap is already running; skipped.');

            return self::SUCCESS;
        }

        try {
            $checkpoint = $collector->backfill(
                $symbol,
                $timeframe,
                MlHistoryWindow::startMs($symbol),
                now('UTC')->getTimestampMs(),
                (int) config('ml.cohort_bootstrap.pages_per_run', 5),
            );
            $built = $features->build($symbol, full: true);
            $matured = $features->mature($symbol);
            $this->info(sprintf(
                '%s: %s pages=%d written=%d; features=%d labels=%d matured=%s',
                $symbol,
                $checkpoint->status,
                $checkpoint->pages,
                $checkpoint->rows_written,
                $built['feature_rows'],
                $built['labels'],
                json_encode($matured),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error("{$symbol}: bootstrap paused; checkpoint kept for resume ({$exception->getMessage()})");

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function leastCompleteSymbol(string $timeframe): ?string
    {
        $symbols = $this->symbols();
        if ($symbols === []) {
            return null;
        }
        $counts = MlCandle::query()
            ->where('provider', (string) config('ml.provider', 'binance_futures'))
            ->where('timeframe', $timeframe)
            ->whereIn('symbol', $symbols)
            ->selectRaw('symbol, COUNT(*) AS aggregate_count')
            ->groupBy('symbol')
            ->pluck('aggregate_count', 'symbol');

        usort($symbols, static fn (string $left, string $right): int => ((int) ($counts[$left] ?? 0) <=> (int) ($counts[$right] ?? 0)) ?: strcmp($left, $right));

        return $symbols[0] ?? null;
    }
}
