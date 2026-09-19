<?php

namespace App\Jobs;

use App\Domain\Market\MarketOhlcBackfillService;
use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\BacktestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Backfill + walk-forward evaluation; never called from a live request. */
class RunQuickTradeHistoricalBacktestJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Deliberately NOT redeclaring a $queue property here -- see
     * EvaluateQuickTradeForecastOutcomeJob's identical fix for the full
     * explanation (a class-level override of a trait property with a
     * different default value is an incompatible composition in PHP,
     * regardless of type). The real dispatch site already calls
     * ->onQueue('analytics') explicitly (QuickTradeResearchController),
     * which is what actually sets the queue at runtime.
     */
    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;

    /**
     * Staged backoff rather than Laravel's default instant retry -- this
     * job's first step hits Binance's public REST klines endpoint
     * (MarketOhlcBackfillService::backfill()), which is rate-limited; an
     * immediate retry against a 429/5xx response just burns the next
     * attempt too. 30s covers a brief blip, 120s covers a short rate-limit
     * window, matching the two retries $tries=3 allows.
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly int $horizonSeconds,
        public readonly int $candleLimit = 500,
        public readonly ?string $runId = null,
        public readonly string $botType = 'quick_trade',
    ) {}

    public function uniqueId(): string
    {
        return implode(':', ['quick-trade-backtest', $this->exchange, strtoupper($this->symbol), $this->timeframe, $this->horizonSeconds]);
    }

    public function handle(MarketOhlcBackfillService $backfill, ForecastHorizonBacktestService $service): void
    {
        $symbol = strtoupper($this->symbol);
        $futures = $this->exchange === 'binance_futures';
        $run = $this->runId ? BacktestRun::find($this->runId) : null;
        try {
            $run?->update(['status' => 'running', 'started_at' => now()]);
            $written = $backfill->backfill($this->exchange, $symbol, $this->timeframe, $this->candleLimit, $futures);
            $result = $service->runForBot($this->botType, $this->exchange . ':' . $symbol . ':' . $this->timeframe,
                $this->exchange, $symbol, $this->timeframe, $this->horizonSeconds);
            $run?->update(['status' => 'completed', 'candles_written' => $written,
                'result_rows' => count($result['buckets'] ?? []), 'finished_at' => now()]);
        } catch (Throwable $e) {
            $run?->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            Log::warning('[QuickTradeBacktest] async run failed', [
                'exchange' => $this->exchange, 'symbol' => $symbol,
                'timeframe' => $this->timeframe, 'horizon_seconds' => $this->horizonSeconds,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
