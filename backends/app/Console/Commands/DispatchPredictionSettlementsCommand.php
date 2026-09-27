<?php

namespace App\Console\Commands;

use App\Jobs\SettlePredictionObservationFromBinanceJob;
use App\Models\PredictionObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class DispatchPredictionSettlementsCommand extends Command
{
    protected $signature = 'prediction:dispatch-settlements {--limit=100 : Maximum observations to dispatch}';
    protected $description = 'Dispatch due prediction observations for Binance closed-candle settlement.';

    public function handle(): int
    {
        $rows = PredictionObservation::query()->where('status', 'PENDING')->whereNotNull('integration_id')
            ->whereRaw('DATE_ADD(predicted_at, INTERVAL horizon_seconds SECOND) <= ?', [now()])
            ->orderBy('predicted_at')->limit((int) $this->option('limit'))->get();
        $dispatched = 0;
        foreach ($rows as $row) {
            $lock = Cache::lock('prediction-settlement-dispatch:'.$row->id, 120);
            if (! $lock->get()) continue;
            SettlePredictionObservationFromBinanceJob::dispatch((int) $row->id);
            $lock->release();
            $dispatched++;
        }
        $this->info("Dispatched {$dispatched} prediction settlement job(s).");
        return self::SUCCESS;
    }
}
