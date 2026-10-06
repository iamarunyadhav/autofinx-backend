<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resumes the checkpointed candle backfill for ONE symbol on the ml_training
 * queue by running the existing `ml:candles:backfill --no-build` command (same
 * lock, same page budget, same checkpoint), so it never forks the backfill
 * logic. One per symbol (unique). Feature/label rebuilding stays with the
 * daily retrain check. Shadow-only: it only writes ml_candles.
 */
final class ResumeMlBackfillJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** One invocation is bounded by ml.backfill.max_pages_per_run; stays under the queue retry_after (2400 s). */
    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $symbol)
    {
        $this->onConnection((string) config('ml.training.connection', 'ml_training'));
        $this->onQueue((string) config('ml.training.queue', 'ml_training'));
    }

    public function uniqueId(): string
    {
        return 'ml:backfill:resume:'.$this->symbol;
    }

    public function handle(): void
    {
        if (! (bool) config('ml.enabled', false)) {
            return;
        }
        $exit = Artisan::call('ml:candles:backfill', ['--symbol' => [$this->symbol], '--no-build' => true]);
        $output = trim(Artisan::output());
        Log::info('ml.shadow.backfill_resume', ['symbol' => $this->symbol, 'exit' => $exit, 'output' => mb_substr($output, -1500)]);
        if ($exit !== 0) {
            $this->fail(new RuntimeException("Backfill for {$this->symbol} stopped (checkpoint kept): ".mb_substr($output, -300)));
        }
    }
}
