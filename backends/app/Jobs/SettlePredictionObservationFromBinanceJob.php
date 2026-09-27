<?php

namespace App\Jobs;

use App\Domain\Prediction\BinanceClosedExpiryPriceService;
use App\Domain\Prediction\PredictionObservationSettlementService;
use App\Models\Integration;
use App\Models\PredictionObservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SettlePredictionObservationFromBinanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $observationId) { $this->onQueue('analysis'); }

    public function handle(BinanceClosedExpiryPriceService $prices, PredictionObservationSettlementService $settlement): void
    {
        $observation = PredictionObservation::query()->find($this->observationId);
        if ($observation === null || $observation->status !== 'PENDING' || ! $observation->integration_id) return;
        $integration = Integration::query()->whereKey($observation->integration_id)->first();
        if ($integration === null || $integration->provider_key !== 'binance') return;
        $settlement->settle($observation, $prices->resolve($observation, $integration));
    }
}
