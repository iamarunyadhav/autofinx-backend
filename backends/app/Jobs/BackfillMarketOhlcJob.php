<?php

namespace App\Jobs;

use App\Domain\Market\MarketOhlcBackfillService;
use App\Models\RatioBotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort, cheap, idempotent: a no-op if the config already has enough
 * candle history for its timeframe. Never blocks or gates trading — a
 * starved bot still fails open exactly as before this job existed, just
 * less often once history has been seeded.
 */
class BackfillMarketOhlcJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;
    public int $uniqueFor = 300;

    public function __construct(public readonly string $ratioBotConfigId)
    {
        $this->onQueue('ratio_bot');
    }

    public function uniqueId(): string
    {
        return 'ratio-bot-ohlc-backfill:' . $this->ratioBotConfigId;
    }

    public function handle(MarketOhlcBackfillService $backfill): void
    {
        $config = RatioBotConfig::find($this->ratioBotConfigId);
        if (! $config || ! $backfill->needsBackfillForConfig($config)) {
            return;
        }

        try {
            $written = $backfill->backfillForConfig($config);
            Log::info('[MarketOhlcBackfill] Backfilled candles', [
                'config_id' => $config->id,
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'rows_written' => $written,
            ]);
        } catch (Throwable $e) {
            Log::warning('[MarketOhlcBackfill] Backfill failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
