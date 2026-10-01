<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class MlCandlesSyncCommand extends MlShadowCommand
{
    protected $signature = 'ml:candles:sync {--symbol=*} {--force : Run even when ml.enabled is false}';

    protected $description = 'Live collection of newly CLOSED 5m klines, then incremental features + pending labels (shadow ML only).';

    public function handle(MlCandleCollector $collector, FeatureLabelService $features): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $failed = false;
        foreach ($this->symbols() as $symbol) {
            $lock = Cache::lock('ml:shadow:sync:'.$symbol, 240);
            if (! $lock->get()) {
                continue;
            }
            try {
                $synced = $collector->sync($symbol, $timeframe);
                $built = $features->build($symbol);
                $this->line(sprintf('%s: candles +%d (unchanged %d, rejected %d, forming excluded %d, gaps retried %d); features +%d; labels +%d',
                    $symbol, $synced['written'], $synced['unchanged'], $synced['rejected'], $synced['forming_excluded'], $synced['gaps_retried'],
                    $built['feature_rows'], $built['labels']));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$symbol}: sync failed - {$exception->getMessage()}");
            } finally {
                $lock->release();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
