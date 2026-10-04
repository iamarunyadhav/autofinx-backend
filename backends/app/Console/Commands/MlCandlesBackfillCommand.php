<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Domain\Ml\Shadow\MlHistoryWindow;
use App\Models\MlBackfillCheckpoint;
use App\Models\MlCandle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class MlCandlesBackfillCommand extends MlShadowCommand
{
    protected $signature = 'ml:candles:backfill
        {--symbol=* : Symbol(s); defaults to ml.symbols}
        {--months= : Months of history (default: per symbol, ml.history)}
        {--from= : Start (UTC date/time), overrides --months}
        {--to= : End (UTC date/time), default now}
        {--timeframe= : Candle interval (default ml.base_timeframe)}
        {--max-pages= : Page budget for this run; re-run to resume from the checkpoint}
        {--no-build : Skip the feature/label rebuild after the backfill}
        {--restart : Re-scan the range from its start (fills fields older rows lack, e.g. taker volume)}';

    protected $description = 'Paginated, checkpointed, resumable re-download of CLOSED Binance Futures klines into ml_candles (shadow ML only).';

    public function handle(MlCandleCollector $collector, FeatureLabelService $features): int
    {
        $timeframe = (string) ($this->option('timeframe') ?: config('ml.base_timeframe', '5m'));
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to'), 'UTC') : now('UTC');
        $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;
        $failed = false;

        foreach ($this->symbols() as $symbol) {
            // Separate lock from ml:candles:sync — live collection keeps running.
            $lock = Cache::lock('ml:shadow:backfill:'.$symbol.':'.$timeframe, 6 * 3600);
            if (! $lock->get()) {
                $this->warn("{$symbol}: another backfill is running; skipped.");

                continue;
            }
            // Day-aligned start so a re-run on the same day resumes the same checkpoint.
            $from = match (true) {
                (bool) $this->option('from') => Carbon::parse((string) $this->option('from'), 'UTC'),
                (bool) $this->option('months') => now('UTC')->subMonthsNoOverflow((int) $this->option('months'))->startOfDay(),
                default => Carbon::createFromTimestampMs(MlHistoryWindow::startMs($symbol), 'UTC'),
            };
            try {
                $this->info("{$symbol} {$timeframe}: backfilling {$from->toIso8601String()} -> {$to->toIso8601String()}");
                $checkpoint = $collector->backfill($symbol, $timeframe, $from->getTimestampMs(), $to->getTimestampMs(), $maxPages,
                    function (MlBackfillCheckpoint $cp) use ($symbol): void {
                        if ($cp->pages % 10 === 0 || $cp->status !== 'RUNNING') {
                            $this->line(sprintf('  %s page %d cursor %s written %d rejected %d [%s]', $symbol, $cp->pages,
                                gmdate('Y-m-d H:i', intdiv((int) $cp->cursor_ms, 1000)), $cp->rows_written, $cp->rows_rejected, $cp->status));
                        }
                    }, (bool) $this->option('restart'));
                $this->info(sprintf('%s: %s - pages %d, written %d, rejected %d, cursor %s', $symbol, $checkpoint->status,
                    $checkpoint->pages, $checkpoint->rows_written, $checkpoint->rows_rejected, gmdate('c', intdiv((int) $checkpoint->cursor_ms, 1000))));
                if ($checkpoint->status === 'RUNNING') {
                    $this->warn("{$symbol}: page budget reached; re-run the same command to resume from the checkpoint.");
                }
                $missingTaker = MlCandle::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->whereNull('taker_buy_volume')->count();
                $this->line("{$symbol}: candles still without taker-buy volume: {$missingTaker}");
                if (! $this->option('no-build') && $timeframe === config('ml.base_timeframe', '5m')) {
                    $built = $features->build($symbol, full: true);
                    $matured = $features->mature($symbol);
                    $this->info(sprintf('%s: feature rows +%d, labels +%d, matured %s', $symbol, $built['feature_rows'], $built['labels'], json_encode($matured)));
                }
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$symbol}: backfill stopped - {$exception->getMessage()} (checkpoint kept; re-run to resume)");
            } finally {
                $lock->release();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
